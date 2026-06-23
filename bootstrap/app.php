<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        // Кука UTM-атрибуции ставится web-роутом /go/{slug}, а читается
        // api-роутом чекаута. Исключаем её из шифрования, чтобы значение
        // (id метки) одинаково читалось в обеих middleware-группах.
        // См. docs/tasks/utm-tracking.md.
        $middleware->encryptCookies(except: [
            'utm_link_id',
        ]);

        //   Stateful API для Sanctum
//        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //  ОБРАБОТКА ИСКЛЮЧЕНИЙ ДЛЯ API
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ресурс не найден',
                ], 404);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не авторизован',
                ], 401);
            }
        });

        // HttpException-наследники (ThrottleRequestsException → 429,
        // AccessDeniedHttpException → 403, MethodNotAllowedHttpException → 405 и т.д.)
        // должны сохранять свой HTTP-статус, иначе ниже общий catchall
        // превратит их в 500. Регистрируем ПЕРЕД generic `\Exception`.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();
                $defaultMessages = [
                    403 => 'Доступ запрещён',
                    405 => 'Метод не поддерживается',
                    429 => 'Слишком много запросов. Попробуйте позже.',
                ];
                return response()->json([
                    'success' => false,
                    'message' => $defaultMessages[$status]
                        ?? ($e->getMessage() ?: 'Ошибка'),
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], $status);
            }
        });

        $exceptions->render(function (\Exception $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Произошла ошибка на сервере',
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], 500);
            }
        });
    })->create();
