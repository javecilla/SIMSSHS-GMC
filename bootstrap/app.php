<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Lottery;
use Illuminate\Cache\RateLimiting\Limit;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Route::macro('versioned', function ($version, $path) {
            //     return Route::middleware("api.version:$version")
            //         ->prefix($version)
            //         ->name("api.$version.")
            //         ->group($path);
            // });
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // for things like sessions and CSRF
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        // runs on *all* routes
        $middleware->append([
            \App\Http\Middleware\HandleCors::class,
            // \App\Http\Middleware\EnsureTokenIsValid::class,
        ]);

        // Web-specific
        $middleware->web(append: [
            \App\Http\Middleware\HandleAppearance::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // API-specific
        $middleware->api(append: [
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\TransformApiResponse::class,
            \App\Http\Middleware\LogApiRequests::class,

            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        $middleware->alias([
            'api.version' => \App\Http\Middleware\ApiVersion::class,
        ]);

        // replaces Laravel's default stack
        $middleware->use([
            \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
            \Illuminate\Http\Middleware\TrustProxies::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Http\Middleware\ValidatePostSize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->stopIgnoring(\Illuminate\Http\Exceptions\HttpException::class);

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                if ($e instanceof \App\Exceptions\Api\BaseApiException) {
                    return $e->render($request);
                }

                if ($e instanceof \Illuminate\Database\QueryException) {
                    return response()->json([
                        'message' => 'A database error occurred.',
                        'error' => 'QueryException',
                        'debug' => config('app.debug') ? $e->getMessage() : null,
                    ], 500);
                }

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                    $previous = $e->getPrevious();
                    
                    if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                        $model = $previous->getModel();
                        $modelName = basename(str_replace('\\', '/', (is_object($model) ? get_class($model) : $model)));
                        // Get the ID that was searched for
                        $ids = $previous->getIds();
                        $id = is_array($ids) ? implode(', ', $ids) : $ids;
                        
                        return response()->json([
                            'message' => "{$modelName} with id '{$id}' is not found.",
                            'error' => 'ModelNotFoundException',
                            'debug' => config('app.debug') ? $e->getMessage() : null,
                        ], 404);
                    }
                    
                    return response()->json([
                        'message' => 'Resource not found.',
                        'error' => 'NotFoundHttpException',
                        'debug' => config('app.debug') ? $e->getMessage() : null,
                    ], 404);
                }
                
                return response()->json([
                    'message' => 'An error occurred.',
                    'error' => $e->getMessage(),
                ], 500);
            }
            
            return null;
        });
        

        $exceptions->throttle(function (Throwable $e) {
            return match (true) {
                $e instanceof \Illuminate\Broadcasting\BroadcastException => Limit::perMinute(300),
                $e instanceof \App\Exceptions\ApiMonitoringException => Lottery::odds(1, 1000),
                default => Limit::none(),
            };
        });
    })->create();
