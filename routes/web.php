<?php

use Illuminate\Support\Facades\Route;
use JFA\PhiCmsCore\Http\Controllers\SectionImageController;

Route::middleware('web')->group(function (): void {
    Route::get('/phi-cms/images/{uuid}', [SectionImageController::class, 'servePublic'])
        ->whereUuid('uuid')
        ->name('phi-cms.images.public');

    Route::get('/phi-cms/sections/{section}/images/{uuid}/preview', [SectionImageController::class, 'preview'])
        ->whereUuid('uuid')
        ->middleware('auth')
        ->name('phi-cms.images.preview');
});
