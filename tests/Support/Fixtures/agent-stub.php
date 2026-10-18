<?php

/*
 * Stands in for agent/build/agent.phar in the vigil:agent command test: records the variables the command
 * prepared in the including scope, exactly what the real PHAR reads.
 */

$GLOBALS['vigilAgentStubVariables'] = get_defined_vars();

$exitCode = 7;
