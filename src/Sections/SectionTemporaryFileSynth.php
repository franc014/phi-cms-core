<?php

namespace JFA\PhiCmsCore\Sections;

use JFA\PhiCmsCore\Pages\PageTemporaryUploads;
use Livewire\Features\SupportFileUploads\FileUploadSynth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class SectionTemporaryFileSynth extends FileUploadSynth
{
    public function hydrate(mixed $value): mixed
    {
        $file = parent::hydrate($value);
        $this->authorizeFiles($file);

        return $file;
    }

    public function dehydrate(mixed $target): array
    {
        $this->authorizeFiles($target);

        return parent::dehydrate($target);
    }

    private function authorizeFiles(mixed $value): void
    {
        foreach (is_array($value) ? $value : [$value] as $file) {
            if ($file instanceof TemporaryUploadedFile && str_starts_with($file->getFilename(), 'phi-')) {
                $filename = $file->getFilename();
                $editor = $this->context->component->getId();
                $pageUploads = app(PageTemporaryUploads::class);

                if ($pageUploads->hasFile($filename)) {
                    $pageUploads->file($filename, false, $editor);

                    continue;
                }

                app(SectionTemporaryUploads::class)->file($filename, false, $editor);
            }
        }
    }
}
