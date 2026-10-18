<?php

// When the relay runs inside a Laravel application (`php artisan vigil:agent`), the application's Composer
// autoloader has already filled `$GLOBALS['__composer_autoload_files']` with the hashes of every "files"
// autoload entry it required. The PHAR is not an isolated environment and inherits that global, so the PHAR's
// own (scoped) autoloader would believe that shared dependencies' files were already required and skip them.
// Empty the list while the PHAR's autoloader runs, then restore the application's list.
$vigilAgentComposerAutoloadFiles = $GLOBALS['__composer_autoload_files'] ?? [];
$GLOBALS['__composer_autoload_files'] = [];

/** @var \Composer\Autoload\ClassLoader $vigilAgentAutoloader */
$vigilAgentAutoloader = require __DIR__.'/vendor/autoload.php';

$GLOBALS['__composer_autoload_files'] = $vigilAgentComposerAutoloadFiles;

// PHP loads classes lazily, reaching into the PHAR on disk each time a class is first used. A deploy replaces
// agent.phar while the old relay is still running; a class the old process loads afterwards would be read at
// the old offsets from the new file and fail with nonsensical syntax errors, typically while shutting down.
// Eagerly load every class in the class map now (class_exists lets Composer do the work and also loads
// interfaces and traits) so the running relay never touches the file again.
if (Phar::running(false) !== '') {
    foreach ($vigilAgentAutoloader->getClassMap() as $vigilAgentClass => $vigilAgentClassPath) {
        class_exists($vigilAgentClass);
    }
}

unset($vigilAgentComposerAutoloadFiles, $vigilAgentAutoloader, $vigilAgentClass, $vigilAgentClassPath);
