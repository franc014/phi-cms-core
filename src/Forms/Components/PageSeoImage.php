<?php

namespace JFA\PhiCmsCore\Forms\Components;

use Filament\Forms\Components\FileUpload;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Pages\PageSeoImages;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class PageSeoImage extends FileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->image()
            ->imageEditor()
            ->disk((string) config('phi-cms.page_seo_images.disk', config('phi-cms.images.disk', 'phi-cms-images')))
            ->visibility('private')
            ->fetchFileInformation(false)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize((int) config('phi-cms.images.max_kilobytes', 3072))
            ->rules([
                File::types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max((int) config('phi-cms.images.max_kilobytes', 3072)),
            ])
            ->preventFilePathTampering(
                allowFilePathUsing: fn (string $file): bool => app(PageSeoImages::class)->allowsCurrentReference($file),
            )
            ->getUploadedFileUsing(function (PageSeoImage $component, string $file, string | array | null $storedFileNames): ?array {
                if (! str_starts_with($file, 'media:')) {
                    return null;
                }

                return app(PageSeoImages::class)->metadata($file);
            })
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, PageSeoImage $component): string {
                try {
                    return app(PageSeoImages::class)->retain($file);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$component->getStatePath() => collect($exception->errors())->flatten()->all()]);
                }
            })
            ->deleteUploadedFileUsing(function (string | TemporaryUploadedFile $file): void {
                // Removing a form value only removes the draft reference. The
                // retained immutable file is operator-managed and never deleted
                // as a FileUpload side effect.
            });
    }
}
