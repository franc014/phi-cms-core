<?php

namespace JFA\PhiCmsCore\Resources\Categories\Pages;

use Filament\Resources\Pages\CreateRecord;
use JFA\PhiCmsCore\Resources\Categories\CategoryResource;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;
}
