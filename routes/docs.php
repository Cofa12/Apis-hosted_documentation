<?php

use Cofa\ApiDocs\Http\Controllers\DocumentationController;
use Illuminate\Support\Facades\Route;

$path = trim((string) config('api-docs.serve.path', 'api/documentation'), '/');

Route::get($path, [DocumentationController::class, 'index'])
    ->name((string) config('api-docs.serve.name', 'api-docs.index'));

Route::get($path . '.json', [DocumentationController::class, 'spec'])
    ->name((string) config('api-docs.serve.name', 'api-docs.index') . '.spec');
