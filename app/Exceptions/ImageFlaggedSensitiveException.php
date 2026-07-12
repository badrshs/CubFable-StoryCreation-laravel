<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every engine in the fallback chain refused this image on content grounds,
 * in both the original-prompt and rewritten-prompt rounds. The item should be
 * flagged for human review instead of being retried further.
 */
class ImageFlaggedSensitiveException extends RuntimeException {}
