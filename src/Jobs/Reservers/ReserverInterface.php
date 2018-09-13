<?php

namespace  Qless\Jobs\Reservers;

use Qless\Jobs\Job;
use Qless\Queue;

/**
 * Qless\Jobs\Reservers\ReserverInterface
 *
 * @package Qless\Jobs\Reservers
 */
interface ReserverInterface
{
    /**
     * Gets reserver's description.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Gets a list of the using queues.
     *
     * @return Queue[]
     */
    public function getQueues(): array;

    /**
     * Reserve a job to perform work.
     *
     * @return Job|null
     */
    public function reserve(): ?Job;

    /**
     * Doing preparing before fork.
     *
     * @return void
     */
    public function beforeFork(): void;
}
