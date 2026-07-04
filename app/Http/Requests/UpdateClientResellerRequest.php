<?php

namespace App\Http\Requests;

/**
 * PUT /resellers/{id} (api/modules/client/resellers.yaml).
 *
 * Partial updates; demoting the reseller (limit_client = 0) is rejected
 * with 400 in the controller per the contract's wording.
 */
class UpdateClientResellerRequest extends UpdateClientRequest {}
