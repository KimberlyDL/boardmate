<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The single JSON envelope for successful API responses: {data, meta?, message?}.
 * Errors use Laravel's default {message, errors?} shape (see bootstrap/app.php).
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        $body = ['data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        if ($message !== null) {
            $body['message'] = $message;
        }

        return response()->json($body, $status);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::ok($data, $message, status: 201);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
