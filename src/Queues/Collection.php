<?php

namespace Qless\Queues;

use ArrayAccess;
use Qless\Client;
use Qless\Exceptions\UnknownPropertyException;
use Qless\Exceptions\UnsupportedFeatureException;

/**
 * Qless\Queues\Collection
 *
 * @property-read array $counts
 *
 * @package Qless\Queues
 */
class Collection implements ArrayAccess
{
    /** @var Client */
    private $client;

    /**
     * Collection constructor.
     *
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function __get(string $name)
    {
        switch ($name) {
            // What queues are there, and how many jobs do they have running, waiting, scheduled, etc.
            case 'counts':
                return json_decode($this->client->queues(), true) ?: [];
            default:
                throw new UnknownPropertyException('Getting unknown property: ' . self::class . '::' . $name);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param  mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        $queues = json_decode($this->client->queues(), true) ?: [];

        foreach ($queues as $queue) {
            if (isset($queue['name']) && $queue['name'] === $offset) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a queue object associated with the provided queue name.
     *
     * @param  string $offset
     * @return Queue
     */
    public function offsetGet($offset)
    {
        return new Queue($offset, $this->client);
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnsupportedFeatureException
     */
    public function offsetSet($offset, $value)
    {
        throw new UnsupportedFeatureException('Setting a queue is not supported using Queues collection.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnsupportedFeatureException
     */
    public function offsetUnset($offset)
    {
        throw new UnsupportedFeatureException('Deleting a queue is not supported using Queues collection.');
    }
}
