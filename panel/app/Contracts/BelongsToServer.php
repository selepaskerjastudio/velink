<?php

namespace App\Contracts;

use App\Models\Server;

/**
 * Implemented by any model whose records are scoped to a single server for
 * authorization purposes. EnforceServerScope resolves route-bound models
 * through this contract to decide which server a request touches.
 */
interface BelongsToServer
{
    public function owningServer(): ?Server;
}
