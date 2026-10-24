<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Section;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

final class SectionImages
{
    public function assertPrivateDisk(string $diskName): void
    {
        if (config('media-library.media_model') !== Media::class) {
            throw new RuntimeException('Gallery requires the guarded PHI Media model; custom models require explicit integration.');
        }
        $configuration = config('filesystems.disks.' . $diskName);
        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'local'
            || ($configuration['serve'] ?? false) || ($configuration['visibility'] ?? 'private') === 'public' || isset($configuration['url'])) {
            throw new RuntimeException('Gallery requires a non-served private local disk.');
        }
        $path = rtrim((string) ($configuration['root'] ?? ''), DIRECTORY_SEPARATOR);
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Gallery storage requires an absolute local root.');
        }
        $root = realpath($path);
        if ($root === false) {
            $parent = realpath(dirname($path));
            if ($parent === false || is_link($path)) {
                throw new RuntimeException('Create and verify the private Gallery storage parent before enabling uploads.');
            }
            $root = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        $public = realpath(public_path());
        if (is_string($public) && ($root === $public || str_starts_with($root, $public . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Gallery originals must be outside the public root.');
        }
        foreach (config('filesystems.disks', []) as $disk) {
            if (($disk['driver'] ?? null) !== 'local' || ! (($disk['serve'] ?? false) || ($disk['visibility'] ?? null) === 'public' || isset($disk['url']))) {
                continue;
            }
            $servedRoot = realpath($disk['root'] ?? '');
            if (is_string($servedRoot) && ($root === $servedRoot || str_starts_with($root, $servedRoot . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Gallery storage must not be nested under another served disk.');
            }
        }
    }

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
                'url' => route('phi-cms.images.preview', ['section' => $section, 'uuid' => $media->uuid, 'page' => app(SectionImageContext::class)->page()?->getKey()]),
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

        try {
            $this->assertPrivateDisk($this->disk());
        } catch (RuntimeException $exception) {
            report($exception);

            throw ValidationException::withMessages(['content.image_url' => 'Private image storage is unavailable. The existing image has not changed.']);
        }
        $candidate = ['actor' => (string) auth()->id(), 'session' => hash('sha256', session()->getId()), 'page' => app(SectionImageContext::class)->page()?->getKey()];
        if ($file instanceof TemporaryUploadedFile) {
            $candidate = app(SectionTemporaryUploads::class)->file($file->getFilename(), true);
            abort_unless(app(SectionImageContext::class)->section()->getKey() === $section->getKey(), 403);
        }
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
            $media = MediaMutation::run(fn (): \Spatie\MediaLibrary\MediaCollections\Models\Media => $section
                ->addMedia($file)
                ->usingFileName(Str::uuid()->toString() . '.' . $extension)
                ->withCustomProperties(['phi_first_published_at' => null, 'phi_tombstoned_at' => null, 'phi_candidate' => $candidate])
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
            } elseif (! $this->legacyOriginalReady($section, $reference['reference'])) {
                throw ValidationException::withMessages([$reference['path'] => 'The adopted image original is unavailable or has changed. Restore the verified source before publishing.']);
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

    /** @param array<string, mixed>|null $incoming */
    public function lockSection(Section $section, ?array $incoming = null): Section
    {
        $before = Section::query()->findOrFail($section->getKey());
        $snapshots = [$before->draft_content, $before->published_content];
        $uuids = [];
        foreach ([...$snapshots, $incoming] as $snapshot) {
            foreach ($this->references($before, is_array($snapshot) ? $snapshot : []) as $reference) {
                if (str_starts_with($reference['reference'], 'media:')) {
                    $uuids[] = substr($reference['reference'], 6);
                }
            }
        }

        if ($uuids !== []) {
            Media::query()->whereIn('uuid', array_unique($uuids))->orderBy('id')->lockForUpdate()->get();
        }

        $locked = Section::query()->lockForUpdate()->findOrFail($section->getKey());
        if ($locked->content_version !== $before->content_version
            || [$locked->draft_content, $locked->published_content] !== $snapshots) {
            throw new SectionConflict;
        }

        return $locked;
    }

    /**
     * @return list<array{section_id: int, field: string}>
     */
    public function usages(Media $media): array
    {
        $aliases = array_unique(['media:' . $media->uuid, $media->getPath(), $media->getPathRelativeToRoot(), ltrim($media->getPathRelativeToRoot(), '/'), $media->getUrl()]);
        $usages = [];

        foreach (Section::query()
            ->select(['id', 'type', 'content_version', 'content', 'draft_content', 'published_content', 'legacy_image_sources'])
            ->orderBy('id')
            ->get() as $section) {
            foreach (['content', 'draft_content', 'published_content', 'legacy_image_sources'] as $field) {
                foreach ($aliases as $alias) {
                    if ($this->containsReference($section->getAttribute($field), $alias)) {
                        $usages[] = ['section_id' => (int) $section->getKey(), 'field' => $field];

                        break;
                    }
                }
            }

            try {
                $definition = app(SectionRegistry::class)->get($section->type);
                if ($section->content_version === null) {
                    $blocks = $section->content;
                    if (! is_array($blocks) || count($blocks) !== 1 || ($blocks[0]['type'] ?? null) !== $section->type || ! is_array($blocks[0]['data'] ?? null)) {
                        throw new RuntimeException('Unknown legacy envelope.');
                    }
                    $definition::adoptLegacy($blocks[0]['data']);
                } else {
                    $definition::normalize($section->draft_content ?? []);
                    $definition::normalize($section->published_content ?? []);
                }
            } catch (Throwable) {
                $usages[] = ['section_id' => (int) $section->getKey(), 'field' => 'incomplete-inventory'];
            }
        }

        foreach (Storage::disk('local')->allFiles('phi-cms/gallery') as $path) {
            try {
                $artifact = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
                foreach ($aliases as $alias) {
                    if ($this->containsReference($artifact, $alias)) {
                        $usages[] = ['section_id' => 0, 'field' => 'recovery:' . $path];

                        break;
                    }
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
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Image cleanup requires an independent committed tombstone transaction.');
        }

        $tombstoned = DB::transaction(function () use ($media): Media {
            $locked = Media::query()->lockForUpdate()->findOrFail($media->getKey());
            if ($locked->collection_name !== $this->collection() || $locked->disk !== $this->disk()) {
                throw new RuntimeException('Only private PHI images may be cleaned up.');
            }
            $owner = $locked->model_type === (new Section)->getMorphClass()
                ? Section::query()->lockForUpdate()->find($locked->model_id)
                : null;
            if ($owner === null || $owner->content_version === null) {
                throw ValidationException::withMessages(['media' => 'Legacy or unowned media is protected from physical deletion.']);
            }
            if ($this->usages($locked) !== []) {
                throw ValidationException::withMessages(['media' => 'The image is referenced or its usage inventory is incomplete.']);
            }

            $properties = $locked->custom_properties;
            $properties['phi_tombstoned_at'] ??= now()->toISOString();
            $properties['phi_cleanup_attempts'] = ($properties['phi_cleanup_attempts'] ?? 0) + 1;
            $properties['phi_cleanup_error'] = null;
            DB::table('media')->where('id', $locked->getKey())->update(['custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);

            return $locked->fresh();
        });

        try {
            $disk = Storage::disk($tombstoned->disk);
            $path = ltrim($tombstoned->getPathRelativeToRoot(), '/');
            $files = $disk->allFiles(dirname($path));
            if (array_diff($files, [$path]) !== []) {
                throw new RuntimeException('Unexpected files require operator inspection; the identity remains tombstoned.');
            }
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('Image cleanup failed; retry the command after correcting storage access.');
            }
            if ($disk->exists($path)) {
                throw new RuntimeException('Image cleanup could not be verified.');
            }
            DB::table('media')->where('id', $tombstoned->getKey())->delete();
        } catch (Throwable $exception) {
            $properties = $tombstoned->custom_properties;
            $properties['phi_cleanup_error'] = $exception::class;
            DB::table('media')->where('id', $tombstoned->getKey())->update(['custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);

            throw $exception;
        }
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
        abort_unless($this->allowsReference($section, 'media:' . $media->uuid), 404);

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

            if ($media === null || $media->getCustomProperty('phi_tombstoned_at')) {
                return false;
            }
            if ($this->referenceInSnapshot($section, $reference)) {
                return true;
            }
            $candidate = $media->getCustomProperty('phi_candidate');

            return is_array($candidate)
                && ($candidate['actor'] ?? null) === (string) auth()->id()
                && ($candidate['session'] ?? null) === hash('sha256', session()->getId())
                && ($candidate['page'] ?? null) === app(SectionImageContext::class)->page()?->getKey()
                && (! isset($candidate['editor']) || session('phi-cms.upload-editors.' . $candidate['editor']) === ($candidate['token'] ?? null));
        }

        if ($this->isExternalReference($reference)) {
            $binding = $this->legacyBinding($section, $reference);

            if ($binding === null) {
                // An intentional URL selected through the native editor has no
                // adoption binding. It is preserved byte-for-byte and is never
                // fetched by the server.
                return true;
            }

            return ($binding['kind'] ?? null) === 'external' && ($binding['url'] ?? null) === $reference;
        }

        return $this->legacyBinding($section, $reference) !== null || $this->referenceInSnapshot($section, $reference);
    }

    private function isExternalReference(string $reference): bool
    {
        return filter_var($reference, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($reference, PHP_URL_SCHEME), ['http', 'https'], true);
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

    private function legacyOriginalReady(Section $section, string $reference): bool
    {
        $binding = $this->legacyBinding($section, $reference);
        $isExternal = $this->isExternalReference($reference);

        if (($binding['kind'] ?? null) === 'external') {
            return $isExternal && ($binding['url'] ?? null) === $reference;
        }

        if ($binding === null) {
            // An intentional URL selected through the native editor has no
            // adoption binding. It is preserved byte-for-byte and never fetched.
            return $isExternal;
        }

        if (! in_array($binding['kind'] ?? null, ['disk', 'static'], true)
            || ! is_string($binding['root'] ?? null) || ! is_string($binding['physical_path'] ?? null)
            || ! is_string($binding['url'] ?? null) || ! is_string($binding['sha256'] ?? null)) {
            return false;
        }

        clearstatcache();
        $root = realpath($binding['root']);
        $path = realpath($binding['physical_path']);
        if ($root !== $binding['root'] || $path !== $binding['physical_path']
            || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR) || ! is_file($path) || ! is_readable($path)) {
            return false;
        }

        if ($binding['kind'] === 'disk') {
            $configuration = config('filesystems.disks.' . ($binding['disk'] ?? ''), []);
            if (($configuration['driver'] ?? null) !== 'local'
                || realpath($configuration['root'] ?? '') !== $root
                || ! is_string($binding['key'] ?? null)
                || realpath($root . DIRECTORY_SEPARATOR . $binding['key']) !== $path) {
                return false;
            }
        }

        $urlPath = parse_url($binding['url'], PHP_URL_PATH);

        return is_string($urlPath)
            && realpath(public_path(ltrim(rawurldecode($urlPath), '/'))) === $path
            && hash_file('sha256', $path) === $binding['sha256']
            && filesize($path) === ($binding['bytes'] ?? null);
    }

    private function hasOriginal(Media $media): bool
    {
        return $media->disk === $this->disk() && is_file($media->getPath()) && is_readable($media->getPath());
    }

    public function validateFile(string $path): void
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
        if ($info[0] > $maxDimension || $info[1] > $maxDimension) {
            throw ValidationException::withMessages(['content.image_url' => "Images must be {$maxDimension} × {$maxDimension} pixels or smaller."]);
        }
        if ($info['mime'] !== $mime || @imagecreatefromstring(file_get_contents($path)) === false) {
            throw ValidationException::withMessages(['content.image_url' => 'The image could not be decoded.']);
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
