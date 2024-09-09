<?php

namespace App\Jobs\MovePipeline;

use App\Services\ActivityPubFetchService;
use App\Util\ActivityPub\Helpers;
use DateTime;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;
use Log;

class ProcessMovePipeline implements ShouldQueue
{
    use Queueable;

    public $target;

    public $activity;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 15;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 5;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

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
            new WithoutOverlapping('process-move:'.$this->target),
            (new ThrottlesExceptionsWithRedis(5, 2 * 60))->backoff(1),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTime
    {
        return now()->addMinutes(10);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('app.env') !== 'production' || (bool) config_cache('federation.activitypub.enabled') == false) {
            Log::info('pmp: AP not enabled');
            throw new Exception('Activitypub not enabled');
        }

        $validTarget = $this->checkTarget();
        if (! $validTarget) {
            Log::info('pmp: invalid target');
            throw new Exception('Invalid target');
        }

        $validActor = $this->checkActor();
        if (! $validActor) {
            Log::info('pmp: invalid actor');
            throw new Exception('Invalid actor');
        }

    }

    protected function checkTarget()
    {
        $res = ActivityPubFetchService::fetchRequest($this->target, true);

        if (! $res || ! isset($res['alsoKnownAs'])) {
            Log::info('[AP][INBOX][MOVE] target_aka failure');

            return false;
        }

        $targetRes = Helpers::profileFetch($this->target);
        if (! $targetRes) {
            Log::info('[AP][INBOX][MOVE] target fetch failure');

            return false;
        }

        if (is_string($res['alsoKnownAs'])) {
            return $this->lowerTrim($res['alsoKnownAs']) === $this->lowerTrim($this->activity);
        }

        if (is_array($res['alsoKnownAs'])) {
            $map = Arr::map($res['alsoKnownAs'], function ($value, $key) {
                return trim(strtolower($value));
            });

            $res = in_array($this->activity, $map);
            $debugMessage = $res ? '[AP][INBOX][MOVE] aka target is valid' : '[AP][INBOX][MOVE] aka target is invalid';

            Log::info($debugMessage);

            return $res;
        }

        return false;
    }

    protected function checkActor()
    {
        $res = ActivityPubFetchService::fetchRequest($this->activity, true);

        if (! $res || ! isset($res['movedTo']) || empty($res['movedTo'])) {
            Log::info('[AP][INBOX][MOVE] actor_movedTo failure');
            $payload = json_encode($res, JSON_PRETTY_PRINT);
            Log::info($payload);

            return false;
        }

        $actorRes = Helpers::profileFetch($this->activity);
        if (! $actorRes) {
            Log::info('[AP][INBOX][MOVE] actor fetch failure');

            return false;
        }

        if (is_string($res['movedTo'])) {
            $match = $this->lowerTrim($res['movedTo']) === $this->lowerTrim($this->target);
            if (! $match) {
                $msg = json_encode([
                    'movedTo' => $res['movedTo'],
                    'target' => $this->target,
                ]);
                Log::info('[AP][INBOX][MOVE] invalid actor match.'.$msg);

                return false;
            }

            return $match;
        }

        return false;
    }

    protected function lowerTrim($str)
    {
        return trim(strtolower($str));
    }
}
