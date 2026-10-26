<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company Timezone
    |--------------------------------------------------------------------------
    |
    | Times are stored in UTC. This timezone is used to display times and to
    | interpret every time a user enters, including in the chat assistant.
    |
    */

    'timezone' => 'America/Chicago',

    /*
    |--------------------------------------------------------------------------
    | Booking Rules
    |--------------------------------------------------------------------------
    |
    | Starts and ends must fall on slot boundaries. A booking may be at most
    | the max duration long, and may start up to the grace period in the past.
    |
    */

    'slot_minutes' => 30,

    'max_duration_hours' => 96,

    'past_start_grace_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Chat Assistant Limits
    |--------------------------------------------------------------------------
    */

    'chat' => [
        'rate_limit_per_minute' => (int) env('CHAT_RATE_LIMIT', 20),
        'max_steps' => (int) env('CHAT_MAX_STEPS', 8),
        'proposal_expiry_minutes' => (int) env('CHAT_PROPOSAL_EXPIRY', 15),
        'retention_days' => 30,
    ],

];
