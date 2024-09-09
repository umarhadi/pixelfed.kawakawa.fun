<?php

namespace App\Jobs\MovePipeline;

use App\Follower;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\HttpSignature;
use DB;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use DateTime;

class MoveMigrateFollowersPipeline implements ShouldQueue
{
    use Queueable;

    public $target;

    public $activity;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 6;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct($target, $activity)
    {
        $this->target = $target;
        $this->activity = $activity;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('process-move-migrate-followers:'.$this->target),
            (new ThrottlesExceptions(2, 5 * 60))->backoff(5),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTime
    {
        return now()->addMinutes(5);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('app.env') !== 'production' || (bool) config_cache('federation.activitypub.enabled') == false) {
            throw new Exception('Activitypub not enabled');
        }

        $target = $this->target;
        $actor = $this->activity;

        $targetAccount = Helpers::profileFetch($target);
        $actorAccount = Helpers::profileFetch($actor);

        if (! $targetAccount || ! $actorAccount) {
            throw new Exception('Invalid move accounts');
        }

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Follow',
            'actor' => null,
            'object' => $target,
        ];

        $version = config('pixelfed.version');
        $appUrl = config('app.url');
        $userAgent = "(Pixelfed/{$version}; +{$appUrl})";
        $addlHeaders = [
            'Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            'User-Agent' => $userAgent,
        ];
        $targetInbox = $targetAccount['sharedInbox'] ?? $targetAccount['inbox_url'];
        $targetPid = $targetAccount['id'];

        DB::table('followers')
            ->join('profiles', 'followers.profile_id', '=', 'profiles.id')
            ->where('followers.following_id', $actorAccount['id'])
            ->whereNotNull('profiles.user_id')
            ->whereNull('profiles.deleted_at')
            ->select('profiles.id', 'profiles.user_id', 'profiles.username', 'profiles.private_key')
            ->chunkById(100, function ($followers) use ($activity, $addlHeaders, $targetInbox, $targetPid) {
                $client = new Client([
                    'timeout' => config('federation.activitypub.delivery.timeout'),
                ]);
                $requests = function ($followers) use ($client, $activity, $addlHeaders, $targetInbox, $targetPid) {
                    foreach ($followers as $follower) {
                        $permalink = 'https://'.config('pixelfed.domain.app').'/users/'.$follower->username;
                        $activity['actor'] = $permalink;
                        $keyId = $permalink.'#main-key';
                        $payload = json_encode($activity);
                        $url = $targetInbox;
                        $headers = HttpSignature::signRaw($follower->private_key, $keyId, $targetInbox, $activity, $addlHeaders);
                        Follower::updateOrCreate([
                            'profile_id' => $follower->id,
                            'following_id' => $targetPid,
                        ]);
                        yield new $client->postAsync($url, [
                            'curl' => [
                                CURLOPT_HTTPHEADER => $headers,
                                CURLOPT_POSTFIELDS => $payload,
                                CURLOPT_HEADER => true,
                            ],
                        ]);
                    }
                };

                $pool = new Pool($client, $requests($followers), [
                    'concurrency' => config('federation.activitypub.delivery.concurrency'),
                    'fulfilled' => function ($response, $index) {},
                    'rejected' => function ($reason, $index) {},
                ]);

                $promise = $pool->promise();

                $promise->wait();
            }, 'id');
    }
}
