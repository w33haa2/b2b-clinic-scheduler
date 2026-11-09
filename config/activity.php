<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    |
    | Every change made in the app is recorded in activity_logs (see
    | docs/activity-log.md). Rows older than this many days are deleted by the
    | daily `model:prune` run in routes/console.php.
    |
    */

    'retention_days' => (int) env('ACTIVITY_RETENTION_DAYS', 365),

];
