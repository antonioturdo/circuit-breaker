<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Fixtures;

use Zeusi\CircuitBreaker\Event\EventInterface;
use Zeusi\CircuitBreaker\Event\EventListenerInterface;

final class RecordingEventListener implements EventListenerInterface
{
    /**
     * @var list<EventInterface>
     */
    private array $events = [];

    public function handleEvent(EventInterface $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Returns the events received since the last call.
     *
     * @return list<EventInterface>
     */
    public function flush(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
