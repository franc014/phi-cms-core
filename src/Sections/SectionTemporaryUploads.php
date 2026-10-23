<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

final class SectionTemporaryUploads
{
    public function assertPrivateStorage(): void
    {
        $storage = FileUploadConfiguration::storage();
        app(SectionImages::class)->assertPrivateDisk(FileUploadConfiguration::disk());
        $storage->makeDirectory(FileUploadConfiguration::directory());
    }

    public function activate(string $editor): string
    {
        $token = Str::uuid()->toString();
        session()->put('phi-cms.upload-editors.' . $editor, $token);

        return $token;
    }

    public function issue(string $editor, string $token, string $field): string
    {
        $context = app(SectionImageContext::class);
        $section = $context->authorizeUpload();
        $ticket = Str::uuid()->toString();
        $binding = [
            'actor' => (string) auth()->id(),
            'session' => hash('sha256', session()->getId()),
            'editor' => $editor,
            'token' => $token,
            'section' => $section->getKey(),
            'page' => $context->page()?->getKey(),
            'field' => $field,
        ];
        $this->authorize($binding, true);
        Cache::put('phi-cms.upload-ticket.' . $ticket, $binding, now()->addMinutes(30));

        return $ticket;
    }

    /** @return array<string, mixed> */
    public function ticket(string $ticket): array
    {
        $binding = Cache::get('phi-cms.upload-ticket.' . $ticket);
        abort_unless(is_array($binding), 403);
        $this->authorize($binding, true);

        return $binding;
    }

    /** @param array<string, mixed> $binding */
    public function register(string $filename, array $binding): void
    {
        Cache::put('phi-cms.upload-file.' . hash('sha256', $filename), $binding, now()->addHours(24));
    }

    /** @return array<string, mixed> */
    public function file(string $filename, bool $write, ?string $editor = null, ?string $field = null): array
    {
        abort_unless(str_starts_with($filename, 'phi-') && basename($filename) === $filename, 403);
        $binding = Cache::get('phi-cms.upload-file.' . hash('sha256', $filename));
        abort_unless(is_array($binding), 403);
        abort_if($editor !== null && $binding['editor'] !== $editor, 403);
        abort_if($field !== null && $binding['field'] !== $field
            && ! (Str::beforeLast($binding['field'], '.') === $field && Str::isUuid(Str::afterLast($binding['field'], '.'))), 403);
        $this->authorize($binding, $write);

        return $binding;
    }

    /** @param array<string, mixed> $binding */
    private function authorize(array $binding, bool $write): void
    {
        abort_unless(config('phi-cms.editing', false), 403);
        abort_unless((string) auth()->id() === $binding['actor']
            && hash_equals($binding['session'], hash('sha256', session()->getId()))
            && session('phi-cms.upload-editors.' . $binding['editor']) === $binding['token'], 403);
        $section = Section::query()->findOrFail($binding['section']);
        $page = $binding['page'] === null ? null : Page::query()->published()->findOrFail($binding['page']);
        $context = app(SectionImageContext::class);
        $context->set($section, $page, preview: true);
        if ($write) {
            $context->authorizeUpload();
        } else {
            $context->authorizeRead();
        }
    }
}
