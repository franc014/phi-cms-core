<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Pages\PageSeoImages;

it('accepts decoded supported originals and rejects unsupported uploads', function (): void {
    $images = app(PageSeoImages::class);
    $valid = UploadedFile::fake()->image('seo.jpg', 40, 40);

    $images->validateFile($valid->getRealPath());

    $invalid = UploadedFile::fake()->create('seo.txt', 1, 'text/plain');

    expect(fn () => $images->validateFile($invalid->getRealPath()))
        ->toThrow(ValidationException::class, 'Only decoded JPEG, PNG, and WebP images are accepted.');
});
