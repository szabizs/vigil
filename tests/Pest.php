<?php

use Materom\Vigil\Tests\RequestTestCase;
use Materom\Vigil\Tests\TestCase;

uses(TestCase::class)->in('Feature/Console', 'Unit');
uses(RequestTestCase::class)->in('Feature/Request');
