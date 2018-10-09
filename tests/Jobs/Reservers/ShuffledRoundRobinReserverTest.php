<?php

namespace Qless\Tests\Jobs\Reservers;

use Qless\Jobs\Reservers\ShuffledRoundRobinReserver;
use Qless\Queues\Queue;

/**
 * Qless\Tests\Jobs\Reservers\ShuffledRoundRobinReserver
 *
 * @package Qless\Tests\Jobs\Reservers
 */
class ShuffledRoundRobinReserverTest extends RoundRobinReserverTest
{
    /**
     * @test
     * @expectedException \Qless\Exceptions\InvalidArgumentException
     * @expectedExceptionMessage A queues list or a specification to reserve queues are required.
     */
    public function shouldThrowExceptionForNoQueuesAndSpec()
    {
        new ShuffledRoundRobinReserver($this->client->queues, []);
    }

    /** @test */
    public function shouldNormalConstructObjectWithQueuesStack()
    {
        $queue1 = new Queue('queue-1', $this->client);
        $queue2 = new Queue('queue-2', $this->client);

        $stack = [$queue1, $queue2];

        $reserver = new ShuffledRoundRobinReserver($this->client->queues, ['queue-1', 'queue-2']);

        $this->assertEquals($stack, $reserver->getQueues());
    }

    /** @test */
    public function shouldGetDescription()
    {
        $reserver = new ShuffledRoundRobinReserver($this->client->queues, ['queue-1', 'queue-2']);

        $this->assertEquals('queue-1, queue-2 (shuffled round robin)', $reserver->getDescription());
    }

    /** @test */
    public function shouldShuffleQueuesBeforeWork()
    {
        $reserver = new ShuffledRoundRobinReserver(
            $this->client->queues,
            ['queue-1', 'queue-2', 'queue-3', 'queue-4', 'queue-5']
        );

        $reserver->beforework();

        $this->assertRegExp(
            '#queue-\d, queue-\d, queue-\d, queue-\d, queue-\d \(shuffled round robin\)#',
            $reserver->getDescription()
        );

        $this->assertCount(5, $reserver->getQueues());
        $this->assertContainsOnly(Queue::class, $reserver->getQueues());
    }
}
