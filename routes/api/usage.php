<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Usage module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Read-only usage statistics (spec 017), available to every valid API key
| and scoped by the spec 011 read predicate — deliberately NOT inside a
| scope.admin group. Ordering rule (constitution Principle IV): the literal
| `usage/summary` and every `…/{id}/traffic` route are registered before the
| matching `…/{id}` route; ids are numeric.
*/
