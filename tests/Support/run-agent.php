<?php

/*
 * Runs the built relay PHAR in a child process for the end-to-end tests, injecting variables exactly like
 * `php artisan vigil:agent` does: php run-agent.php <config.json>
 */

/** @var array<string, mixed> $vigilAgentConfig */
$vigilAgentConfig = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$vigilAgentPhar = $vigilAgentConfig['pharPath'];
unset($vigilAgentConfig['pharPath']);

extract($vigilAgentConfig);
unset($vigilAgentConfig);

$exitCode = 0;

require $vigilAgentPhar;

exit((int) $exitCode);
