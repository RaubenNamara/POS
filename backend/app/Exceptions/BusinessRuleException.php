<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/** A request that is well-formed but breaks a business rule (insufficient stock, over-refund, ...). */
class BusinessRuleException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'business_rule', public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'details' => $this->details,
        ], 422);
    }
}
