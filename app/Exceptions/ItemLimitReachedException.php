<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a store has reached the catalog item quota of its active plan.
 *
 * Rendered as HTTP 403 with a stable machine-readable code so clients can show
 * a clear upgrade prompt.
 */
class ItemLimitReachedException extends Exception
{
    public function __construct(private readonly ?int $limit = null)
    {
        parent::__construct('Batas jumlah item pada paket Anda telah tercapai.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Batas jumlah item pada paket Anda telah tercapai.',
            'code' => 'item_limit_reached',
            'limit' => $this->limit,
        ], Response::HTTP_FORBIDDEN);
    }
}
