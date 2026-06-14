<?php

namespace JFA\PhiCmsCore\Resources\Posts\Pages;

use Filament\Resources\Pages\CreateRecord;
use JFA\PhiCmsCore\Resources\Posts\PostResource;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;
}
