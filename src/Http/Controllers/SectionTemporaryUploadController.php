<?php

namespace JFA\PhiCmsCore\Http\Controllers;

use Illuminate\Support\Facades\Validator;
use JFA\PhiCmsCore\Sections\SectionImages;
use JFA\PhiCmsCore\Sections\SectionTemporaryUploads;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class SectionTemporaryUploadController
{
    /** @return array{paths: list<string>} */
    public function __invoke(string $ticket, SectionTemporaryUploads $uploads): array
    {
        abort_unless(request()->hasValidRelativeSignature(), 401);
        $binding = $uploads->ticket($ticket);
        $uploads->assertPrivateStorage();
        abort_if(FileUploadConfiguration::isUsingS3() || FileUploadConfiguration::isUsingGCS(), 403);
        Validator::make(request()->all(), [
            'files' => ['required', 'array', 'size:1'],
            'files.*' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:3072', 'dimensions:max_width=8192,max_height=8192'],
        ])->validate();
        $storage = FileUploadConfiguration::storage();
        $paths = [];
        foreach (request()->file('files') as $file) {
            app(SectionImages::class)->validateFile($file->getRealPath());
            $filename = 'phi-' . $ticket . '-' . TemporaryUploadedFile::generateHashName($file);
            $uploads->register($filename, $binding);
            $path = FileUploadConfiguration::path($filename);
            abort_unless($storage->put($path, $file->getContent(), 'private'), 503);
            abort_unless($storage->put($path . '.json', json_encode(['name' => 'image', 'type' => $file->getMimeType(), 'size' => $file->getSize()], JSON_THROW_ON_ERROR), 'private'), 503);
            $paths[] = TemporaryUploadedFile::signPath($filename);
        }

        return ['paths' => $paths];
    }
}
