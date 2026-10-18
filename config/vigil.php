<?php

return [
    'enabled' => env('VIGIL_ENABLED', true),
    'token' => env('VIGIL_TOKEN'),
    'environment' => env('VIGIL_ENVIRONMENT', env('APP_ENV')),
    'deployment' => env('VIGIL_DEPLOY', env('LARAVEL_CLOUD_DEPLOY_UUID', env('FORGE_DEPLOY_COMMIT', env('VAPOR_COMMIT_HASH')))),
    'server' => env('VIGIL_SERVER', (string) gethostname()),
    'base_url' => env('VIGIL_BASE_URL'),
    'capture_exception_source_code' => env('VIGIL_CAPTURE_EXCEPTION_SOURCE_CODE', true),
    'capture_request_payload' => env('VIGIL_CAPTURE_REQUEST_PAYLOAD', false),
    'redact_payload_fields' => explode(',', env('VIGIL_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation')),
    'redact_headers' => explode(',', env('VIGIL_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN')),

    'sampling' => [
        'requests' => env('VIGIL_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => env('VIGIL_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('VIGIL_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('VIGIL_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    'filtering' => [
        'ignore_cache_events' => env('VIGIL_IGNORE_CACHE_EVENTS', false),
        'ignore_mail' => env('VIGIL_IGNORE_MAIL', false),
        'ignore_notifications' => env('VIGIL_IGNORE_NOTIFICATIONS', false),
        'ignore_outgoing_requests' => env('VIGIL_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_queries' => env('VIGIL_IGNORE_QUERIES', false),
        'log_level' => env('VIGIL_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
    ],

    'ingest' => [
        'uri' => env('VIGIL_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('VIGIL_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('VIGIL_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('VIGIL_INGEST_EVENT_BUFFER', 500),
    ],

    'agent' => [
        'spool_path' => env('VIGIL_AGENT_SPOOL_PATH', storage_path('app/vigil/spool')),
        'spool_segment_bytes' => env('VIGIL_AGENT_SPOOL_SEGMENT_BYTES', 4194304),
        'spool_segment_seconds' => env('VIGIL_AGENT_SPOOL_SEGMENT_SECONDS', 5),
        'spool_max_bytes' => env('VIGIL_AGENT_SPOOL_MAX_BYTES', 1073741824),
        'spool_fsync' => env('VIGIL_AGENT_SPOOL_FSYNC', false),
        'upload_bytes' => env('VIGIL_AGENT_UPLOAD_BYTES', 8388608),
        'concurrency' => env('VIGIL_AGENT_CONCURRENCY', 2),
        'auth_connection_timeout' => env('VIGIL_AGENT_AUTH_CONNECTION_TIMEOUT', 5),
        'auth_timeout' => env('VIGIL_AGENT_AUTH_TIMEOUT', 10),
        'ingest_connection_timeout' => env('VIGIL_AGENT_INGEST_CONNECTION_TIMEOUT', 5),
        'ingest_timeout' => env('VIGIL_AGENT_INGEST_TIMEOUT', 30),
        'drain_seconds' => env('VIGIL_AGENT_DRAIN_SECONDS', 10),
    ],
];
