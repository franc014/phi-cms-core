<?php

namespace JFA\PhiCmsCore\Resources\Posts;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Enums\NavigationGroup;
use JFA\PhiCmsCore\Models\Post;
use JFA\PhiCmsCore\Resources\Posts\Pages\CreatePost;
use JFA\PhiCmsCore\Resources\Posts\Pages\EditPost;
use JFA\PhiCmsCore\Resources\Posts\Pages\ListPosts;
use JFA\PhiCmsCore\Resources\Posts\Schemas\PostForm;
use JFA\PhiCmsCore\Resources\Posts\Tables\PostsTable;
use UnitEnum;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::BLOG;

    public static function getModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.post');
    }

    public static function getPluralModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.posts');
    }

    public static function form(Schema $schema): Schema
    {
        return PostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
