<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator as BaseFileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class GuardedFileManipulator extends BaseFileManipulator
{
    public function createDerivedFiles(
        Media $media,
        array $onlyConversionNames = [],
        bool $onlyMissing = false,
        bool $withResponsiveImages = false,
        bool $queueAll = false,
    ): void {
        LegacySectionGuard::conversion($media);

        parent::createDerivedFiles($media, $onlyConversionNames, $onlyMissing, $withResponsiveImages, $queueAll);
    }

    public function performConversions(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing = false,
    ): BaseFileManipulator {
        return DB::transaction(function () use ($conversions, $media, $onlyMissing): BaseFileManipulator {
            LegacySectionGuard::conversion($media, true);

            return parent::performConversions($conversions, $media, $onlyMissing);
        });
    }
}
