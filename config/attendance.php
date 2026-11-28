<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Late cut-off
    |--------------------------------------------------------------------------
    |
    | Manila wall-clock `H:MM` or `HH:MM`. A scan is late only strictly after
    | this time, so a scan at exactly 07:30:00 is still present.
    |
    */

    'late_after' => env('ATTENDANCE_LATE_AFTER', '07:30'),

    /*
    |--------------------------------------------------------------------------
    | Debounce window
    |--------------------------------------------------------------------------
    |
    | A repeat tap within this many seconds of the last recorded scan is ignored,
    | so a double tap does not set a time out.
    |
    */

    'debounce_seconds' => (int) env('ATTENDANCE_DEBOUNCE_SECONDS', 60),

];
