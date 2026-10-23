<?php

namespace JFA\PhiCmsCore\Forms\Components;

use Filament\Forms\Components\FileUpload;
use Illuminate\Validation\Rules\File;
use JFA\PhiCmsCore\Sections\SectionImages;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class Image extends FileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->image()
            ->imageEditor()
            ->disk((string) config('phi-cms.images.disk', 'phi-cms-images'))
            ->visibility('private')
            ->fetchFileInformation(false)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize((int) config('phi-cms.images.max_kilobytes', 3072))
            ->rules([
                File::types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max((int) config('phi-cms.images.max_kilobytes', 3072)),
            ])
            ->preventFilePathTampering(
                allowFilePathUsing: fn (string $file): bool => app(SectionImages::class)->allowsCurrentReference($file),
            )
            ->getUploadedFileUsing(function (Image $component, string $file, string | array | null $storedFileNames): ?array {
                return app(SectionImages::class)->metadata($file);
            })
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                return app(SectionImages::class)->retain($file);
            })
            ->deleteUploadedFileUsing(function (string | TemporaryUploadedFile $file): void {
                // Removing a form value only removes the draft reference. The
                // retained immutable file is operator-managed and never deleted
                // as a FileUpload side effect.
            });
    }
}
