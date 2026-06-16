<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared web-application Linux user
    |--------------------------------------------------------------------------
    |
    | Every managed web application runs under a single shared OS user
    | (RunCloud-style). Each app lives in /home/{user}/webapps/{app_slug} and
    | gets its own php-fpm pool + socket keyed by app_slug, so apps stay
    | isolated at the pool level while sharing the OS account.
    |
    */

    'webapp_user' => env('VELINK_WEBAPP_USER', 'velink'),

];
