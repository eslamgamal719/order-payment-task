<?php

namespace App\Http\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

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

    public static function paginatedResponse(ResourceCollection $resource, LengthAwarePaginator $paginator): JsonResponse
    {
        return self::customResponse($resource, 200, [
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }
}
