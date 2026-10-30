<?php

namespace JFA\PhiCmsCore\Resources\Pages\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Pages\PageContent;
use JFA\PhiCmsCore\Resources\Pages\PageResource;
use JFA\PhiCmsCore\Resources\Pages\Schemas\PageCreationForm;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create draft')
                ->modalHeading('Create draft Page')
                ->modalSubmitActionLabel('Create draft')
                ->createAnother(false)
                ->authorize(fn (): bool => auth()->user()?->can('create', new Page) === true
                    && auth()->user()?->can('preview', new Page) === true)
                ->schema(PageCreationForm::components())
                ->using(function (array $data): Page {
                    $submissionToken = $data['_submission_token'] ?? null;
                    if (! is_string($submissionToken) || $submissionToken === '') {
                        throw ValidationException::withMessages(['_submission_token' => 'Reopen the creation action and try again.']);
                    }

                    return app(PageContent::class)->createDraft(
                        Arr::except($data, ['_submission_token']),
                        $submissionToken,
                    );
                })
                ->successNotificationTitle(__('phi-cms-core::workflow.notifications.page_created'))
                ->successRedirectUrl(fn (Page $record): string => route('cms.page.preview', $record)),
        ];
    }
}
