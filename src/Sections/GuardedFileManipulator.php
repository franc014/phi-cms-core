<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator as BaseFileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class GuardedFileManipulator extends BaseFileManipulator
{
    public function performConversions(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing = false,
    ): BaseFileManipulator {
        return DB::transaction(function () use ($conversions, $media, $onlyMissing): BaseFileManipulator {
            LegacySectionGuard::conversion($media);

            return parent::performConversions($conversions, $media, $onlyMissing);
        });
    }
}
