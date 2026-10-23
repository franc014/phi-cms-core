<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Section;
use Throwable;

final class SectionImages
{
    /**
     * @param  array<string, mixed>  $content
     * @return list<array{path: string, reference: string, required: bool}>
     */
    public function references(Section $section, array $content): array
    {
        $definition = app(SectionRegistry::class)->get($section->type);

        return $definition::imageReferences($content);
    }

    public function allowsCurrentReference(string $reference): bool
    {
        try {
            $section = app(SectionImageContext::class)->authorizeRead();

            return $this->allowsReference($section, $reference);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{name: string, size: int, type: string|null, url: string}|null */
    public function metadata(string $reference): ?array
    {
        $section = app(SectionImageContext::class)->authorizeRead();

        if (str_starts_with($reference, 'media:')) {
            $media = $this->mediaForReference($section, $reference);

            if ($media === null || ! $this->allowsReference($section, $reference)) {
                return null;
            }

            return [
                'name' => $media->file_name,
                'size' => (int) $media->size,
                'type' => $media->mime_type,
                'url' => route('phi-cms.images.preview', ['section' => $section, 'uuid' => $media->uuid]),
            ];
        }

        if (! $this->allowsReference($section, $reference)) {
            return null;
        }

        $url = $this->legacyUrl($section, $reference);

        return [
            'name' => basename(parse_url($reference, PHP_URL_PATH) ?: $reference),
            'size' => 0,
            'type' => null,
            'url' => $url,
        ];
    }

    public function retain(UploadedFile $file): string
    {
        $section = app(SectionImageContext::class)->authorizeUpload();
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages(['content.image_url' => 'The image upload is no longer available. Retry the upload.']);
        }

        $this->validateFile($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['content.image_url' => 'Only JPEG, PNG, and WebP images are accepted.']),
        };

        try {
            $media = MediaMutation::run(fn (): Media => $section
                ->addMedia($file)
                ->usingFileName(Str::uuid()->toString() . '.' . $extension)
                ->withCustomProperties(['phi_first_published_at' => null, 'phi_tombstoned_at' => null])
                ->toMediaCollection($this->collection(), $this->disk()));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['content.image_url' => 'The image could not be stored. The public image has not changed. Retry the upload.']);
        }

        return 'media:' . $media->uuid;
    }

    /** @param array<string, mixed> $content */
    public function validateContent(Section $section, array $content): void
    {
        foreach ($this->references($section, $content) as $reference) {
            if ($reference['required'] && blank($reference['reference'])) {
                throw ValidationException::withMessages([$reference['path'] => 'An image is required.']);
            }

            if (blank($reference['reference'])) {
                continue;
            }

            if (! $this->allowsReference($section, $reference['reference'])) {
                throw ValidationException::withMessages([$reference['path'] => 'This image is not available to this Section.']);
            }

            if (str_starts_with($reference['reference'], 'media:')) {
                $media = $this->mediaForReference($section, $reference['reference']);
                if ($media === null || ! $this->hasOriginal($media)) {
                    throw ValidationException::withMessages([$reference['path'] => 'The selected image is unavailable. Retry the upload.']);
                }
            }
        }
    }

    /** @param array<string, mixed> $content */
    public function markPublished(Section $section, array $content): void
    {
        $mediaIds = [];
        foreach ($this->references($section, $content) as $reference) {
            if (! str_starts_with($reference['reference'], 'media:')) {
                continue;
            }

            $media = $this->mediaForReference($section, $reference['reference']);
            if ($media === null || ! $this->hasOriginal($media)) {
                throw ValidationException::withMessages([$reference['path'] => 'The selected image is unavailable.']);
            }

            $mediaIds[] = $media->getKey();
        }

        if ($mediaIds === []) {
            return;
        }

        $now = now()->toISOString();
        foreach (Media::query()->whereKey($mediaIds)->orderBy('id')->lockForUpdate()->get() as $media) {
            $properties = $media->custom_properties;
            $properties['phi_first_published_at'] ??= $now;
            DB::table('media')->where('id', $media->getKey())->update(['custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
    }

    public function mediaForReference(Section $section, string $reference): ?Media
    {
        $uuid = Str::after($reference, 'media:');

        if (! str_starts_with($reference, 'media:') || ! Str::isUuid($uuid)) {
            return null;
        }

        return Media::query()
            ->where('uuid', $uuid)
            ->where('model_type', $section->getMorphClass())
            ->where('model_id', $section->getKey())
            ->where('collection_name', $this->collection())
            ->first();
    }

    /** @return array<int, Media> */
    public function lockMediaForContent(Section $section, array $content): array
    {
        $ids = [];
        foreach ($this->references($section, $content) as $reference) {
            if (! str_starts_with($reference['reference'], 'media:')) {
                continue;
            }

            $media = $this->mediaForReference($section, $reference['reference']);
            if ($media !== null) {
                $ids[] = (int) $media->getKey();
            }
        }

        if ($ids === []) {
            return [];
        }

        return Media::query()->whereKey(array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get()->all();
    }

    /**
     * @return list<array{section_id: int, field: string}>
     */
    public function usages(Media $media): array
    {
        $reference = 'media:' . $media->uuid;
        $usages = [];

        foreach (Section::query()
            ->select(['id', 'content', 'draft_content', 'published_content', 'legacy_image_sources'])
            ->orderBy('id')
            ->get() as $section) {
            foreach (['content', 'draft_content', 'published_content', 'legacy_image_sources'] as $field) {
                if ($this->containsReference($section->getAttribute($field), $reference)) {
                    $usages[] = ['section_id' => (int) $section->getKey(), 'field' => $field];
                }
            }
        }

        foreach (Storage::disk('local')->files('phi-cms/gallery') as $path) {
            try {
                if ($this->containsReference(json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR), $reference)) {
                    $usages[] = ['section_id' => 0, 'field' => 'recovery:' . $path];
                }
            } catch (Throwable) {
                // An unrelated or incomplete recovery artifact does not grant
                // permission to delete a media object.
                $usages[] = ['section_id' => 0, 'field' => 'recovery:' . $path];
            }
        }

        return $usages;
    }

    public function delete(Media $media): void
    {
        if ($media->getCustomProperty('phi_first_published_at') !== null) {
            throw ValidationException::withMessages(['media' => 'Published Gallery images are immutable and cannot be physically deleted.']);
        }

        if ($media->getCustomProperty('phi_tombstoned_at') !== null) {
            throw ValidationException::withMessages(['media' => 'Tombstoned Gallery images remain protected for recovery.']);
        }

        $owner = $media->model_type === (new Section)->getMorphClass()
            ? Section::query()->find($media->model_id)
            : null;
        if ($owner === null || $owner->content_version === null) {
            throw ValidationException::withMessages(['media' => 'Legacy or unowned media is protected from physical deletion.']);
        }

        if ($this->usages($media) !== []) {
            throw ValidationException::withMessages(['media' => 'The image is still referenced by a draft, public snapshot, legacy source, or recovery artifact.']);
        }

        MediaMutation::run(static function () use ($media): void {
            $media->delete();
        });
    }

    public function publicPath(Media $media): string
    {
        if ($media->disk !== $this->disk() || ! $media->getCustomProperty('phi_first_published_at') || $media->getCustomProperty('phi_tombstoned_at')) {
            abort(404);
        }

        $path = $media->getPath();
        abort_unless(is_file($path) && is_readable($path), 404);

        return $path;
    }

    public function privatePath(Section $section, Media $media): string
    {
        abort_unless($media->disk === $this->disk(), 404);
        abort_unless($media->model_type === $section->getMorphClass() && (int) $media->model_id === (int) $section->getKey(), 404);
        abort_unless($media->collection_name === $this->collection(), 404);
        abort_unless(! $media->getCustomProperty('phi_tombstoned_at'), 404);

        $path = $media->getPath();
        abort_unless(is_file($path) && is_readable($path), 404);

        return $path;
    }

    /** @return array<string, mixed>|null */
    public function legacyBinding(Section $section, string $reference): ?array
    {
        foreach ($section->legacy_image_sources ?? [] as $binding) {
            if (is_array($binding) && ($binding['reference'] ?? null) === $reference) {
                return $binding;
            }
        }

        return null;
    }

    private function allowsReference(Section $section, string $reference): bool
    {
        if (str_starts_with($reference, 'media:')) {
            $media = $this->mediaForReference($section, $reference);

            return $media !== null && ! $media->getCustomProperty('phi_tombstoned_at');
        }

        if (filter_var($reference, FILTER_VALIDATE_URL) !== false && in_array(parse_url($reference, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $this->legacyBinding($section, $reference) !== null || $this->referenceInSnapshot($section, $reference);
        }

        return $this->legacyBinding($section, $reference) !== null || $this->referenceInSnapshot($section, $reference);
    }

    private function referenceInSnapshot(Section $section, string $reference): bool
    {
        foreach ([$section->draft_content, $section->published_content] as $snapshot) {
            foreach ($this->references($section, is_array($snapshot) ? $snapshot : []) as $item) {
                if ($item['reference'] === $reference) {
                    return true;
                }
            }
        }

        return false;
    }

    private function legacyUrl(Section $section, string $reference): string
    {
        $binding = $this->legacyBinding($section, $reference);

        return is_string($binding['url'] ?? null) ? $binding['url'] : $reference;
    }

    private function hasOriginal(Media $media): bool
    {
        return $media->disk === $this->disk() && is_file($media->getPath()) && is_readable($media->getPath());
    }

    private function validateFile(string $path): void
    {
        $maxBytes = (int) config('phi-cms.images.max_bytes', 3 * 1024 * 1024);
        if (filesize($path) > $maxBytes) {
            throw ValidationException::withMessages(['content.image_url' => 'Images must be 3 MiB or smaller.']);
        }

        $info = @getimagesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_array($info) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['content.image_url' => 'Only decoded JPEG, PNG, and WebP images are accepted.']);
        }

        $maxDimension = (int) config('phi-cms.images.max_dimension', 8192);
        if (($info[0] ?? 0) > $maxDimension || ($info[1] ?? 0) > $maxDimension) {
            throw ValidationException::withMessages(['content.image_url' => "Images must be {$maxDimension} × {$maxDimension} pixels or smaller."]);
        }
    }

    private function collection(): string
    {
        return (string) config('phi-cms.images.collection', 'phi-section-images');
    }

    private function disk(): string
    {
        return (string) config('phi-cms.images.disk', 'phi-cms-images');
    }

    private function containsReference(mixed $value, string $reference): bool
    {
        if (is_string($value)) {
            return $value === $reference;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->containsReference($item, $reference)) {
                return true;
            }
        }

        return false;
    }
}
