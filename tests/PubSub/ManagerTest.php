<?php

namespace Qless\Tests\PubSub;

use Qless\Exceptions\InvalidArgumentException;
use Qless\Jobs\BaseJob;
use Qless\Jobs\Reservers\DefaultReserver;
use Qless\PubSub\Manager;
use Qless\Tests\QlessTestCase;
use Qless\Tests\Support\BackgroundProcessTrait;
use Qless\Workers\SimpleWorker;

/**
 * Qless\Tests\PubSub\ManagerTest
 *
 * @package Qless\Tests
 */
class ManagerTest extends QlessTestCase
{

    use BackgroundProcessTrait;

    public const QUEUE_NAME = 'pubsub-manager-test';


    public const EVENT_TYPES = [
        Manager::EVENT_CANCELED,
        Manager::EVENT_COMPLETED,
        Manager::EVENT_FAILED,
        Manager::EVENT_POPPED,
        Manager::EVENT_STALLED,
        Manager::EVENT_PUT,
        Manager::EVENT_TRACK,
        Manager::EVENT_UNTRACK
    ];

    /**
     * @test
     */
    public function shouldNotAcceptOtherEvents(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client->events->on('foo', static function (BaseJob $job) {
        });
    }

    /**
     * @test
     */
    public function shouldReceiveCanceledEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_CANCELED);
    }

    /**
     * @test
     */
    public function shouldReceiveCompletedEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_COMPLETED);
    }

    /**
     * @test
     */
    public function shouldReceiveFailedEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_FAILED);
    }

    /**
     * @test
     */
    public function shouldReceivePoppedEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_POPPED);
    }

    /**
     * @test
     */
    public function shouldReceiveStalledEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_STALLED);
    }

    /**
     * @test
     */
    public function shouldReceivePutEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_PUT);
    }

    /**
     * @test
     */
    public function shouldReceiveTrackEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_TRACK);
    }

    /**
     * @test
     */
    public function shouldReceiveUntrackEvent(): void
    {
        $this->shouldReceivedExpectedMessageType(Manager::EVENT_UNTRACK);
    }

    protected function shouldReceivedExpectedMessageType(string $type): void
    {
        $client = $this->client;

        $jobLimit = 1;
        if ($type === Manager::EVENT_STALLED) {
            $jobLimit = 2;
            $client->config->set('heartbeat', 5);
        }

        $queue = $client->queues[self::QUEUE_NAME];

        $jid = $queue->put(DummyPubSubJob::class, compact('type'));

        $this->runBackgroundScript(
            __DIR__ . '/../pubsubmanager-jobactions.php',
            [$type, $jid],
            __DIR__ . '/../pubsubmanager-jobactions.log'
        );

        sleep(2);

        $client->jobs[$jid]->track();

        $expectedMessage = sprintf('%s: %s', $type, $jid);

        $worker = new SimpleWorker(
            new DefaultReserver($client->queues, [self::QUEUE_NAME]),
            $client
        );
        $worker->setMaximumNumberJobs($jobLimit);
        $worker->run();
        $client->workers->remove($worker->getName());
        $messagesReceived = explode(PHP_EOL, $this->getBackgroundStdOut());
        $this->stopBackgroundTask();

        self::assertContains($expectedMessage, $messagesReceived, 'Expected events of type ' . $type);
    }
}
