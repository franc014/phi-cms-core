<?php

namespace JFA\PhiCmsCore\Models;

use JFA\PhiCmsCore\Sections\LegacySectionGuard;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * PHI's guarded Media model keeps Spatie's table and identity semantics while
 * allowing the package to install narrow mutation guards through observers.
 */
class Media extends SpatieMedia
{
    public function save(array $options = []): bool
    {
        LegacySectionGuard::media($this);

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        LegacySectionGuard::media($this);

        return parent::delete();
    }
}
