<?php

namespace Qless\Tests\Events;

use Qless\Jobs\Reservers\OrderedReserver;
use Qless\Queues\Queue;
use Qless\Tests\QlessTestCase;
use Qless\Tests\Stubs\EventsDrivenJobHandler;
use Qless\Tests\Stubs\JobHandler;
use Qless\Tests\Stubs\PerformClassAwareWorker;

/**
 * Qless\Tests\Events\CustomJobPerformHandlerTest
 *
 * @package Qless\Tests\Events
 */
class CustomJobPerformHandlerTest extends QlessTestCase
{
    /** @test */
    public function shouldSubscribeOnEvents()
    {
        $queue = new Queue('test-queue', $this->client);
        $jid = $queue->put(JobHandler::class, []);

        $worker = new PerformClassAwareWorker(new OrderedReserver([$queue]), $this->client);

        $eventsDrivenJobHandler = new EventsDrivenJobHandler();
        $worker->registerJobPerformHandler($eventsDrivenJobHandler);
        $worker->run();

        $expected = [
            "{$jid}:beforePerform",
            "{$jid}:perform",
            "{$jid}:afterPerform"
        ];

        $this->assertEquals($expected, $_SERVER['caller']['stack']);
    }
}
