<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an operation is attempted on a book whose status does not allow it,
 * e.g. starting checkout for a book that is no longer a draft.
 */
class InvalidBookStateException extends Exception
{
    public static function notAwaitingPayment(): self
    {
        return new self('Book is not awaiting payment.');
    }
}
