<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Builds the standard API envelope (SPEC §9.1) so controllers and the exception
 * handler emit one consistent shape:
 *   success: { success:true, data, meta?, message? }
 *   error:   { success:false, error:{ code, message, details? } }
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        ?array $meta = null,
        int $status = 200,
    ): JsonResponse {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    /**
     * Wrap a paginator, mapping items through a JsonResource and emitting the
     * required pagination meta.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>  $resource
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $resource,
        ?string $message = null,
    ): JsonResponse {
        return self::success(
            data: $resource::collection($paginator->items()),
            message: $message,
            meta: [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['success' => false, 'error' => $error], $status);
    }

    /**
     * Map any exception onto the error envelope with a consistent status/code.
     */
    public static function fromException(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::error(
                'VALIDATION_ERROR',
                'Doğrulama başarısız oldu.',
                422,
                $e->errors(),
            ),
            $e instanceof AuthenticationException => self::error(
                'UNAUTHENTICATED',
                'Kimlik doğrulaması gerekli.',
                401,
            ),
            $e instanceof AuthorizationException => self::error(
                'FORBIDDEN',
                'Bu işlem için yetkiniz yok.',
                403,
            ),
            $e instanceof ModelNotFoundException => self::error(
                'NOT_FOUND',
                'Kayıt bulunamadı.',
                404,
            ),
            $e instanceof HttpExceptionInterface => self::error(
                self::codeForStatus($e->getStatusCode()),
                $e->getMessage() ?: 'İstek işlenemedi.',
                $e->getStatusCode(),
            ),
            default => self::error(
                'INTERNAL_ERROR',
                config('app.debug') ? $e->getMessage() : 'Beklenmeyen bir hata oluştu.',
                500,
            ),
        };
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            429 => 'RATE_LIMITED',
            default => 'HTTP_ERROR',
        };
    }
}
