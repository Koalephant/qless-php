<?php

namespace  Qless\Jobs\Reservers;

use Qless\Jobs\BaseJob;

/**
 * Qless\Jobs\Reservers\OrderedReserver
 *
 * @package Qless\Jobs\Reservers
 */
class OrderedReserver extends AbstractReserver implements ReserverInterface
{
    const TYPE_DESCRIPTION = 'ordered';

    /**
     * {@inheritdoc}
     *
     * @return BaseJob|null
     */
    final public function reserve(): ?BaseJob
    {
        $this->logger->debug('Attempting to reserve a job using {reserver} reserver', [
            'reserver' => $this->getDescription(),
        ]);

        foreach ($this->queues as $queue) {
            /** @var \Qless\Jobs\BaseJob|null $job */
            $job = $queue->pop($this->worker);
            if ($job !== null) {
                $this->logger->info('Found a job on {queue}', ['queue' => (string) $queue]);
                return $job;
            }
        }

        return null;
    }
}
