<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain conflict on an order or payment (e.g. cancelling an order that has
 * active payments, recording payment on a cancelled order, voiding an already
 * voided payment).
 *
 * Rendered as HTTP 409 with a stable code, distinct from validation errors.
 */
class OrderConflictException extends Exception
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'order_conflict',
    ) {
        parent::__construct($message);
    }

    /**
     * Stable machine-readable error code for clients and tests.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], Response::HTTP_CONFLICT);
    }
}
