<?php

namespace JFA\PhiCmsCore\Resources\Menus\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Menus\MenuConflict;
use JFA\PhiCmsCore\Menus\MenuContent;
use JFA\PhiCmsCore\Models\Menu;
use JFA\PhiCmsCore\Resources\Menus\MenuResource;
use Livewire\Attributes\Locked;

class EditMenu extends EditRecord
{
    protected static string $resource = MenuResource::class;

    #[Locked]
    public int $menuVersion = 0;

    #[Locked]
    public ?string $reviewToken = null;

    #[Locked]
    public ?int $reviewVersion = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $publicationReview = null;

    public function mount(int | string $record): void
    {
        parent::mount($record);
        $this->menuVersion = (int) ($this->getRecord()->menu_version ?? 0);
    }

    protected function authorizeAccess(): void
    {
        $record = $this->getRecord()->fresh();
        if ($record instanceof Menu && $record->menu_version !== null) {
            $actor = auth()->user()?->fresh();
            abort_if($actor === null, 403);
            $gate = Gate::forUser($actor);
            abort_unless($gate->allows('viewDraft', $record)
                && ($gate->allows('update', $record) || $gate->allows('publish', $record)), 403);

            return;
        }

        parent::authorizeAccess();
    }

    protected function getFormActions(): array
    {
        return $this->getRecord()->menu_version !== null ? [] : parent::getFormActions();
    }

    protected function getHeaderActions(): array
    {
        if ($this->getRecord()->menu_version !== null) {
            $key = (string) $this->getRecord()->key;

            return [
                Action::make('saveMenuDraft')
                    ->label('Save menu draft')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([$this->draftItemsRepeater($key)])
                    ->modalSubmitActionLabel('Save draft')
                    ->visible(fn (): bool => $this->mayUpdateMenu())
                    ->action(function (array $data) use ($key): void {
                        $this->saveMenuDraft($key, $data);
                    }),
                Action::make('previewMenuDraft')
                    ->label('Preview saved draft')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalContent(fn () => view('phi-cms-core::resources.menus.preview', [
                        'preview' => app(MenuContent::class)->preview($key),
                    ]))
                    ->modalSubmitAction(false)
                    ->visible(fn (): bool => $this->mayViewDraft()),
                Action::make('reviewMenuPublication')
                    ->label('Review publication')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->visible(fn (): bool => $this->mayPublishMenu())
                    ->action(function () use ($key): void {
                        $review = app(MenuContent::class)->review($key, $this->menuVersion);
                        $this->reviewToken = $review['token'];
                        $this->reviewVersion = $review['version'];
                        $this->publicationReview = $review;
                        Notification::make()
                            ->title('Saved menu draft reviewed')
                            ->body($review['publishable'] ? 'The reviewed destinations are available for publication.' : 'Resolve the unavailable or unpublished destinations before publishing.')
                            ->success($review['publishable'])
                            ->warning(! $review['publishable'])
                            ->send();
                    }),
                Action::make('publishMenu')
                    ->label('Publish menu')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Publish reviewed menu')
                    ->modalSubmitActionLabel('Publish menu')
                    ->modalContent(fn () => view('phi-cms-core::resources.menus.review', [
                        'review' => $this->publicationReview,
                    ]))
                    ->visible(fn (): bool => $this->mayPublishMenu() && $this->reviewToken !== null && $this->publicationReview !== null)
                    ->disabled(fn (): bool => ($this->publicationReview['publishable'] ?? false) !== true)
                    ->action(function () use ($key): void {
                        $this->publishReviewedMenu($key);
                    }),
            ];
        }

        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->getRecord()->menu_version === null),
        ];
    }

    private function draftItemsRepeater(string $key): Repeater
    {
        return Repeater::make('items')
            ->label('Flat menu links')
            ->default(fn (): array => array_map(
                static fn (array $row): array => [
                    'item_key' => $row['item_key'],
                    'label' => $row['label'],
                    'destination' => $row['destination'],
                ],
                app(MenuContent::class)->preview($key)['items'],
            ))
            ->schema([
                Hidden::make('item_key'),
                TextInput::make('label')
                    ->label('Label')
                    ->required()
                    ->maxLength(255),
                Select::make('destination.kind')
                    ->label('Destination type')
                    ->options([
                        'page' => 'Page',
                        'fixed' => 'Developer-declared host route',
                        'custom' => 'Custom URL (untracked)',
                    ])
                    ->required()
                    ->live(),
                Select::make('destination.page_id')
                    ->label('Page')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => collect(app(MenuContent::class)->destinationChoices($key, $search))
                        ->filter(fn (array $choice): bool => ($choice['destination']['kind'] ?? null) === 'page')
                        ->mapWithKeys(fn (array $choice): array => [(string) $choice['destination']['page_id'] => $choice['label']])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value)
                        ? app(MenuContent::class)->pageChoice($key, (int) $value)['label'] ?? null
                        : null)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('destination.kind') === 'page'),
                Select::make('destination.anchor')
                    ->label('Page anchor')
                    ->placeholder('Page root')
                    ->options(fn (Get $get): array => is_numeric($get('destination.page_id'))
                        ? app(MenuContent::class)->anchorChoices($key, (int) $get('destination.page_id'))
                        : [])
                    ->visible(fn (Get $get): bool => $get('destination.kind') === 'page'),
                Select::make('destination.key')
                    ->label('Host route')
                    ->options($this->fixedLinkOptions())
                    ->required()
                    ->visible(fn (Get $get): bool => $get('destination.kind') === 'fixed'),
                TextInput::make('destination.url')
                    ->label('Custom URL (untracked)')
                    ->required()
                    ->maxLength(2048)
                    ->visible(fn (Get $get): bool => $get('destination.kind') === 'custom'),
            ])
            ->defaultItems(0)
            ->maxItems(100)
            ->reorderable()
            ->reorderableWithButtons()
            ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) ? $state['label'] : null)
            ->addActionLabel('Add link');
    }

    /** @return array<string, string> */
    private function fixedLinkOptions(): array
    {
        $options = [];
        foreach (config('phi-cms.navigation.fixed_links', []) as $key => $definition) {
            if (is_array($definition) && is_string($definition['label'] ?? null) && is_string($definition['route'] ?? null)) {
                $options[$key] = $definition['label'] . ' — ' . (Route::has($definition['route'])
                    ? route($definition['route'], [], false)
                    : 'Unavailable route');
            }
        }

        return $options;
    }

    /** @param array<string, mixed> $data */
    private function saveMenuDraft(string $key, array $data): void
    {
        $rows = $data['items'] ?? [];
        if (! is_array($rows)) {
            throw ValidationException::withMessages(['items' => 'Menu links must be an ordered list.']);
        }
        $rows = array_values($rows);
        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! is_array($row['destination'] ?? null)) {
                throw ValidationException::withMessages(["items.{$index}.destination" => 'Choose a destination for this menu item.']);
            }
            $destination = $row['destination'];
            $kind = $destination['kind'] ?? null;
            if ($kind === 'page') {
                $pageId = filter_var($destination['page_id'] ?? null, FILTER_VALIDATE_INT);
                if ($pageId === false || $pageId < 1) {
                    throw ValidationException::withMessages(["items.{$index}.destination.page_id" => 'Choose a Page.']);
                }
                $rows[$index]['destination'] = [
                    'kind' => 'page',
                    'page_id' => $pageId,
                    'anchor' => filled($destination['anchor'] ?? null) ? (string) $destination['anchor'] : null,
                ];
            } elseif ($kind === 'fixed') {
                $rows[$index]['destination'] = ['kind' => 'fixed', 'key' => $destination['key'] ?? null];
            } elseif ($kind === 'custom') {
                $rows[$index]['destination'] = ['kind' => 'custom', 'url' => $destination['url'] ?? null];
            }
        }

        $this->menuVersion = app(MenuContent::class)->saveDraft($key, $rows, $this->menuVersion);
        $this->reviewToken = null;
        $this->reviewVersion = null;
        $this->publicationReview = null;
        Notification::make()->title('Menu draft saved')->success()->send();
    }

    private function publishReviewedMenu(string $key): void
    {
        if ($this->reviewToken === null || $this->reviewVersion === null) {
            throw new MenuConflict('Review the saved menu before publishing it.');
        }

        $this->menuVersion = app(MenuContent::class)->publish($key, $this->reviewVersion, $this->reviewToken);
        $this->reviewToken = null;
        $this->reviewVersion = null;
        $this->publicationReview = null;
        $this->record = $this->getRecord()->fresh();
        Notification::make()->title('Menu published')->success()->send();
    }

    private function mayUpdateMenu(): bool
    {
        $actor = auth()->user()?->fresh();
        $menu = $this->getRecord()->fresh();

        return $actor !== null && $actor->can('update', $menu);
    }

    private function mayViewDraft(): bool
    {
        $actor = auth()->user()?->fresh();
        $menu = $this->getRecord()->fresh();

        return $actor !== null && $actor->can('viewDraft', $menu);
    }

    private function mayPublishMenu(): bool
    {
        $actor = auth()->user()?->fresh();
        $menu = $this->getRecord()->fresh();

        return $actor !== null && $actor->can('publish', $menu);
    }
}
