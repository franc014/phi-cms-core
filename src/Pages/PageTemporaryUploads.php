<?php

namespace JFA\PhiCmsCore\Pages;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Page;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

final class PageTemporaryUploads
{
    public function assertPrivateStorage(): void
    {
        $storage = FileUploadConfiguration::storage();
        app(PageSeoImages::class)->assertPrivateDisk(FileUploadConfiguration::disk());
        $storage->makeDirectory(FileUploadConfiguration::directory());
    }

    public function activate(string $editor): string
    {
        $token = Str::uuid()->toString();
        session()->put('phi-cms.page-upload-editors.' . $editor, $token);

        return $token;
    }

    public function issue(string $editor, string $token, string $field): string
    {
        $context = app(PageSeoImageContext::class);
        $page = $context->authorizeUpload();
        $ticket = Str::uuid()->toString();
        $binding = [
            'actor' => (string) auth()->id(),
            'session' => hash('sha256', session()->getId()),
            'editor' => $editor,
            'token' => $token,
            'page' => $page->getKey(),
            'field' => $field,
        ];
        $this->authorize($binding, true);
        Cache::put('phi-cms.page-upload-ticket.' . $ticket, $binding, now()->addMinutes(30));

        return $ticket;
    }

    public function hasTicket(string $ticket): bool
    {
        return is_array(Cache::get('phi-cms.page-upload-ticket.' . $ticket));
    }

    /** @return array<string, mixed> */
    public function ticket(string $ticket): array
    {
        $binding = Cache::get('phi-cms.page-upload-ticket.' . $ticket);
        abort_unless(is_array($binding), 403);
        $this->authorize($binding, true);

        return $binding;
    }

    /** @param array<string, mixed> $binding */
    public function register(string $filename, array $binding): void
    {
        Cache::put('phi-cms.page-upload-file.' . hash('sha256', $filename), $binding, now()->addHours(24));
    }

    public function hasFile(string $filename): bool
    {
        return is_array(Cache::get('phi-cms.page-upload-file.' . hash('sha256', $filename)));
    }

    /** @return array<string, mixed> */
    public function file(string $filename, bool $write, ?string $editor = null, ?string $field = null): array
    {
        abort_unless(str_starts_with($filename, 'phi-') && basename($filename) === $filename, 403);
        $binding = Cache::get('phi-cms.page-upload-file.' . hash('sha256', $filename));
        abort_unless(is_array($binding), 403);
        abort_if($editor !== null && $binding['editor'] !== $editor, 403);
        abort_if($field !== null && $binding['field'] !== $field, 403);

        $this->authorize($binding, $write);

        return $binding;
    }

    /** @param array<string, mixed> $binding */
    private function authorize(array $binding, bool $write): void
    {
        abort_unless(config('phi-cms.editing', false), 403);
        abort_unless((string) auth()->id() === ($binding['actor'] ?? null)
            && hash_equals((string) ($binding['session'] ?? ''), hash('sha256', session()->getId()))
            && session('phi-cms.page-upload-editors.' . ($binding['editor'] ?? '')) === ($binding['token'] ?? null), 403);

        $page = Page::query()->findOrFail((int) ($binding['page'] ?? 0));
        $context = app(PageSeoImageContext::class);
        $context->set($page, preview: true);
        if ($write) {
            $context->authorizeUpload();
        } else {
            $context->authorizeRead();
        }
    }
}
