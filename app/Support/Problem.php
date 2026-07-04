<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RFC 9457 application/problem+json response builder (constitution Principle V).
 */
class Problem
{
    /**
     * Build a problem+json response.
     *
     * @param  array<string, mixed>  $extensions  extra members (e.g. errors map for 422)
     */
    public static function response(int $status, string $title, ?string $detail = null, array $extensions = []): JsonResponse
    {
        $body = array_merge([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
        ], $detail !== null ? ['detail' => $detail] : [], $extensions);

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * Map a framework exception to its problem+json representation.
     */
    public static function fromThrowable(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::response(422, 'Validation failed', 'One or more fields are invalid.', [
                'errors' => $e->errors(),
            ]);
        }

        // Route-model-binding misses arrive wrapped in a NotFoundHttpException
        // whose message would leak the model class — unwrap them.
        if ($e instanceof ModelNotFoundException
            || ($e instanceof HttpExceptionInterface && $e->getPrevious() instanceof ModelNotFoundException)) {
            return self::response(404, 'Not found', 'The requested resource does not exist.');
        }

        if ($e instanceof AuthenticationException) {
            return self::response(401, 'Unauthorized', 'A valid API key is required.');
        }

        // Row-permission denials (spec 011 FR-011/FR-023): thrown by
        // BaseModel's write gates before any DB write happens.
        if ($e instanceof AuthorizationException) {
            $detail = $e->getMessage() !== '' ? $e->getMessage() : 'You do not have permission to perform this action.';

            return self::response(403, 'Forbidden', $detail);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';

            return self::response($status, $title, $e->getMessage() !== '' ? $e->getMessage() : null);
        }

        $detail = config('app.debug') ? $e->getMessage() : null;

        return self::response(500, 'Internal server error', $detail);
    }
}
