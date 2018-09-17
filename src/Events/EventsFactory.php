<?php

namespace Qless\Events;

/**
 * Qless\Events\EventsFactory
 *
 * @package Qless\Events
 */
class EventsFactory
{
    /**
     * Tries to create an Event DTO.
     *
     * @param  null|string $data
     * @return null|QlessCoreEvent
     */
    public function fromData(?string $data = null): ?QlessCoreEvent
    {
        if (empty($data)) {
            return null;
        }

        $data = json_decode($data, true);
        if (empty($data)) {
            return null;
        }

        return new QlessCoreEvent(
            $data['event'],
            $data['jid'] ?? null,
            $data['worker'] ?? null,
            $data['queue'] ?? null,
            $data['to'] ?? null,
            $data['group'] ?? null,
            $data['message'] ?? null,
            $data['option'] ?? null,
            $data['message'] ?? null
        );
    }
}
