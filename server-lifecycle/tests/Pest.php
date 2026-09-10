<?php

use Tests\TestCase;

// Pelican's root phpunit.xml does not discover plugin test directories. Running
// `vendor/bin/pest plugins/server-lifecycle/tests` loads this minimal bootstrap
// while continuing to use Pelican's own application TestCase.
pest()->extend(TestCase::class)->in('Unit', 'Feature');
