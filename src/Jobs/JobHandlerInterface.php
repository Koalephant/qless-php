<?php

namespace Qless\Jobs;

/**
 * Qless\Jobs\JobHandlerInterface
 *
 * @package Qless\Job
 */
interface JobHandlerInterface
{
    /**
     * The Job perform handler.
     *
     * @param  Job $job
     * @return void
     */
    public function perform(Job $job): void;
}
