<?php
namespace Qless\Tests\Workers;

use Qless\Queues\Queue;
use Qless\Tests\QlessTestCase;
use Qless\Tests\Stubs\JobHandler;
use Qless\Workers\ResourceLimitedWorkerInterface;

abstract class WorkerLimitTest extends QlessTestCase
{
    public function testNumberJobs(): void
    {
        $queue = $this->getQueue(100);
        $worker = $this->getWorker();
        $worker->setMaximumNumberJobs(1);
        $worker->run();

        self::assertEquals(99, $queue->length());
    }

    public function testTimeLimitWorker(): void
    {
        $queue = $this->getQueue(500);
        $worker = $this->getWorker();
        $worker->setTimeLimit(1);
        $worker->run();

        self::assertNotEmpty($queue->length());
    }

    public function testMemoryLimitWorker(): void
    {
        $queue = $this->getQueue(100);
        $worker = $this->getWorker();
        $worker->setMemoryLimit(1);
        $worker->run();

        self::assertNotEmpty($queue->length());
    }


    private function getQueue(int $size): Queue
    {
        $queue = new Queue('test-queue', $this->client);
        for ($i = 0; $i < $size; $i++) {
            $queue->put(JobHandler::class, []);
        }

        return $queue;
    }

    abstract protected function getWorker(): ResourceLimitedWorkerInterface;
}
