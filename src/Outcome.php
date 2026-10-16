<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

enum Outcome: string
{
    case Success = 'success';
    case Failure = 'failure';
}
