<?php
namespace App\Services\Credits;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(string $message = "Insufficient credits.", int $code = 402)
    {
        parent::__construct($message, $code);
    }
}
