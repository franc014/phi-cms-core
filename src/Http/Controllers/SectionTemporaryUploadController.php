<?php

namespace JFA\PhiCmsCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Sections\SectionImages;
use JFA\PhiCmsCore\Sections\SectionTemporaryUploads;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SectionTemporaryUploadController
{
    /** @return array{paths: list<string>}|JsonResponse */
    public function __invoke(string $ticket, SectionTemporaryUploads $uploads): array | JsonResponse
    {
        try {
            abort_unless(request()->hasValidRelativeSignature(), 401);
            $binding = $uploads->ticket($ticket);
            $uploads->assertPrivateStorage();
            abort_if(FileUploadConfiguration::isUsingS3() || FileUploadConfiguration::isUsingGCS(), 403);

            // FilePond does not request JSON and the host may force HTML error
            // rendering, so return upload failures directly as JSON. This keeps
            // the specific rejection visible in the editor instead of turning
            // it into an opaque redirect or generic upload error.
            $validator = Validator::make(request()->all(), [
                'files' => ['required', 'array', 'size:1'],
                'files.*' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:3072', 'dimensions:max_width=8192,max_height=8192'],
            ]);
            if ($validator->fails()) {
                return $this->invalid($validator->errors()->toArray());
            }

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
        } catch (ValidationException $exception) {
            return $this->invalid($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode(), $exception->getHeaders());
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function invalid(array $errors, ?string $message = null): JsonResponse
    {
        $first = $errors === [] ? null : reset($errors);
        $first = is_array($first) ? reset($first) : $first;

        return response()->json([
            'message' => $message ?? (is_string($first) ? $first : 'The image upload was rejected.'),
            'errors' => $errors,
        ], 422);
    }
}
