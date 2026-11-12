<?php

namespace App\Credits;

use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public int $needed, public int $balance, string $why = 'not enough credits')
    {
        parent::__construct("This needs {$needed} credits; you have {$balance} ({$why}).");
    }
}
