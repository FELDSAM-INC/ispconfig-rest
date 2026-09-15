<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * A 403 refusal with a machine-readable problem type (spec 023 research R2).
 * Extends AuthorizationException so every existing handling path keeps
 * working; App\Support\Problem renders the type URI and the extension members
 * next to the unchanged title and detail.
 */
class ProblemAuthorizationException extends AuthorizationException
{
    /**
     * @param  string  $problemType  an App\Support\ProblemType name
     * @param  array<string, mixed>  $extensions  extension members (limit, feature)
     */
    public function __construct(
        string $message,
        public readonly string $problemType,
        public readonly array $extensions = [],
    ) {
        parent::__construct($message);
    }
}
