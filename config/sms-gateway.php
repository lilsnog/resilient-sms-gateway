<?php

return [

    /*
    | Providers, keyed by the name used in routes. Each entry is passed to
    | SmsGateway\Providers\HttpJsonProvider. The vendors below are placeholders;
    | map the field names to your aggregator's API.
    */
    'providers' => [
        'primary' => [
            'url' => env('SMS_PRIMARY_URL', 'https://api.primary-sms.example/v1/send'),
            'headers' => ['Authorization' => 'Bearer ' . env('SMS_PRIMARY_KEY', '')],
            'fields' => ['to' => 'to', 'body' => 'sms', 'sender' => 'from'],
            'id_path' => 'message_id',
            'timeout' => 5,
        ],
        'backup' => [
            'url' => env('SMS_BACKUP_URL', 'https://api.backup-sms.example/messages'),
            'headers' => ['X-Api-Key' => env('SMS_BACKUP_KEY', '')],
            'fields' => ['to' => 'recipient', 'body' => 'text', 'sender' => 'sender'],
            'id_path' => 'data.id',
            'timeout' => 5,
        ],
    ],

    /*
    | Route used when no prefix rule matches the recipient.
    */
    'default_route' => ['primary', 'backup'],

    /*
    | Prefix rules, longest match wins. Example: send Nigerian numbers through
    | the backup first.
    */
    'routes' => [
        // '+234' => ['backup', 'primary'],
    ],

    'circuit' => [
        'failure_threshold' => 5,   // failures inside the window that open the circuit
        'window_seconds' => 60,
        'cool_down_seconds' => 30,  // how long a provider is skipped once open
        'state_ttl' => 3600,
    ],

    'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),
];
