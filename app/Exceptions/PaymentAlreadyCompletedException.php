<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when checkout is requested for a book whose PaymentIntent has already
 * succeeded. The paid path has already run by the time this is thrown, so
 * callers only need to route the user away from checkout.
 */
class PaymentAlreadyCompletedException extends Exception
{
    public function __construct(string $message = 'Payment already completed.')
    {
        parent::__construct($message);
    }
}
