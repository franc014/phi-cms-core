<?php

use Illuminate\Support\Facades\Route;
use JFA\PhiCmsCore\Http\Controllers\PageSeoImageController;
use JFA\PhiCmsCore\Http\Controllers\SectionImageController;
use JFA\PhiCmsCore\Http\Controllers\SectionTemporaryUploadController;

Route::middleware('web')->group(function (): void {
    Route::post('/phi-cms/images/temporary/{ticket}', SectionTemporaryUploadController::class)
        ->whereUuid('ticket')->middleware('throttle:60,1')->name('phi-cms.images.temporary');

    Route::get('/phi-cms/images/{uuid}', [SectionImageController::class, 'servePublic'])
        ->whereUuid('uuid')
        ->name('phi-cms.images.public');

    Route::get('/phi-cms/pages/images/{uuid}', [PageSeoImageController::class, 'servePublic'])
        ->whereUuid('uuid')
        ->name('phi-cms.page-images.public');

    Route::get('/phi-cms/sections/{section}/images/{uuid}/preview', [SectionImageController::class, 'preview'])
        ->whereUuid('uuid')
        ->middleware('auth')
        ->name('phi-cms.images.preview');

    Route::get('/phi-cms/pages/{page}/images/{uuid}/preview', [PageSeoImageController::class, 'preview'])
        ->whereUuid('uuid')
        ->middleware('auth')
        ->name('phi-cms.page-images.preview');
});
