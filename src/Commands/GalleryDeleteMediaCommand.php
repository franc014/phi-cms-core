<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Pages\PageSeoImages;
use JFA\PhiCmsCore\Sections\SectionImages;
use RuntimeException;
use Throwable;

final class GalleryDeleteMediaCommand extends Command
{
    protected $signature = 'phi-cms:delete-image
        {uuid : UUID of the immutable private Gallery or Page SEO image}
        {--dry-run : Report usages without changing rows or files}
        {--confirm : Confirm operator-only physical deletion}
        {--force : Bypass the interactive confirmation prompt}
        {--clear-unpublished-owner-reference : Clear matching retained published references on an unpublished Page before cleanup}';

    /** @var list<string> */
    protected $aliases = ['phi-cms:gallery-delete-media'];

    protected $description = 'Physically delete an unreferenced private Gallery or Page SEO image after guarded operator confirmation';

    public function handle(SectionImages $images, PageSeoImages $pageSeoImages): int
    {
        try {
            if (! $this->option('confirm') && ! $this->option('force') && ! $this->option('dry-run')) {
                throw new RuntimeException('Pass --confirm (or --force) to authorize operator-only physical deletion.');
            }

            $uuid = (string) $this->argument('uuid');
            if (! Str::isUuid($uuid)) {
                throw new RuntimeException('The image identifier must be a UUID.');
            }

            $media = Media::query()->where('uuid', $uuid)->first();

            if ($media === null) {
                $this->info('Gallery image is already absent.');

                return self::SUCCESS;
            }

            $isGalleryImage = $media->collection_name === config('phi-cms.images.collection', 'phi-section-images');
            $isPageSeoImage = $media->collection_name === $pageSeoImages->collectionName();
            if (! $isGalleryImage && ! $isPageSeoImage) {
                throw new RuntimeException('Only private PHI Gallery or Page SEO images may be deleted.');
            }
            if ($media->disk !== ($isPageSeoImage ? $pageSeoImages->diskName() : config('phi-cms.images.disk', 'phi-cms-images'))) {
                throw new RuntimeException('The image is not stored on its configured private PHI disk.');
            }
            if ($this->option('clear-unpublished-owner-reference') && ! $isPageSeoImage) {
                throw new RuntimeException('--clear-unpublished-owner-reference is only valid for Page SEO images.');
            }

            if ($this->option('dry-run')) {
                $report = [
                    'uuid' => $uuid,
                    'usages' => $isPageSeoImage ? $pageSeoImages->usages($media) : $images->usages($media),
                    'tombstoned_at' => $media->getCustomProperty('phi_tombstoned_at'),
                ];
                if ($this->option('clear-unpublished-owner-reference')) {
                    $report['owner_release'] = $pageSeoImages->inspectUnpublishedOwnerReference($media);
                }
                $this->line(json_encode($report, JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            if ($this->option('clear-unpublished-owner-reference')) {
                $release = $pageSeoImages->releaseUnpublishedOwnerReference($media);
                $this->line(json_encode(['owner_release' => $release], JSON_THROW_ON_ERROR));
                $pageSeoImages->delete($media->fresh());
                $this->info('Page SEO image owner reference released and image deleted.');
            } elseif ($isPageSeoImage) {
                $pageSeoImages->delete($media);
                $this->info('Page SEO image deleted.');
            } else {
                $images->delete($media);
                $this->info('Gallery image deleted.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
