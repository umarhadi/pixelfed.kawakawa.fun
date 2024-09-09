<?php

namespace App\Jobs\MovePipeline;

use App\Follower;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\HttpSignature;
use DateTime;
use DB;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Psr7\Request;

class MoveMigrateFollowersPipeline implements ShouldQueue
{
    use Queueable;

    public string $target;
    public string $activity;

    public int $tries = 15;
    public int $maxExceptions = 5;
    public int $timeout = 900;

    public function __construct(string $target, string $activity)
    {
        $this->target = $target;
        $this->activity = $activity;
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping('process-move-migrate-followers:'.$this->target),
            (new ThrottlesExceptionsWithRedis(5, 2 * 60))->backoff(1),
        ];
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }

    public function handle(): void
    {
        try {
            $this->validateEnvironment();

            $targetAccount = $this->fetchProfile($this->target);
            $actorAccount = $this->fetchProfile($this->activity);

            if (!$targetAccount || !$actorAccount) {
                throw new Exception('Invalid move accounts');
            }

            $client = $this->createHttpClient();
            $targetInbox = $targetAccount['sharedInbox'] ?? $targetAccount['inbox_url'];
            $targetPid = $targetAccount['id'];

            DB::table('followers')
                ->join('profiles', 'followers.profile_id', '=', 'profiles.id')
                ->where('followers.following_id', $actorAccount['id'])
                ->whereNotNull('profiles.user_id')
                ->whereNull('profiles.deleted_at')
                ->select('profiles.id', 'profiles.user_id', 'profiles.username', 'profiles.private_key', 'profiles.status')
                ->chunkById(100, function ($followers) use ($client, $targetInbox, $targetPid) {
                    $this->processFollowerChunk($followers, $client, $targetInbox, $targetPid);
                }, 'id');
        } catch (Exception $e) {
            Log::error('MoveMigrateFollowersPipeline failed', [
                'target' => $this->target,
                'activity' => $this->activity,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function validateEnvironment(): void
    {
        if (config('app.env') !== 'production' || !(bool)config('federation.activitypub.enabled')) {
            throw new Exception('ActivityPub not enabled');
        }
    }

    private function fetchProfile(string $url): ?array
    {
        return Helpers::profileFetch($url);
    }

    private function createHttpClient(): Client
    {
        return new Client([
            'timeout' => config('federation.activitypub.delivery.timeout'),
        ]);
    }

    private function processFollowerChunk($followers, Client $client, string $targetInbox, int $targetPid): void
    {
        $requests = $this->generateRequests($followers, $targetInbox, $targetPid);

        $pool = new Pool($client, $requests, [
            'concurrency' => config('federation.activitypub.delivery.concurrency'),
            'fulfilled' => function ($response, $index) {
                // Log success if needed
            },
            'rejected' => function ($reason, $index) {
                Log::error('Failed to process follower', ['reason' => $reason, 'index' => $index]);
            },
        ]);

        $pool->promise()->wait();
    }

    private function generateRequests($followers, string $targetInbox, int $targetPid): \Generator
    {
        foreach ($followers as $follower) {
            if (!$this->isValidFollower($follower)) {
                continue;
            }

            yield $this->createFollowRequest($follower, $targetInbox, $targetPid);
        }
    }

    private function isValidFollower($follower): bool
    {
        return $follower->private_key && $follower->username && $follower->user_id && $follower->status !== 'delete';
    }

    private function createFollowRequest($follower, string $targetInbox, int $targetPid): \GuzzleHttp\Psr7\Request
    {
        $permalink = 'https://'.config('pixelfed.domain.app').'/users/'.$follower->username;
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Follow',
            'actor' => $permalink,
            'object' => $this->target,
        ];

        $keyId = $permalink.'#main-key';
        $payload = json_encode($activity);

        $version = config('pixelfed.version');
        $appUrl = config('app.url');
        $userAgent = "(Pixelfed/{$version}; +{$appUrl})";
        $addlHeaders = [
            'Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            'User-Agent' => $userAgent,
        ];

        $headers = HttpSignature::signRaw($follower->private_key, $keyId, $targetInbox, $activity, $addlHeaders);

        Follower::updateOrCreate([
            'profile_id' => $follower->id,
            'following_id' => $targetPid,
        ]);

        return new Request('POST', $targetInbox, $headers, $payload);
    }
}
