<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an image provider refuses to generate on content-safety grounds
 * (e.g. Gemini finishReason IMAGE_OTHER / SAFETY). Signals the retry layer to
 * rephrase the prompt rather than failing outright.
 */
class ImageContentRejectedException extends Exception
{
    //
}
