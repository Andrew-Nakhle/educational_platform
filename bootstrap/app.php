<?php

use App\Http\Middleware\CheckUserStatus;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'user.status' => CheckUserStatus::class,
        ]);

        // API guests get a JSON 401 instead of a redirect to the
        // (non-existent) login route, even without an Accept header.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // findOrFail() and route model binding: "Lesson not found." like the
        // hand-written 404s, instead of "No query results for model [App\Models\…]".
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $previous = $e->getPrevious();

            if (! $previous instanceof ModelNotFoundException
                || ! ($request->is('api/*') || $request->expectsJson())
            ) {
                return null;
            }

            $model = Str::of(class_basename($previous->getModel()))
                ->snake(' ')
                ->ucfirst();

            return response()->json([
                'message' => "{$model} not found.",
            ], 404);
        });
    })->create();
