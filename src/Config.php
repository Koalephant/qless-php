<?php

namespace Qless;

/**
 * Qless\Config
 *
 * @package Qless
 */
class Config
{
    /** @var Client */
    private $client;

    /**
     * Config constructor.
     *
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Gets the value for the specified config name, falling back to the default if it does not exist
     *
     * @param string $name
     * @param mixed $default
     * @return mixed|null
     *
     * @throws QlessException
     */
    public function get(string $name, $default = null)
    {
        $res = $this->client->call('config.get', $name);

        return $res === false ? $default : $res;
    }

    /**
     * Sets the config name to the specified value
     *
     * @param string          $name
     * @param string|int|bool $value
     *
     * @throws QlessException
     */
    public function set(string $name, $value)
    {
        $this->client->call('config.set', $name, $value);
    }

    /**
     * Clears the specified config name
     *
     * @param string $name
     * @return void
     *
     * @throws QlessException
     */
    public function clear($name)
    {
        $this->client->call('config.unset', $name);
    }
}
