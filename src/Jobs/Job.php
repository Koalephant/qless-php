<?php

namespace Qless\Jobs;

use Qless\Client;
use Qless\EventsManagerAwareInterface;
use Qless\EventsManagerAwareTrait;
use Qless\Exceptions\InvalidArgumentException;
use Qless\Exceptions\LostLockException;
use Qless\Exceptions\QlessException;
use Qless\Exceptions\UnknownPropertyException;

/**
 * Qless\Jobs\Job
 *
 * @package Qless\Jobs
 *
 * @property-read string $jid
 * @property-read string $klass
 * @property-read string $queue
 * @property JobData $data
 * @property-read array $history
 * @property-read string[] $dependencies
 * @property-read string[] $dependents
 * @property-read int $priority
 * @property-read string $worker
 * @property-read string[] $tags
 * @property-read float $expires
 * @property-read int $remaining
 * @property-read int $retries
 */
final class Job implements EventsManagerAwareInterface
{
    use EventsManagerAwareTrait;

    /**
     * The job id.
     *
     * @var string
     */
    private $jid;

    /**
     * The class of the job.
     *
     * @var string
     */
    private $klass;

    /**
     * The queue the job is in.
     *
     * @var string
     */
    private $queue;

    /**
     * The data for the job.
     *
     * @var JobData
     */
    private $data;

    /**
     * The history of what has happened to the job so far.
     *
     * @var array
     */
    private $history;

    /**
     * The jids of other jobs that must complete before this one.
     *
     * @var string[]
     */
    private $dependencies;

    /**
     * The jids of other jobs that depend on this one.
     *
     * @var string[]
     */
    private $dependents;

    /**
     * The priority of this job.
     *
     * var int
     */
    private $priority;

    /**
     * The internal worker name (usually consumer identifier).
     *
     * @var string
     */
    private $worker;

    /**
     * Array of tags for this job.
     *
     * @var string[]
     */
    private $tags;

    /**
     * When you must either check in with a heartbeat or turn it in as completed.
     *
     * @var float
     */
    private $expires;

    /**
     * The number of retries remaining for this job.
     *
     * @var int
     */
    private $remaining;

    /**
     * The number of retries originally requested.
     *
     * @var int
     */
    private $retries;

    /** @var Client */
    private $client;

    /** @var ?object */
    private $instance;

    /** @var array */
    private $rawData;

    private $jobFactory;

    /**
     * Job constructor.
     *
     * @param Client $client
     * @param array $data
     */
    public function __construct(Client $client, array $data)
    {
        $this->client = $client;
        $this->rawData = $data;

        $this->jobFactory = new JobFactory();
        $this->jobFactory->setEventsManager($client->getEventsManager());

        $this->jid = $data['jid'];
        $this->klass = $data['klass'];
        $this->queue = $data['queue'];
        $this->data = new JobData(json_decode($data['data'], true) ?: []);
        $this->history = $data['history'] ?? [];
        $this->dependencies = $data['dependencies'] ?? [];
        $this->dependents = $data['dependents'] ?? [];
        $this->priority = (int) $data['priority'] ?? 0;
        $this->worker = $data['worker'];
        $this->tags = $data['tags'] ?? [];
        $this->expires = (float) $data['expires'] ?? 0.0;
        $this->remaining = (int) $data['remaining'] ?? 0;
        $this->retries = (int) $data['retries'] ?? 0;
    }

    /**
     * Gets the internal Job's properties.
     *
     * Do not call this method directly as it is a PHP magic method that
     * will be implicitly called when executing `$value = $job->property;`.
     *
     * @param  string $name
     * @return mixed
     *
     * @throws UnknownPropertyException
     */
    public function __get(string $name)
    {
        switch ($name) {
            case 'jid':
                return $this->jid;
            case 'klass':
                return $this->klass;
            case 'queue':
                return $this->queue;
            case 'data':
                return $this->data;
            case 'history':
                return $this->history;
            case 'dependencies':
                return $this->dependencies;
            case 'dependents':
                return $this->dependents;
            case 'priority':
                return $this->priority;
            case 'worker':
                return $this->worker;
            case 'tags':
                return $this->tags;
            case 'expires':
                return $this->expires;
            case 'remaining':
                return $this->remaining;
            case 'retries':
                return $this->retries;
            default:
                throw new UnknownPropertyException('Getting unknown property: ' . self::class . '::' . $name);
        }
    }

    /**
     * Seconds remaining before this job will timeout.
     *
     * @return float
     */
    public function ttl(): float
    {
        return $this->expires - microtime(true);
    }

    /**
     * Add the specified tags to this job.
     *
     * @param  string ...$tags A list of tags to remove from this job.
     * @return void
     */
    public function tag(...$tags): void
    {
        $tags = func_get_args();
        $response = call_user_func_array([$this->client, 'call'], array_merge(['tag', 'add', $this->jid], $tags));

        $this->tags = json_decode($response, true);
    }

    /**
     * Remove the specified tags to this job
     *
     * @param  string $tags... list of tags to add to this job
     * @return void
     */
    public function untag($tags): void
    {
        $tags = func_get_args();
        $this->tags = json_decode(
            call_user_func_array([$this->client, 'call'], array_merge(['tag', 'remove', $this->jid], $tags)),
            true
        );
    }

    /**
     * Complete a job and optionally put it in another queue,
     * either scheduled or to be considered waiting immediately.
     *
     * Like Queue::put and Queue::move, it accepts a delay, and dependencies.
     *
     * @see \Qless\Queues\Queue::put
     *
     * @param  string|null $nextq
     * @param  int         $delay
     * @param  array       $depends
     * @return string
     */
    public function complete(?string $nextq = null, int $delay = 0, array $depends = []): string
    {
        $params = [
            $this->jid,
            $this->worker,
            $this->queue,
            json_encode($this->data, JSON_UNESCAPED_SLASHES) ?: '{}'
        ];

        if ($nextq) {
            $next = ['next', $nextq, 'delay', $delay, 'depends', json_encode($depends, JSON_UNESCAPED_SLASHES)];
            $params = array_merge($params, $next);
        }

        return call_user_func_array(
            [$this->client, 'complete'],
            $params
        );
    }

    /**
     * Requeue this job.
     *
     * Optional values to replace when re-queuing job
     *
     * * int delay          delay (in seconds)
     * * array data         replacement data
     * * int priority       replacement priority
     * * int retries        replacement number of retries
     * * string[] tags      replacement tags
     * * string[] depends   replacement list of JIDs this job is dependent on
     *
     * @param  string $queue New queue name.
     * @param  array  $opts  Optional parameters.
     * @return string
     */
    public function requeue(?string $queue = null, array $opts = []): string
    {
        $opts = array_merge(
            [
                'delay'     => 0,
                'data'      => $this->data,
                'priority'  => $this->priority,
                'retries'   => $this->retries,
                'tags'      => $this->tags,
                'depends'   => $this->dependencies,
            ],
            $opts
        );

        $queueName = $queue ?: $this->queue;

        $data = json_encode($opts['data'], JSON_UNESCAPED_SLASHES) ?: '{}';

        return $this->client
            ->requeue(
                $this->worker,
                $queueName,
                $this->jid,
                $this->klass,
                $data,
                $opts['delay'],
                'priority',
                $opts['priority'],
                'tags',
                json_encode($opts['tags'], JSON_UNESCAPED_SLASHES),
                'retries',
                $opts['retries'],
                'depends',
                json_encode($opts['depends'], JSON_UNESCAPED_SLASHES)
            );
    }

    /**
     * Return the job to the work queue for processing
     *
     * @param string $group
     * @param string $message
     * @param int $delay
     *
     * @return int remaining retries available
     */
    public function retry($group, $message, $delay = 0)
    {
        return $this->client
            ->retry(
                $this->jid,
                $this->queue,
                $this->worker,
                $delay,
                $group,
                $message
            );
    }

    /**
     * Set the timestamp of the new heartbeat.
     *
     * @param  array $data
     * @return float
     *
     * @throws LostLockException
     */
    public function heartbeat(array $data = []): float
    {
        try {
            $this->expires = $this->client->heartbeat(
                $this->jid,
                $this->worker,
                json_encode($data, JSON_UNESCAPED_SLASHES)
            );
        } catch (QlessException $e) {
            throw new LostLockException($e->getMessage(), 'Heartbeat', $this->jid, $e->getCode(), $e);
        }

        return $this->expires;
    }

    /**
     * Cancel a job.
     *
     * It will be deleted from the system, the thinking being that if you don't want
     * to do any work on it, it shouldn't be in the queuing system. Optionally cancels all jobs's dependents.
     *
     * @param bool $dependents true if associated dependents should also be cancelled
     *
     * @return array
     */
    public function cancel($dependents = false): array
    {
        if ($dependents && !empty($this->rawData['dependents'])) {
            return call_user_func_array(
                [$this->client, 'cancel'],
                array_merge([$this->jid], $this->rawData['dependents'])
            );
        }

        return $this->client->cancel($this->jid);
    }

    /**
     * Creates the instance to perform the job and calls the method on the instance.
     *
     * The instance must be specified in the payload['performMethod'];
     *
     * @return bool
     */
    public function perform(): bool
    {
        try {
            $instance = $this->getInstance();

            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }

            $this->getEventsManager()->fire('job:beforePerform', $this);
            $performMethod = $this->getPerformMethod();
            $instance->$performMethod($this);
            $this->getEventsManager()->fire('job:afterPerform', $this);

            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }
        } catch (\Throwable $e) {
            $this->fail(
                'system:fatal',
                sprintf('%s: %s in %s on line %d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())
            );

            return false;
        }

        return true;
    }

    /**
     * Mark the current Job as failed, with the provided group, and a more specific message.
     *
     * @param string $group   Some phrase that might be one of several categorical modes of failure
     * @param string $message Something more job-specific, like perhaps a traceback.
     *
     * @return bool|string The id of the failed Job if successful, or FALSE on failure.
     */
    public function fail(string $group, string $message)
    {
        $jsonData = json_encode($this->data, JSON_UNESCAPED_SLASHES) ?: '{}';

        $this->getEventsManager()->fire('job:onFailure', $this, compact('group', 'message'));

        return $this->client->fail($this->jid, $this->worker, $group, $message, $jsonData);
    }

    /**
     * Timeout this Job.
     *
     * @return void
     */
    public function timeout(): void
    {
        $this->client->timeout($this->jid);
    }

    /**
     * Get the instance of the class specified on this job.
     *
     * This instance will be used to call a perform method:
     * - $payload['performMethod']
     * - "perform" if not specified
     *
     * @return object
     *
     * @throws InvalidArgumentException
     */
    public function getInstance()
    {
        if ($this->instance === null) {
            $this->instance = $this->jobFactory->create(
                $this->klass,
                $this->getPerformMethod()
            );
        }

        return $this->instance;
    }

    /**
     * Gets method to execute on the instance (defaults to "perform").
     *
     * @return string
     */
    protected function getPerformMethod(): string
    {
        return $this->data['performMethod'] ?? 'perform';
    }
}
