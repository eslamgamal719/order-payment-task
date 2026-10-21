<?php

namespace App\Http\Helpers;

use Illuminate\Http\JsonResponse;

class ApiHelper
{
    public static function customResponse(mixed $result = null, int $status = 200, array $additional = []): JsonResponse
    {
        $success = $status >= 200 && $status < 300;
        $key = $success ? 'result' : 'error';

        if (is_string($result)) {
            $result = ['message' => $result];
        }

        return response()->json(array_merge([
            'status' => $status,
            'success' => $success,
            $key => $result,
        ], $additional), $status);
    }
}
