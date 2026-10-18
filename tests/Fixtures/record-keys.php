<?php

/**
 * Ordered record keys per record type, mirrored from the Vigil monitoring product catalogue
 * (watch_materom_ro: app/Watch/RecordDefinitions.php, docs/RECORDS.md).
 *
 * @return array<string, list<string>>
 */
return [
    'request' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'user', 'method', 'url', 'route_name', 'route_methods', 'route_domain', 'route_path', 'route_action', 'ip', 'duration', 'status_code', 'request_size', 'response_size', 'bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context', 'headers', 'payload'],
    'query' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'sql', 'file', 'line', 'duration', 'connection', 'connection_type'],
    'exception' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'class', 'file', 'line', 'message', 'code', 'trace', 'handled', 'php_version', 'laravel_version'],
    'log' => ['v', 't', 'timestamp', 'deploy', 'server', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'level', 'message', 'context', 'extra'],
    'job-attempt' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'user', 'job_id', 'attempt_id', 'attempt', 'name', 'connection', 'queue', 'status', 'duration', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context'],
    'queued-job' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'job_id', 'name', 'connection', 'queue', 'duration'],
    'command' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'class', 'name', 'command', 'exit_code', 'duration', 'bootstrap', 'action', 'terminating', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context'],
    'scheduled-task' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'name', 'cron', 'timezone', 'repeat_seconds', 'without_overlapping', 'on_one_server', 'run_in_background', 'even_in_maintenance_mode', 'status', 'duration', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context'],
    'cache-event' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'store', 'key', 'type', 'duration', 'ttl'],
    'mail' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'mailer', 'class', 'subject', 'to', 'cc', 'bcc', 'attachments', 'duration', 'failed'],
    'notification' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'channel', 'class', 'duration', 'failed'],
    'outgoing-request' => ['v', 't', 'timestamp', 'deploy', 'server', '_group', 'trace_id', 'execution_source', 'execution_id', 'execution_preview', 'execution_stage', 'user', 'host', 'method', 'url', 'duration', 'request_size', 'response_size', 'status_code'],
    'user' => ['v', 't', 'timestamp', 'id', 'name', 'username'],
];
