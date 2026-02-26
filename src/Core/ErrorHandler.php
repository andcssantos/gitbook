<?php

namespace App\Core;

use App\Http\Response;
use App\Utils\Config;
use Throwable;

class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
    }

    public static function handleException(Throwable $e): void
    {
        $debug = (bool) Config::get('app.debug', false);

        error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

        if ($debug) {
            Response::json([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);

            return;
        }

        Response::json([
            'success' => false,
            'error' => true,
            'message' => 'Internal Server Error',
        ], 500);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
}
