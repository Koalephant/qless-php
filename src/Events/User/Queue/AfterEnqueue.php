<?php

namespace Qless\Events\User\Queue;

/**
 * Qless\Events\User\Queue\AfterEnqueue
 *
 * @package Qless\Events\User\Queue
 */
class AfterEnqueue extends AbstractQueueEvent
{
    private $jid;
    private $data;
    private $className;

    /**
     * AfterEnqueue constructor.
     *
     * @param object $source
     * @param string $jid
     * @param array  $data
     * @param string $className
     */
    public function __construct($source, string $jid, array $data, string $className)
    {
        parent::__construct($source);

        $this->jid = $jid;
        $this->data = $data;
        $this->className = $className;
    }

    public static function getHappening(): string
    {
        return 'afterEnqueue';
    }

    public function getJid(): string
    {
        return $this->jid;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getClassName(): string
    {
        return $this->className;
    }
}
