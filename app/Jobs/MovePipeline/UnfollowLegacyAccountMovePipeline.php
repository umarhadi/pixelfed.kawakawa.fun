<?php

namespace App\Jobs\MovePipeline;

use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\HttpSignature;
use DateTime;
use DB;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class UnfollowLegacyAccountMovePipeline implements ShouldQueue
{
    use Queueable;

    public string $target;

    public string $activity;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public function __construct(string $target, string $activity)
    {
        $this->target = $target;
        $this->activity = $activity;
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping('process-move-undo-legacy-followers:'.$this->target),
            (new ThrottlesExceptions(2, 5 * 60))->backoff(5),
        ];
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(5);
    }

    public function handle(): void
    {
        try {
            $this->validateEnvironment();

            $targetAccount = $this->fetchProfile($this->target);
            $actorAccount = $this->fetchProfile($this->activity);

            if (! $targetAccount || ! $actorAccount) {
                throw new Exception('Invalid move accounts');
            }

            $client = $this->createHttpClient();
            $targetInbox = $actorAccount['sharedInbox'] ?? $actorAccount['inbox_url'];
            $targetPid = $actorAccount['id'];

            $this->processFollowers($client, $targetInbox, $targetPid);
        } catch (Exception $e) {
            Log::error('UnfollowLegacyAccountMovePipeline failed', [
                'target' => $this->target,
                'activity' => $this->activity,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateEnvironment(): void
    {
        if (config('app.env') !== 'production' || ! (bool) config('federation.activitypub.enabled')) {
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

    private function processFollowers(Client $client, string $targetInbox, int $targetPid): void
    {
        DB::table('followers')
            ->join('profiles', 'followers.profile_id', '=', 'profiles.id')
            ->where('followers.following_id', $targetPid)
            ->whereNotNull('profiles.user_id')
            ->whereNull('profiles.deleted_at')
            ->select('profiles.id', 'profiles.user_id', 'profiles.username', 'profiles.private_key', 'profiles.status')
            ->chunkById(100, function ($followers) use ($client, $targetInbox, $targetPid) {
                $this->processFollowerChunk($followers, $client, $targetInbox, $targetPid);
            }, 'id');
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
                Log::error('Failed to process unfollow', ['reason' => $reason, 'index' => $index]);
            },
        ]);

        $pool->promise()->wait();
    }

    private function generateRequests($followers, string $targetInbox, int $targetPid): \Generator
    {
        foreach ($followers as $follower) {
            if (! $this->isValidFollower($follower)) {
                continue;
            }

            yield $this->createUnfollowRequest($follower, $targetInbox, $targetPid);
        }
    }

    private function isValidFollower($follower): bool
    {
        return $follower->private_key && $follower->username && $follower->user_id && $follower->status !== 'delete';
    }

    private function createUnfollowRequest($follower, string $targetInbox, int $targetPid): Request
    {
        $permalink = 'https://'.config('pixelfed.domain.app').'/users/'.$follower->username;
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Undo',
            'id' => $permalink.'#follow/'.$targetPid.'/undo',
            'actor' => $permalink,
            'object' => [
                'type' => 'Follow',
                'id' => $permalink.'#follows/'.$targetPid,
                'object' => $this->activity,
                'actor' => $permalink,
            ],
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

        return new Request('POST', $targetInbox, $headers, $payload);
    }
}
