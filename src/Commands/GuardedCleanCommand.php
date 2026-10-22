<?php

namespace JFA\PhiCmsCore\Commands;

use JFA\PhiCmsCore\Sections\LegacySectionGuard;
use Spatie\MediaLibrary\MediaCollections\Commands\CleanCommand;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class GuardedCleanCommand extends CleanCommand
{
    protected function deleteConversionFilesForDeprecatedConversions(Media $media): void
    {
        if (LegacySectionGuard::conversionIsProtected($media)) {
            $this->warn("Skipped deprecated conversions for adoption/recovery-protected Media[id={$media->id}].");

            return;
        }

        parent::deleteConversionFilesForDeprecatedConversions($media);
    }

    protected function deleteDeprecatedResponsiveImages(Media $media): void
    {
        if (LegacySectionGuard::conversionIsProtected($media)) {
            $this->warn("Skipped deprecated responsive images for adoption/recovery-protected Media[id={$media->id}].");

            return;
        }

        parent::deleteDeprecatedResponsiveImages($media);
    }
}
