<?php

declare(strict_types=1);

namespace Talea\Tests\Support;

use PHPUnit\Event\Test\Finished as TestFinished;
use PHPUnit\Event\Test\FinishedSubscriber as TestFinishedSubscriber;
use PHPUnit\Event\TestSuite\Finished as SuiteFinished;
use PHPUnit\Event\TestSuite\FinishedSubscriber as SuiteFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Lifts the time limit after every test and test class. Product code a test runs in-process sets one (Backup::restore,
 * SiteExport, the installer: set_time_limit(300)), and on Linux PHP counts it in CPU time of the whole process: a ParaTest
 * worker runs many test classes, so the limit set by one class kept running through the next ones and ended a worker in
 * whatever test it had reached after 300 s of CPU – on a slower CI runner a different test each time.
 */
final class NoTimeLimit implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements TestFinishedSubscriber {
                public function notify(TestFinished $event): void
                {
                    set_time_limit(0);
                }
            },
            new class implements SuiteFinishedSubscriber {
                public function notify(SuiteFinished $event): void
                {
                    set_time_limit(0);
                }
            },
        );
    }
}
