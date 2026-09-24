<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\MaxJsonBodySize;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);

        $middleware->alias([
            'approved' => EnsureUserIsApproved::class,
            'max.json.body' => MaxJsonBodySize::class,
        ]);
    })
    ->withExceptions()->create();
