<?php

namespace JFA\PhiCmsCore\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * PHI's guarded Media model keeps Spatie's table and identity semantics while
 * allowing the package to install narrow mutation guards through observers.
 */
class Media extends SpatieMedia {}
