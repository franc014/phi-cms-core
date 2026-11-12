<?php

namespace JFA\PhiCmsCore\Pages;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Metatags;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Post;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Sections\MediaMutation;
use JFA\PhiCmsCore\Sections\SectionRegistry;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

final class PageSeoImages
{
    /** @var list<string> */
    private const IMAGE_KEYS = ['og_image', 'twitter_image'];

    public function assertPrivateDisk(string $diskName): void
    {
        if (config('media-library.media_model') !== Media::class) {
            throw new RuntimeException('Page SEO images require the guarded PHI Media model; custom models require explicit integration.');
        }
        $configuration = config('filesystems.disks.' . $diskName);
        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'local'
            || ($configuration['serve'] ?? false) || ($configuration['visibility'] ?? 'private') === 'public' || isset($configuration['url'])) {
            throw new RuntimeException('Page SEO images require a non-served private local disk.');
        }
        $path = rtrim((string) ($configuration['root'] ?? ''), DIRECTORY_SEPARATOR);
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Page SEO image storage requires an absolute local root.');
        }
        $root = realpath($path);
        if ($root === false) {
            $parent = realpath(dirname($path));
            if ($parent === false || is_link($path)) {
                throw new RuntimeException('Create and verify the private Page SEO image storage parent before enabling uploads.');
            }
            $root = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        $public = realpath(public_path());
        if (is_string($public) && ($root === $public || str_starts_with($root, $public . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Page SEO image originals must be outside the public root.');
        }
        foreach (config('filesystems.disks', []) as $disk) {
            if (($disk['driver'] ?? null) !== 'local' || ! (($disk['serve'] ?? false) || ($disk['visibility'] ?? null) === 'public' || isset($disk['url']))) {
                continue;
            }
            $servedRoot = realpath($disk['root'] ?? '');
            if (is_string($servedRoot) && ($root === $servedRoot || str_starts_with($root, $servedRoot . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Page SEO image storage must not be nested under another served disk.');
            }
        }
    }

    public function collectionName(): string
    {
        return (string) config('phi-cms.page_seo_images.collection', 'phi-page-seo-images');
    }

    public function diskName(): string
    {
        return (string) config('phi-cms.page_seo_images.disk', config('phi-cms.images.disk', 'phi-cms-images'));
    }

    public function allowsCurrentReference(string $reference): bool
    {
        try {
            $page = app(PageSeoImageContext::class)->authorizeRead();

            return $this->allowsReference($page, $reference);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{name: string, size: int, type: string|null, url: string}|null */
    public function metadata(string $reference): ?array
    {
        $page = app(PageSeoImageContext::class)->authorizeRead();

        if (str_starts_with($reference, 'media:')) {
            $media = $this->mediaForReference($page, $reference);
            if ($media === null || ! $this->allowsReference($page, $reference)) {
                return null;
            }

            return [
                'name' => $media->file_name,
                'size' => (int) $media->size,
                'type' => $media->mime_type,
                'url' => route('phi-cms.page-images.preview', ['page' => $page, 'uuid' => $media->uuid]),
            ];
        }

        if (! $this->allowsReference($page, $reference)) {
            return null;
        }

        return [
            'name' => basename(parse_url($reference, PHP_URL_PATH) ?: $reference),
            'size' => 0,
            'type' => null,
            'url' => asset('storage/' . $reference),
        ];
    }

    public function retain(UploadedFile $file): string
    {
        $page = app(PageSeoImageContext::class)->authorizeUpload();

        try {
            $this->assertPrivateDisk($this->diskName());
        } catch (RuntimeException $exception) {
            report($exception);

            throw ValidationException::withMessages(['pageData.metatags.og_image' => 'Private image storage is unavailable. The existing SEO image has not changed.']);
        }

        $candidate = [
            'actor' => (string) auth()->id(),
            'session' => hash('sha256', session()->getId()),
            'page' => $page->getKey(),
        ];
        if ($file instanceof TemporaryUploadedFile) {
            $candidate = app(PageTemporaryUploads::class)->file($file->getFilename(), true);
            abort_unless((int) app(PageSeoImageContext::class)->page()->getKey() === (int) $page->getKey(), 403);
        }

        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages(['pageData.metatags.og_image' => 'The image upload is no longer available. Retry the upload.']);
        }

        $this->validateFile($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['pageData.metatags.og_image' => 'Only JPEG, PNG, and WebP images are accepted.']),
        };

        try {
            return DB::transaction(function () use ($page, $file, $extension, $candidate): string {
                $lockedPage = Page::query()->lockForUpdate()->find($page->getKey());
                if ($lockedPage === null || $lockedPage->page_version === null) {
                    throw ValidationException::withMessages(['pageData.metatags.og_image' => 'The Page is no longer available. The existing SEO image has not changed.']);
                }

                app(PageSeoImageContext::class)->set($lockedPage, preview: true);
                $authorizedPage = app(PageSeoImageContext::class)->authorizeUpload();
                abort_unless((int) $authorizedPage->getKey() === (int) $lockedPage->getKey(), 403);

                $media = MediaMutation::run(fn (): \Spatie\MediaLibrary\MediaCollections\Models\Media => $lockedPage
                    ->addMedia($file)
                    ->usingFileName(Str::uuid()->toString() . '.' . $extension)
                    ->withCustomProperties([
                        'phi_first_published_at' => null,
                        'phi_tombstoned_at' => null,
                        'phi_candidate' => $candidate,
                    ])
                    ->toMediaCollection($this->collectionName(), $this->diskName()));

                return 'media:' . $media->uuid;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['pageData.metatags.og_image' => 'The image could not be stored. The public Page has not changed. Retry the upload.']);
        }
    }

    /** @param array<string, mixed>|null $metatags */
    public function validateMetadata(Page $page, ?array $metatags): void
    {
        if ($metatags === null) {
            return;
        }

        foreach (self::IMAGE_KEYS as $key) {
            $reference = $metatags[$key] ?? null;
            if ($reference === null || $reference === '') {
                continue;
            }
            if (! is_string($reference)) {
                throw ValidationException::withMessages(["fields.metatags.{$key}" => 'SEO image selections must be strings or null.']);
            }
            if (str_starts_with($reference, 'media:')) {
                $media = $this->mediaForReference($page, $reference);
                if ($media === null || $media->getCustomProperty('phi_tombstoned_at') || ! $this->hasOriginal($media)) {
                    throw ValidationException::withMessages(["fields.metatags.{$key}" => 'The selected SEO image is unavailable. Retry the upload.']);
                }
                if (! $this->allowsReference($page, $reference)) {
                    throw ValidationException::withMessages(["fields.metatags.{$key}" => 'This SEO image is not available to this Page.']);
                }
            } elseif (! $this->referenceInMetadata($page, $reference)) {
                throw ValidationException::withMessages(["fields.metatags.{$key}" => 'Legacy SEO image references may only be retained or cleared. Upload a replacement image instead.']);
            }
        }
    }

    /** @param array<string, mixed>|null $metatags */
    public function markPublished(Page $page, ?array $metatags): void
    {
        $ids = $this->mediaIds($page, $metatags);
        if ($ids === []) {
            return;
        }

        $now = now()->toISOString();
        foreach (Media::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $media) {
            $properties = $media->custom_properties;
            $properties['phi_first_published_at'] ??= $now;
            DB::table('media')->where('id', $media->getKey())->update([
                'custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function mediaForReference(Page $page, string $reference): ?Media
    {
        $uuid = Str::after($reference, 'media:');
        if (! str_starts_with($reference, 'media:') || ! Str::isUuid($uuid)) {
            return null;
        }

        return Media::query()
            ->where('uuid', $uuid)
            ->where('model_type', $page->getMorphClass())
            ->where('model_id', $page->getKey())
            ->where('collection_name', $this->collectionName())
            ->first();
    }

    /** @return list<Media> */
    public function lockPage(Page $page, ?array $incomingMetatags = null): array
    {
        $uuids = [];
        foreach ([...$this->pageMetadataSnapshots($page), $incomingMetatags] as $snapshot) {
            foreach ($this->referencesFromMetadata(is_array($snapshot) ? $snapshot : null) as $reference) {
                if (str_starts_with($reference, 'media:')) {
                    $uuids[] = Str::after($reference, 'media:');
                }
            }
        }
        if ($uuids === []) {
            return [];
        }

        return Media::query()
            ->whereIn('uuid', array_values(array_unique($uuids)))
            ->where('collection_name', $this->collectionName())
            ->where('model_type', $page->getMorphClass())
            ->where('model_id', $page->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    public function lockOwnerMedia(Page $page): void
    {
        Media::query()
            ->where('model_type', $page->getMorphClass())
            ->where('model_id', $page->getKey())
            ->where('collection_name', $this->collectionName())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function assertOwnerDeleteAllowed(Page $page): void
    {
        $hasMedia = Media::query()
            ->where('model_type', $page->getMorphClass())
            ->where('model_id', $page->getKey())
            ->where('collection_name', $this->collectionName())
            ->exists();

        if ($hasMedia) {
            throw ValidationException::withMessages(['page' => 'Release all Page SEO images before deleting this Page.']);
        }
    }

    /** @return array{media_uuid: string, page_id: int, page_title: string, page_slug: string, page_version: int, reference: string, fields: list<string>} */
    public function inspectUnpublishedOwnerReference(Media $media): array
    {
        return $this->unpublishedOwnerReference($media, clear: false);
    }

    /** @return array{media_uuid: string, page_id: int, page_title: string, page_slug: string, page_version: int, reference: string, fields: list<string>} */
    public function releaseUnpublishedOwnerReference(Media $media): array
    {
        return $this->unpublishedOwnerReference($media, clear: true);
    }

    /** @return array{media_uuid: string, page_id: int, page_title: string, page_slug: string, page_version: int, reference: string, fields: list<string>} */
    private function unpublishedOwnerReference(Media $media, bool $clear): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Page SEO owner-reference release requires an independent committed transaction.');
        }

        return DB::transaction(function () use ($media, $clear): array {
            $locked = Media::query()->lockForUpdate()->findOrFail($media->getKey());
            if ($locked->collection_name !== $this->collectionName() || $locked->disk !== $this->diskName()) {
                throw new RuntimeException('Only private PHI Page SEO images support owner-reference release.');
            }
            if ($locked->model_type !== (new Page)->getMorphClass()) {
                throw ValidationException::withMessages(['media' => 'Only Page-owned SEO images support owner-reference release.']);
            }

            $page = Page::query()->lockForUpdate()->find($locked->model_id);
            if ($page === null || $page->page_version === null) {
                throw ValidationException::withMessages(['media' => 'Legacy or unowned Page SEO media is protected from owner-reference release.']);
            }
            if ($page->status === PageStatus::PUBLISHED) {
                throw ValidationException::withMessages(['media' => 'Published Pages cannot release retained SEO references.']);
            }

            $reference = 'media:' . $locked->uuid;
            $aliases = $this->aliases($locked);
            $draft = is_array($page->draft_fields) ? data_get($page->draft_fields, 'metatags') : null;
            foreach (self::IMAGE_KEYS as $key) {
                if ($this->containsReference(is_array($draft) ? ($draft[$key] ?? null) : null, $aliases)) {
                    throw ValidationException::withMessages(['media' => "The Page draft still selects this SEO image in {$key}. Save a different image or clear the draft before releasing the retained reference."]);
                }
            }

            $metatagsRows = Metatags::query()
                ->where('metatable_type', (new Page)->getMorphClass())
                ->where('metatable_id', $page->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $fields = [];
            $updates = [];
            foreach ($metatagsRows as $row) {
                foreach (self::IMAGE_KEYS as $key) {
                    if ($this->containsReference($row->getAttribute($key), $aliases)) {
                        $fields[] = $key;
                        $updates[$row->getKey()][$key] = null;
                    }
                }
            }
            $fields = array_values(array_unique($fields));
            sort($fields, SORT_STRING);
            if ($fields === []) {
                throw ValidationException::withMessages(['media' => 'The unpublished Page has no retained published reference to this SEO image.']);
            }

            $plan = [
                'media_uuid' => (string) $locked->uuid,
                'page_id' => (int) $page->getKey(),
                'page_title' => (string) $page->title,
                'page_slug' => (string) $page->slug,
                'page_version' => (int) $page->page_version,
                'reference' => $reference,
                'fields' => $fields,
            ];
            if (! $clear) {
                return $plan;
            }

            foreach ($updates as $rowId => $values) {
                DB::table('metatags')->where('id', $rowId)->update([...$values, 'updated_at' => now()]);
            }
            DB::table('pages')->where('id', $page->getKey())->update([
                'page_version' => (int) $page->page_version + 1,
                'updated_at' => now(),
            ]);

            return $plan;
        });
    }

    /** @return list<string> */
    public function referenceLockSet(Page $page): array
    {
        $references = [];
        foreach ($this->pageMetadataSnapshots($page) as $snapshot) {
            foreach ($this->referencesFromMetadata(is_array($snapshot) ? $snapshot : null) as $reference) {
                if (str_starts_with($reference, 'media:')) {
                    $references[] = $reference;
                }
            }
        }

        $references = array_values(array_unique($references));
        sort($references, SORT_STRING);

        return $references;
    }

    /** @param array<string, mixed>|null $metatags */
    public function urls(Page $page, ?array $metatags, bool $preview): array
    {
        $urls = [];
        foreach (self::IMAGE_KEYS as $key) {
            $reference = $metatags[$key] ?? null;
            if (! is_string($reference) || ! str_starts_with($reference, 'media:')) {
                continue;
            }
            $media = $this->mediaForReference($page, $reference);
            if ($media === null || $media->getCustomProperty('phi_tombstoned_at')) {
                continue;
            }
            $urls[$key] = $preview
                ? route('phi-cms.page-images.preview', ['page' => $page, 'uuid' => $media->uuid])
                : route('phi-cms.page-images.public', ['uuid' => $media->uuid]);
        }

        return $urls;
    }

    public function publicPath(Media $media): string
    {
        if ($media->disk !== $this->diskName() || $media->collection_name !== $this->collectionName()
            || $media->model_type !== (new Page)->getMorphClass()
            || ! $media->getCustomProperty('phi_first_published_at') || $media->getCustomProperty('phi_tombstoned_at')) {
            abort(404);
        }

        $path = $media->getPath();
        abort_unless(is_file($path) && is_readable($path), 404);

        return $path;
    }

    public function privatePath(Page $page, Media $media): string
    {
        abort_unless($media->disk === $this->diskName(), 404);
        abort_unless($media->model_type === $page->getMorphClass() && (int) $media->model_id === (int) $page->getKey(), 404);
        abort_unless($media->collection_name === $this->collectionName(), 404);
        abort_unless(! $media->getCustomProperty('phi_tombstoned_at'), 404);
        abort_unless($this->allowsReference($page, 'media:' . $media->uuid), 404);

        $path = $media->getPath();
        abort_unless(is_file($path) && is_readable($path), 404);

        return $path;
    }

    /**
     * Inventory every known reference before declaring a Page SEO file unused.
     *
     * @return list<array{page_id: int|null, owner_type: string, owner_id: int|null, field: string}>
     */
    public function usages(Media $media): array
    {
        $aliases = $this->aliases($media);
        $usages = [];
        $pageType = (new Page)->getMorphClass();
        $postType = (new Post)->getMorphClass();

        foreach (Page::query()->select(['id', 'draft_fields'])->orderBy('id')->get() as $page) {
            foreach (self::IMAGE_KEYS as $key) {
                if ($this->containsReference(data_get($page->draft_fields, 'metatags.' . $key), $aliases)) {
                    $this->addUsage($usages, $pageType, (int) $page->getKey(), 'draft_fields.metatags.' . $key, (int) $page->getKey());
                }
            }
        }

        foreach (Metatags::query()->orderBy('id')->get() as $metatags) {
            $ownerType = (string) $metatags->getAttribute('metatable_type');
            $ownerId = (int) $metatags->getAttribute('metatable_id');
            if (! in_array($ownerType, [$pageType, $postType], true)) {
                $this->addUsage($usages, $ownerType, $ownerId, 'unknown-inventory');

                continue;
            }

            foreach (self::IMAGE_KEYS as $key) {
                if ($this->containsReference($metatags->getAttribute($key), $aliases)) {
                    $this->addUsage(
                        $usages,
                        $ownerType,
                        $ownerId,
                        'metatags.' . $key,
                        $ownerType === $pageType ? $ownerId : null,
                    );
                }
            }
        }

        if (Schema::hasTable('posts')) {
            foreach (Post::query()->select(['id', 'content'])->orderBy('id')->get() as $post) {
                if ($this->containsReference($post->content, $aliases)) {
                    $this->addUsage($usages, $postType, (int) $post->getKey(), 'content');
                }
            }
        }

        if (Schema::hasTable('sections')) {
            foreach (Section::query()
                ->select(['id', 'type', 'content_version', 'content', 'draft_content', 'published_content', 'legacy_image_sources'])
                ->orderBy('id')
                ->get() as $section) {
                foreach (['content', 'draft_content', 'published_content', 'legacy_image_sources'] as $field) {
                    if ($this->containsReference($section->getAttribute($field), $aliases)) {
                        $this->addUsage($usages, (new Section)->getMorphClass(), (int) $section->getKey(), $field);
                    }
                }

                try {
                    $definition = app(SectionRegistry::class)->get((string) $section->type);
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
                    $this->addUsage($usages, (new Section)->getMorphClass(), (int) $section->getKey(), 'incomplete-inventory');
                }
            }
        }

        foreach (Media::query()->whereKeyNot($media->getKey())->where('disk', $media->disk)->orderBy('id')->get() as $other) {
            if ($this->containsReference($other->getPath(), $aliases)
                || $this->containsReference($other->getPathRelativeToRoot(), $aliases)
                || $this->containsReference($other->getUrl(), $aliases)) {
                $this->addUsage($usages, (string) $other->model_type, (int) $other->model_id, 'media.' . $other->getKey());
            }
        }

        return $usages;
    }

    public function delete(Media $media): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Page SEO image cleanup requires an independent committed tombstone transaction.');
        }

        $tombstoned = DB::transaction(function () use ($media): Media {
            $locked = Media::query()->lockForUpdate()->findOrFail($media->getKey());
            if ($locked->collection_name !== $this->collectionName() || $locked->disk !== $this->diskName()) {
                throw new RuntimeException('Only private PHI Page SEO images may be cleaned up.');
            }
            $owner = $locked->model_type === (new Page)->getMorphClass()
                ? Page::query()->lockForUpdate()->find($locked->model_id)
                : null;
            if ($owner === null || $owner->page_version === null) {
                throw ValidationException::withMessages(['media' => 'Legacy or unowned Page SEO media is protected from physical deletion.']);
            }
            if ($this->usages($locked) !== []) {
                throw ValidationException::withMessages(['media' => 'The Page SEO image is still referenced.']);
            }
            if ($owner->status === PageStatus::PUBLISHED) {
                throw ValidationException::withMessages(['media' => 'Published Page SEO media is protected from physical deletion.']);
            }

            $properties = $locked->custom_properties;
            $properties['phi_tombstoned_at'] ??= now()->toISOString();
            $properties['phi_cleanup_attempts'] = ($properties['phi_cleanup_attempts'] ?? 0) + 1;
            $properties['phi_cleanup_error'] = null;
            DB::table('media')->where('id', $locked->getKey())->update([
                'custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

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
                throw new RuntimeException('Page SEO image cleanup failed; retry the command after correcting storage access.');
            }
            if ($disk->exists($path)) {
                throw new RuntimeException('Page SEO image cleanup could not be verified.');
            }
            DB::table('media')->where('id', $tombstoned->getKey())->delete();
        } catch (Throwable $exception) {
            $properties = $tombstoned->custom_properties;
            $properties['phi_cleanup_error'] = $exception::class;
            DB::table('media')->where('id', $tombstoned->getKey())->update([
                'custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            ]);

            throw $exception;
        }
    }

    public function validateFile(string $path): void
    {
        $maxBytes = (int) config('phi-cms.images.max_bytes', 3 * 1024 * 1024);
        if (filesize($path) > $maxBytes) {
            throw ValidationException::withMessages(['pageData.metatags.og_image' => 'Images must be 3 MiB or smaller.']);
        }

        $info = @getimagesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_array($info) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['pageData.metatags.og_image' => 'Only decoded JPEG, PNG, and WebP images are accepted.']);
        }

        $maxDimension = (int) config('phi-cms.images.max_dimension', 8192);
        if ($info[0] > $maxDimension || $info[1] > $maxDimension) {
            throw ValidationException::withMessages(['pageData.metatags.og_image' => "Images must be {$maxDimension} × {$maxDimension} pixels or smaller."]);
        }
        if ($info['mime'] !== $mime || @imagecreatefromstring(file_get_contents($path)) === false) {
            throw ValidationException::withMessages(['pageData.metatags.og_image' => 'The image could not be decoded.']);
        }
    }

    private function allowsReference(Page $page, string $reference): bool
    {
        if (! str_starts_with($reference, 'media:')) {
            return $this->referenceInMetadata($page, $reference);
        }

        $media = $this->mediaForReference($page, $reference);
        if ($media === null || $media->getCustomProperty('phi_tombstoned_at')) {
            return false;
        }
        if ($this->referenceInMetadata($page, $reference)) {
            return true;
        }

        $candidate = $media->getCustomProperty('phi_candidate');

        return is_array($candidate)
            && ($candidate['actor'] ?? null) === (string) auth()->id()
            && ($candidate['session'] ?? null) === hash('sha256', session()->getId())
            && (int) ($candidate['page'] ?? 0) === (int) $page->getKey()
            && (! isset($candidate['editor']) || session('phi-cms.page-upload-editors.' . $candidate['editor']) === ($candidate['token'] ?? null));
    }

    private function referenceInMetadata(Page $page, string $reference): bool
    {
        foreach ($this->pageMetadataSnapshots($page) as $snapshot) {
            if (in_array($reference, $this->referencesFromMetadata(is_array($snapshot) ? $snapshot : null), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>|null> */
    private function pageMetadataSnapshots(Page $page): array
    {
        $draft = is_array($page->draft_fields) ? ($page->draft_fields['metatags'] ?? null) : null;
        $metatags = Metatags::query()
            ->where('metatable_type', (new Page)->getMorphClass())
            ->where('metatable_id', $page->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (Metatags $metatag): array => $metatag->toArray())
            ->all();

        return [is_array($draft) ? $draft : null, ...$metatags];
    }

    /** @param array<string, mixed>|null $metatags @return list<string> */
    private function referencesFromMetadata(?array $metatags): array
    {
        if ($metatags === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $key): mixed => $metatags[$key] ?? null, self::IMAGE_KEYS),
            fn (mixed $reference): bool => is_string($reference) && $reference !== '',
        ));
    }

    /** @param array<string, mixed>|null $metatags @return list<int> */
    private function mediaIds(Page $page, ?array $metatags): array
    {
        $ids = [];
        foreach ($this->referencesFromMetadata($metatags) as $reference) {
            $media = $this->mediaForReference($page, $reference);
            if ($media !== null) {
                $ids[] = (int) $media->getKey();
            }
        }

        return array_values(array_unique($ids));
    }

    private function hasOriginal(Media $media): bool
    {
        return $media->disk === $this->diskName() && is_file($media->getPath()) && is_readable($media->getPath());
    }

    /** @param list<string> $aliases */
    private function containsReference(mixed $value, array $aliases): bool
    {
        if (is_string($value)) {
            return in_array($value, $aliases, true);
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if ($this->containsReference($item, $aliases)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function aliases(Media $media): array
    {
        $aliases = [
            'media:' . $media->uuid,
            $media->getPath(),
            $media->getPathRelativeToRoot(),
            ltrim($media->getPathRelativeToRoot(), '/'),
        ];

        try {
            $aliases[] = $media->getUrl();
        } catch (Throwable) {
            // An unavailable disk is itself handled by the cleanup guards.
        }

        return array_values(array_unique(array_filter($aliases, is_string(...))));
    }

    /** @param list<array{page_id: int|null, owner_type: string, owner_id: int|null, field: string}> $usages */
    private function addUsage(array &$usages, string $ownerType, int $ownerId, string $field, ?int $pageId = null): void
    {
        $usages[] = [
            'page_id' => $pageId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'field' => $field,
        ];
    }
}
