<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Sections\SectionImages;
use RuntimeException;
use Throwable;

final class GalleryDeleteMediaCommand extends Command
{
    protected $signature = 'phi-cms:gallery-delete-media
        {uuid : UUID of the immutable private Gallery image}
        {--dry-run : Report usages without changing rows or files}
        {--confirm : Confirm operator-only physical deletion}';

    protected $description = 'Physically delete an unreferenced private Gallery image after guarded operator confirmation';

    public function handle(SectionImages $images): int
    {
        try {
            if (! $this->option('confirm') && ! $this->option('dry-run')) {
                throw new RuntimeException('Pass --confirm to authorize operator-only physical deletion.');
            }

            $uuid = (string) $this->argument('uuid');
            if (! Str::isUuid($uuid)) {
                throw new RuntimeException('The image identifier must be a UUID.');
            }

            $media = Media::query()
                ->where('uuid', $uuid)
                ->where('collection_name', config('phi-cms.images.collection', 'phi-section-images'))
                ->first();

            if ($media === null) {
                $this->info('Gallery image is already absent.');

                return self::SUCCESS;
            }

            if ($media->disk !== config('phi-cms.images.disk', 'phi-cms-images')) {
                throw new RuntimeException('Only images on the private PHI Gallery disk may be deleted.');
            }

            if ($this->option('dry-run')) {
                $this->line(json_encode(['uuid' => $uuid, 'usages' => $images->usages($media), 'tombstoned_at' => $media->getCustomProperty('phi_tombstoned_at')], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $images->delete($media);

            $this->info('Gallery image deleted.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
