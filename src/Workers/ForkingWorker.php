<?php

namespace Qless\Workers;

use Qless\Events\QlessCoreEvent;
use Qless\Events\Subscriber;
use Qless\Exceptions\ErrorCodes;
use Qless\Exceptions\RuntimeException;
use Qless\Jobs\Job;
use Qless\Jobs\JobHandlerInterface;
use Qless\Signals\SignalHandler;
use Qless\Workers\Traits\ShutdownAwareTrait;
use Qless\Workers\Traits\SignalAwareTrait;

/**
 * Qless\Workers\ForkingWorker
 *
 * @todo This class is still needs to be refactored.
 *
 * @package Qless\Workers
 */
final class ForkingWorker extends AbstractWorker implements SignalAwareInterface
{
    use ShutdownAwareTrait, SignalAwareTrait;

    private const PROCESS_TYPE_MASTER = 0;
    private const PROCESS_TYPE_JOB = 1;
    private const PROCESS_TYPE_WATCHDOG = 2;

    /** @var int */
    private $processType = self::PROCESS_TYPE_MASTER;

    /** @var ?int */
    private $childPID = null;

    /** @var ?int */
    private $watchdogPID = null;

    /** @var int */
    private $childProcesses = 0;

    /** @var bool */
    private $paused = false;

    /** @var string */
    private $who = 'master';

    /** @var array */
    private $logContext = [];

    /** @var resource[] */
    private $sockets = [];

    /** @var Job|null */
    private $job;

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function run(): void
    {
        /**
         * Do not use declare(ticks=1) instead use pcntl_async_signals(true)
         * There's no performance hit or overhead with `pcntl_async_signals()`.
         *
         * @link https://blog.pascal-martin.fr/post/php71-en-other-new-things.html
         */
        pcntl_async_signals(true);

        $this->registerSignalHandler();

        $this->who = 'master:' . $this->name;
        $this->logContext = ['type' => $this->who, 'job.identifier' => null];
        $this->logger->info('{type}: Worker started', $this->logContext);
        $this->logger->info(
            '{type}: monitoring the following queues (in order), {queues}',
            ['type' => $this->who, 'queues' => implode(', ', $this->reserver->getQueues())]
        );

        $did_work = false;

        while (true) {
            if ($this->shutdown) {
                $this->logger->info('{type}: Shutting down', $this->logContext);
                break;
            }

            while ($this->paused) {
                usleep(250000);
            }

            if ($did_work) {
                $this->title(
                    sprintf(
                        'Waiting for %s with interval %d sec',
                        implode(',', $this->reserver->getQueues()),
                        $this->interval
                    )
                );
                $did_work = false;
            }

            $job = $this->reserve();
            if ($job === null) {
                if ($this->interval == 0) {
                    break;
                }
                usleep($this->interval * 1000000);
                continue;
            }

            $this->job = $job;
            $this->logContext['job.identifier'] = $job->jid;

            // fork processes
            $this->childStart();
            $this->watchdogStart($this->client->createSubscriber(['ql:log']));

            $this->title(sprintf('Forked %d at %s', $this->childPID, strftime('%F %T')));

            // Parent process, sit and wait
            while ($this->childProcesses > 0) {
                $status = null;
                $pid   = pcntl_wait($status, WUNTRACED);
                if ($pid > 0) {
                    if ($pid === $this->childPID) {
                        $exited = $this->childProcessStatus($status);
                    } elseif ($pid === $this->watchdogPID) {
                        $exited = $this->watchdogProcessStatus($status);
                    } else {
                        // unexpected?
                        $this->logger->info(sprintf("master received status for unknown PID %d; exiting\n", $pid));
                        exit(1);
                    }

                    if ($exited) {
                        --$this->childProcesses;
                        switch ($pid) {
                            case $this->childPID:
                                $this->childPID = null;
                                if ($this->watchdogPID) {
                                    // shutdown watchdog immediately if child has exited
                                    posix_kill($this->watchdogPID, SIGKILL);
                                }
                                break;

                            case $this->watchdogPID:
                                $this->watchdogPID = null;
                                break;
                        }
                    }
                }
            }

            foreach ($this->sockets as $socket) {
                socket_close($socket);
            }
            $this->sockets  = [];
            $this->job = null;
            $this->logContext['job.identifier'] = null;
            $did_work = true;

            /**
             * We need to reconnect due to bug in Redis library that always sends QUIT on destruction of \Redis
             * rather than just leaving socket around. This call will sometimes generate a broken pipe notice
             */
            $old = error_reporting();
            error_reporting($old & ~E_NOTICE);
            try {
                $this->client->reconnect();
            } finally {
                error_reporting($old);
            }
        }
    }

    /**
     * Forks and creates a socket pair for communication between parent and child process
     *
     * @param  resource $socket
     * @return int PID if master or 0 if child
     *
     * @throws RuntimeException
     */
    private function fork(&$socket)
    {
        $pair = [];
        if (\socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair) === false) {
            $this->logger->error(
                '{type}: Unable to create socket pair; ' . socket_strerror(socket_last_error($pair[0])),
                $this->logContext
            );

            exit(0);
        }

        // Fork child worker.
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork child worker.');
        }

        if ($pid !== 0) {
            // MASTER
            $this->childProcesses++;

            $socket = $pair[0];
            socket_close($pair[1]);

            // wait up to 10ms to receive data
            socket_set_option(
                $socket,
                SOL_SOCKET,
                SO_RCVTIMEO,
                ['sec' => 0, 'usec' => 10000]
            );

            return $pid;
        }

        $socket = $pair[1];
        socket_close($pair[0]);

        $reserved = str_repeat('x', 20240);

        register_shutdown_function(function () use (&$reserved, $socket) {
            // shutting down
            if (null === $error = error_get_last()) {
                return;
            }
            unset($reserved);

            if (call_user_func(new ErrorCodes, $error['type']) == null) {
                return;
            }

            $this->logger->debug('Sending error to master', $this->logContext);
            $data = serialize($error);

            do {
                $len = socket_write($socket, $data);
                if ($len === false || $len === 0) {
                    break;
                }

                $data = substr($data, $len);
            } while (is_numeric($len) && $len > 0);
        });

        return $pid;
    }

    /**
     * @param resource $socket
     *
     * @return null|string
     */
    private function readErrorFromSocket($socket)
    {
        $error_info = "";
        while (!empty($res = socket_read($socket, 8192))) {
            $error_info .= $res;
        }
        $error_info = unserialize($error_info);

        if (is_array($error_info)) {
            return sprintf(
                '[%s] %s:%d %s',
                call_user_func(new ErrorCodes, $error_info['type']) ?: 'Unknown',
                $error_info['file'],
                $error_info['line'],
                $error_info['message']
            );
        }

        return null;
    }

    /**
     * Handle process exit status.
     *
     * @param int $pid
     * @param int $childType
     * @param int $exitStatus
     *
     * @return false|string FALSE if exit status indicates success; otherwise, a string containing the error messages.
     */
    private function handleProcessExitStatus(int $pid, int $childType, int $exitStatus)
    {
        switch ($childType) {
            case self::PROCESS_TYPE_JOB:
                $childType = 'child';
                break;
            default:
                $childType = 'watchdog';
        }

        if ($exitStatus === 0) {
            $this->logger->debug("{type}: {$childType} process exited successfully", $this->logContext);
            return false;
        }

        $error = $this->readErrorFromSocket($this->sockets[$pid]);
        $jobFailedMessage = $error ?: "{$childType} process failed with status: {$exitStatus}";

        $this->logger->error("{type}: fatal error in {$childType} process: {$jobFailedMessage}", $this->logContext);

        return $jobFailedMessage;
    }

    private function childStart(): void
    {
        $socket = null;
        $this->childPID = $this->fork($socket);
        if ($this->childPID !== 0) {
            $this->sockets[$this->childPID] = $socket;
            return;
        }

        if ($this->job instanceof Job == false) {
            return;
        }

        $this->processType = self::PROCESS_TYPE_JOB;
        $this->clearSignalHandler();

        $jid = $this->job->jid;
        $this->who = 'child:' . $this->name;
        $this->logContext = ['type' => $this->who];
        $status = 'Processing ' . $jid . ' since ' . strftime('%F %T');

        $this->title($status);
        $this->childPerform($this->job);

        socket_close($socket);

        exit(0);
    }

    /**
     * Process a single job.
     *
     * @param  Job $job The job to be processed.
     * @return void
     */
    public function childPerform(Job $job): void
    {
        $context = ['job' => $job->jid, 'type' => $this->who];

        try {
            if ($this->jobPerformClass) {
                /** @var JobHandlerInterface $performClass */
                $performClass = new $this->jobPerformClass;
                $performClass->perform($job);
            } else {
                $job->perform();
            }
            $this->logger->notice('{type}: Job {job} has finished', $context);
        } catch (\Throwable $e) {
            $context['stack'] = $e->getMessage();

            $this->logger->critical('{type}: {job} has failed {stack}', $context);
            $job->fail('system:fatal', $e->getMessage());
        }
    }

    /**
     * @param  int $status The status parameter supplied to a successful call to pcntl_* functions.
     * @return bool
     */
    private function childProcessStatus(int $status): bool
    {
        if ($this->childPID == null) {
            return false;
        }

        switch (true) {
            case pcntl_wifexited($status):
                $code = pcntl_wexitstatus($status);
                $res = $this->handleProcessExitStatus($this->childPID, self::PROCESS_TYPE_JOB, $code);

                if ($res !== false && $this->job instanceof Job) {
                    $this->job->fail('system:fatal', $res);
                }
                return true;
            case pcntl_wifsignaled($status):
                $sig = pcntl_wtermsig($status);
                if ($sig !== SIGKILL) {
                    $this->childProcessUnhandledSignal($sig);
                }
                return true;
            case pcntl_wifstopped($status):
                $sig = pcntl_wstopsig($status);
                $this->logger->info(
                    sprintf(
                        "child %d was stopped with signal %s\n",
                        $this->childPID,
                        SignalHandler::sigName($sig)
                    )
                );
                return false;
            default:
                $this->logger->error(sprintf("unexpected status for child %d; exiting\n", $this->childPID));
                exit(1);
        }
    }

    private function childProcessUnhandledSignal($sig)
    {
        $context = $this->logContext;
        $context['signal'] = SignalHandler::sigName($sig);
        $this->logger->notice("{type}: child terminated with unhandled signal '{signal}'", $context);
    }

    private function childKill()
    {
        if ($this->childPID === null) {
            return;
        }

        $this->logger->info('{type}: Killing child at {child}', ['child' => $this->childPID, 'type' => $this->who]);

        if (pcntl_waitpid($this->childPID, $status, WNOHANG) != -1) {
            posix_kill($this->childPID, SIGKILL);
        }

        $this->childPID = null;
    }

    private function watchdogStart(Subscriber $subscriber): void
    {
        $socket = null;
        $this->watchdogPID = $this->fork($socket);
        if ($this->watchdogPID !== 0) {
            $this->sockets[$this->watchdogPID] = $socket;
            return;
        }

        if ($this->job instanceof Job == false) {
            return;
        }

        $this->processType = self::PROCESS_TYPE_WATCHDOG;
        $this->clearSignalHandler();

        $jid = $this->job->jid;
        $this->who = 'watchdog:' . $this->name;
        $this->logContext = ['type' => $this->who];

        $this->title(sprintf('watching events for %s since %s', $jid, strftime('%F %T')));

        // @todo Move to a separated class
        ini_set('default_socket_timeout', -1);
        $subscriber->messages(function (string $channel, QlessCoreEvent $event = null) use ($subscriber, $jid) {
            if ($event === null) {
                return;
            }

            if ($event->valid() == false || $event->getJid() !== $jid) {
                return;
            }

            if ($this->childPID === null) {
                return;
            }

            switch ($event->getType()) {
                case QlessCoreEvent::LOCK_LOST:
                    if ($event->getWorker() === $this->name) {
                        $this->logger->info(
                            "{type}: sending SIGKILL to child {$this->childPID}; job handed out to another worker",
                            $this->logContext
                        );
                        posix_kill($this->childPID, SIGKILL);
                        $subscriber->stop();
                    }
                    break;
                case QlessCoreEvent::CANCELED:
                    if ($event->getWorker() === $this->name) {
                        $this->logger->info(
                            "{type}: sending SIGKILL to child {$this->childPID}; job canceled",
                            $this->logContext
                        );
                        posix_kill($this->childPID, SIGKILL);
                        $subscriber->stop();
                    }
                    break;
                case QlessCoreEvent::COMPLETED:
                case QlessCoreEvent::FAILED:
                    $subscriber->stop();
                    break;
            }
        });

        socket_close($socket);
        $this->logger->info("{type}: done", $this->logContext);

        exit(0);
    }

    /**
     * @param  int $status The status parameter supplied to a successful call to pcntl_* functions.
     * @return bool
     */
    private function watchdogProcessStatus(int $status): bool
    {
        if ($this->watchdogPID === null) {
            return false;
        }

        switch (true) {
            case pcntl_wifexited($status):
                $code = pcntl_wexitstatus($status);
                $this->handleProcessExitStatus($this->watchdogPID, self::PROCESS_TYPE_WATCHDOG, $code);
                return true;
            case pcntl_wifsignaled($status):
                $sig = pcntl_wtermsig($status);
                if ($sig !== SIGKILL) {
                    $this->logger->warning(
                        sprintf(
                            "watchdog %d terminated with unhandled signal %s\n",
                            $this->watchdogPID,
                            SignalHandler::sigName($sig)
                        )
                    );
                }
                return true;
            case pcntl_wifstopped($status):
                $sig = pcntl_wstopsig($status);
                $this->logger->warning(
                    sprintf(
                        "watchdog %d was stopped with signal %s\n",
                        $this->watchdogPID,
                        SignalHandler::sigName($sig)
                    )
                );
                return false;
            default:
                $this->logger->error(sprintf("unexpected status for watchdog %d; exiting\n", $this->childPID));
                exit(1);
        }
    }

    private function watchdogKill()
    {
        if ($this->watchdogPID) {
            $this->logger->info(
                '{type}: Killing watchdog at {child}',
                ['child' => $this->watchdogPID, 'type' => $this->who]
            );

            if (pcntl_waitpid($this->watchdogPID, $status, WNOHANG) != -1) {
                posix_kill($this->watchdogPID, SIGKILL);
            }
            $this->watchdogPID = null;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function pauseProcessing(): void
    {
        $this->logger->notice('{type}: USR2 received; pausing job processing', $this->logContext);
        $this->paused = true;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function unPauseProcessing(): void
    {
        $this->logger->notice('{type}: CONT received; resuming job processing', $this->logContext);
        $this->paused = false;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function shutdown(): void
    {
        if ($this->childPID) {
            $this->logger->notice('{type}: QUIT received; shutting down after child completes work', $this->logContext);
        } else {
            $this->logger->notice('{type}: QUIT received; shutting down', $this->logContext);
        }

        $this->doShutdown();
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function shutdownNow(): void
    {
        $this->doShutdown();
        $this->killChildren();
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function killChildren(): void
    {
        if (!$this->childPID && !$this->watchdogPID) {
            return;
        }

        $this->childKill();
        $this->watchdogKill();
    }
}
