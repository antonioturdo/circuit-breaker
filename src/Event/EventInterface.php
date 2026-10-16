<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

interface EventInterface
{
    public function getService(): string;
}
