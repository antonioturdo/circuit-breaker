<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

enum State: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
