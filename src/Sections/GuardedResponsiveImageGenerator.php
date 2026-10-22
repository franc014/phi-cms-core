<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator as BaseResponsiveImageGenerator;

final class GuardedResponsiveImageGenerator extends BaseResponsiveImageGenerator
{
    public function generateResponsiveImages(Media $media): void
    {
        DB::transaction(function () use ($media): void {
            LegacySectionGuard::conversion($media, true);

            parent::generateResponsiveImages($media);
        });
    }

    public function generateResponsiveImagesForConversion(Media $media, Conversion $conversion, string $baseImage): void
    {
        DB::transaction(function () use ($media, $conversion, $baseImage): void {
            LegacySectionGuard::conversion($media, true);

            parent::generateResponsiveImagesForConversion($media, $conversion, $baseImage);
        });
    }
}
