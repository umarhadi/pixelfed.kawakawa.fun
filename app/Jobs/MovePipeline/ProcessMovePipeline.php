<?php

namespace App\Jobs\MovePipeline;

use App\Services\ActivityPubFetchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessMovePipeline implements ShouldQueue
{
    use Queueable;

    public $target;

    public $activity;

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
        return [new WithoutOverlapping($this->target)];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! self::checkTarget()) {
            return;
        }

        if (! self::checkActor()) {
            return;
        }
    }

    protected function checkTarget()
    {
        $res = ActivityPubFetchService::fetchRequest($this->target, true);

        if (! $res || ! isset($res['alsoKnownAs'])) {
            return false;
        }

        $res = Helpers::profileFetch($this->target);
        if (! $res) {
            return false;
        }

        if (is_string($res['alsoKnownAs'])) {
            return self::lowerTrim($res['alsoKnownAs']) === self::lowerTrim($this->actor);
        }

        if (is_array($res['alsoKnownAs'])) {
            $map = array_map(self::lowerTrim(), $res['alsoKnownAs']);

            return in_array($this->actor, $map);
        }

        return false;
    }

    protected function checkActor()
    {
        $res = ActivityPubFetchService::fetchRequest($this->actor, true);

        if (! $res || ! isset($res['movedTo'])) {
            return false;
        }

        $res = Helpers::profileFetch($this->actor);
        if (! $res) {
            return false;
        }

        if (is_string($res['movedTo'])) {
            return self::lowerTrim($res['movedTo']) === self::lowerTrim($this->target);
        }

        return false;
    }

    protected function lowerTrim($str)
    {
        return trim(strtolower($str));
    }
}
