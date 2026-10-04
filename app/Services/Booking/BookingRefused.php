<?php

namespace App\Services\Booking;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A booking rule said no; rendered as 422 with a machine-readable code. */
class BookingRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reasonCode], 422);
    }
}
