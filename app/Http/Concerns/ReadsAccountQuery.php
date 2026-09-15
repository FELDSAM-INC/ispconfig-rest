<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Query parameters of the account description endpoints (spec 021 research
 * R7): unknown parameters are 400, `client_id` and other ids must be positive
 * integers (422), as in UsageSummaryController.
 */
trait ReadsAccountQuery
{
    /**
     * @param  array<int, string>  $allowed
     */
    protected function rejectUnknownParameters(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->query()), $allowed);

        if ($unknown !== []) {
            throw new BadRequestHttpException(sprintf(
                "Unknown parameter '%s'. Allowed: %s.",
                implode("', '", $unknown),
                implode(', ', $allowed)
            ));
        }
    }

    /**
     * A positive integer query parameter, or null when absent.
     */
    protected function positiveIdParameter(Request $request, string $name, string $label): ?int
    {
        $raw = $request->query($name);

        if ($raw === null) {
            return null;
        }

        if (! is_string($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 1) {
            throw ValidationException::withMessages([
                $name => "The {$label} must be a positive integer.",
            ]);
        }

        return (int) $raw;
    }
}
