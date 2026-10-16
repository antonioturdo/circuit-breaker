<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

/**
 * Receives the events of the circuits, typically for logging, metrics and alerting.
 *
 * Exceptions thrown by the listener are caught and ignored by the circuit: a failing listener never makes the
 * protected call fail nor leaves a transition half done.
 */
interface EventListenerInterface
{
    public function handleEvent(EventInterface $event): void;
}
