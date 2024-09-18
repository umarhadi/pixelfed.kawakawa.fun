<?php

namespace App\Services;

class NotificationAppGatewayService
{
    public static function config()
    {
        return config('instance.notifications.nag');
    }
}
