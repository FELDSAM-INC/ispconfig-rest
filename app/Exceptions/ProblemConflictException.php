<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A 409 refusal with a machine-readable problem type (spec 039), the conflict
 * sibling of ProblemAuthorizationException.
 *
 * Extends ConflictHttpException so every existing handling path still yields
 * 409; App\Support\Problem renders the type URI and any extension members next
 * to the unchanged title and detail. Conflicts raised as a plain
 * ConflictHttpException keep `type: about:blank`.
 */
class ProblemConflictException extends ConflictHttpException
{
    /**
     * @param  string  $problemType  an App\Support\ProblemType name
     * @param  array<string, mixed>  $extensions  extension members
     */
    public function __construct(
        string $message,
        public readonly string $problemType,
        public readonly array $extensions = [],
    ) {
        parent::__construct($message);
    }
}
