<?php

declare(strict_types=1);

/*
 * PHP-Scoper configuration used by Box (agent/box.json) when building agent/build/agent.phar.
 *
 * Every vendored namespace (React, Evenement, Psr, Fig, ...) is prefixed with a random `VigilAgent_<random>`
 * namespace so the relay's dependencies never collide with the host application's own copies when the PHAR
 * is required from `php artisan vigil:agent`. Nothing from the host is exposed or shared. The relay's own
 * namespace stays readable (`Materom\VigilAgent`): it is unique to this PHAR and the test-suite loads the same
 * classes from source.
 *
 * Set VIGIL_AGENT_SCOPER_PREFIX to pin the prefix (reproducible builds).
 */

$prefix = getenv('VIGIL_AGENT_SCOPER_PREFIX') ?: 'VigilAgent_'.substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(16))), 0, 16);

return [
    'prefix' => $prefix,
    'exclude-namespaces' => ['Materom\VigilAgent'],
    'exclude-classes' => [],
    'exclude-functions' => [],
    'exclude-constants' => [],
    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
    'expose-namespaces' => [],
    'expose-classes' => [],
    'expose-functions' => [],
    'expose-constants' => [],
    'patchers' => [],
];
