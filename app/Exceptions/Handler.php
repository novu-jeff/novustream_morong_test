<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $exception)
    {
        // Determine HTTP status code
        $code = 500; // default for unexpected errors
        $message = 'Something went wrong!';

        if ($exception instanceof HttpExceptionInterface) {
            $code = $exception->getStatusCode();
            $message = $exception->getMessage() ?: $message;
        } elseif (method_exists($exception, 'getCode') && $exception->getCode() >= 400) {
            $code = $exception->getCode();
            $message = $exception->getMessage() ?: $message;
        } else {
            $message = $exception->getMessage() ?: $message;
        }

        // Map default messages for common HTTP codes
        $defaultMessages = [
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ];

        $message = $defaultMessages[$code] ?? $message;

        // Always use your minimal view
        return response()->view('errors.minimal', [
            'code' => $code,
            'title' => $message,
            'message' => $message,
        ], $code);
    }
}
