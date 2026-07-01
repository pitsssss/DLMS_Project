<?php

namespace App\Traits;

use App\Support\CitizenMessageTranslator;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function successResponse(mixed $data = null, string $message = 'messages.generic.success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => CitizenMessageTranslator::get($message),
            'data' => $data,
        ], $status);
    }

    protected function errorResponse(string $message = 'messages.generic.error', array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => CitizenMessageTranslator::get($message),
            'errors' => empty($errors) ? (object) [] : $errors,
        ], $status);
    }

    protected function employeeSuccessResponse(
        mixed $data = null,
        string $message = 'employee.generic.success',
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => EmployeeMessageTranslator::get($message),
            'data' => $data,
        ], $status);
    }

    protected function employeeErrorResponse(
        string $message = 'employee.generic.error',
        array $errors = [],
        int $status = 400
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => EmployeeMessageTranslator::get($message),
            'errors' => empty($errors) ? (object) [] : $errors,
        ], $status);
    }
}
