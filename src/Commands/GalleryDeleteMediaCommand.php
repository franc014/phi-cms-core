<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Sections\SectionImages;
use RuntimeException;
use Throwable;

final class GalleryDeleteMediaCommand extends Command
{
    protected $signature = 'phi-cms:gallery-delete-media
        {uuid : UUID of the immutable private Gallery image}
        {--confirm : Confirm operator-only physical deletion}';

    protected $description = 'Physically delete an unreferenced private Gallery image after guarded operator confirmation';

    public function handle(SectionImages $images): int
    {
        try {
            if (! $this->option('confirm')) {
                throw new RuntimeException('Pass --confirm to authorize operator-only physical deletion.');
            }

            $uuid = (string) $this->argument('uuid');
            if (! Str::isUuid($uuid)) {
                throw new RuntimeException('The image identifier must be a UUID.');
            }

            DB::transaction(function () use ($uuid, $images): void {
                $media = Media::query()
                    ->where('uuid', $uuid)
                    ->where('collection_name', config('phi-cms.images.collection', 'phi-section-images'))
                    ->lockForUpdate()
                    ->first();

                if ($media === null) {
                    throw new RuntimeException('Gallery image not found.');
                }

                if ($media->disk !== config('phi-cms.images.disk', 'phi-cms-images')) {
                    throw new RuntimeException('Only images on the private PHI Gallery disk may be deleted.');
                }

                $images->delete($media);
            });

            $this->info('Gallery image deleted.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
