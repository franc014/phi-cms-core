<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Pages\PageContent;
use RuntimeException;
use Throwable;

class PageCommand extends Command
{
    protected $signature = 'phi-cms:pages
        {page : Existing Page identity}
        {--template= : Registered Page template key}
        {--apply : Apply the reviewed adoption atomically}
        {--rollback= : before-edit or current-public}
        {--inventory= : Reviewed inventory report filename}
        {--report= : New private report filename}
        {--backup= : Existing operator database backup absolute path}
        {--freeze-confirmed : Confirm all applicable writers and old requests are frozen}
        {--accept-live-editing : Accept immediate-live legacy semantics on rollback}
        {--max-records=1 : Refuse a command scope larger than one Page}';

    protected $description = 'Inventory, adopt or safely roll back one existing Page without lossy projections';

    private bool $reportCreated = false;

    public function handle(PageContent $content): int
    {
        $this->reportCreated = false;

        try {
            if ((int) $this->option('max-records') !== 1) {
                throw new RuntimeException('Page adoption is intentionally bounded to one explicit Page.');
            }

            $page = Page::query()->findOrFail((int) $this->argument('page'));
            $template = $this->option('template') ?: $page->template;
            if (! is_string($template) || $template === '') {
                throw new RuntimeException('A registered --template is required for inventory.');
            }

            $before = $content->inventory($page, $template);
            $mutating = $this->option('apply') || $this->option('rollback') !== null;
            if (! $mutating) {
                $this->writeReport($before);
                $this->line(json_encode($before, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            if (! $this->option('freeze-confirmed') || config('phi-cms.editing', false)) {
                throw new RuntimeException('Disable Page editing and freeze all applicable writers before changing ownership.');
            }
            if ($this->option('apply') && $this->option('rollback') !== null) {
                throw new RuntimeException('Choose apply or rollback, not both.');
            }
            if (! is_string($this->option('report'))) {
                throw new RuntimeException('A new private --report is required for mutations.');
            }
            $this->requireBackup();
            $reviewed = $this->readInventory();
            if (($reviewed['fingerprint'] ?? null) !== $before['fingerprint']) {
                throw new RuntimeException('Source changed since inventory. Generate and review a fresh inventory.');
            }

            $operation = $this->option('apply') ? 'adopt' : (string) $this->option('rollback');
            $provenance = [
                'operation' => $operation,
                'before' => $before,
                'backup' => $this->backupMetadata(),
            ];
            $this->writeReport($provenance);

            if ($this->option('apply')) {
                $after = null;
                $content->adopt($page, $template, $before, function (array $candidate) use (&$after, $provenance): void {
                    $after = $candidate;
                    $this->writeReport($provenance + [
                        'after' => $candidate,
                        'commit_check' => 'Verify the committed database fingerprint against after.fingerprint.',
                    ]);
                });
                if ($after === null) {
                    $after = $content->inventory($page->fresh(), $template);
                    $this->writeReport($provenance + [
                        'after' => $after,
                        'commit_check' => 'Verify the committed database fingerprint against after.fingerprint.',
                    ]);
                }
            } else {
                $after = $this->rollback($page, (string) $this->option('rollback'), $content, $template, $before, $provenance);
            }

            $this->info('Completed atomically. Review public output before enabling editing.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $provenance
     * @return array<string, mixed>
     */
    private function rollback(Page $page, string $stage, PageContent $content, string $template, array $before, array $provenance): array
    {
        if (! in_array($stage, ['before-edit', 'current-public'], true) || ! $this->option('accept-live-editing')) {
            throw new RuntimeException('Choose before-edit or current-public rollback and explicitly accept legacy immediate-live semantics.');
        }

        return DB::transaction(function () use ($page, $stage, $content, $template, $before, $provenance): array {
            DB::table('page_urls')->where('page_id', $page->getKey())->orderBy('slug')->lockForUpdate()->get();
            $sectionIds = DB::table('page_section')
                ->where('page_id', $page->getKey())
                ->orderBy('section_id')
                ->pluck('section_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            Section::query()->whereIn('id', $sectionIds)->orderBy('id')->lockForUpdate()->get();
            $current = Page::query()->lockForUpdate()->find($page->getKey());
            if ($current === null) {
                throw new RuntimeException('The Page no longer exists. Nothing was rolled back.');
            }
            $currentInventory = $content->inventory($current, $template);
            if (($before['fingerprint'] ?? null) !== $currentInventory['fingerprint']) {
                throw new RuntimeException('Source changed under locks. Nothing was rolled back.');
            }
            if ($current->page_version === null) {
                $this->writeReport($provenance + [
                    'after' => $currentInventory,
                    'commit_check' => 'Verify the committed database fingerprint against after.fingerprint.',
                ]);

                return $currentInventory;
            }
            if ($stage === 'before-edit' && (int) $current->page_version !== 1) {
                throw new RuntimeException('The Page has changed since adoption; before-edit rollback is unsafe. Use current-public after reviewing the latest state.');
            }
            $export = [
                'page' => $current->getRawOriginal(),
                'metatags' => $current->metatags?->getRawOriginal(),
                'assignments' => DB::table('page_section')->where('page_id', $current->getKey())->get()->map(fn (object $row): array => (array) $row)->all(),
                'urls' => DB::table('page_urls')->where('page_id', $current->getKey())->get()->map(fn (object $row): array => (array) $row)->all(),
            ];
            $path = 'phi-cms/pages/' . $current->getKey() . '/rollback-' . now()->format('YmdHis') . '-' . Str::random(8) . '.json';
            if (! Storage::disk('local')->put($path, json_encode($export, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 'private')) {
                throw new RuntimeException('The rollback recovery export could not be written.');
            }

            DB::table('page_section')->where('page_id', $current->getKey())->where('stage', 'draft')->delete();
            if ($stage === 'before-edit') {
                DB::table('page_section')->where('page_id', $current->getKey())->where('stage', 'published')->update([
                    'occurrence_id' => null,
                    'slot' => null,
                ]);
                DB::table('page_urls')->where('page_id', $current->getKey())->delete();
            }
            DB::table('pages')->where('id', $current->getKey())->update([
                'template' => $stage === 'current-public' ? $current->template : null,
                'page_version' => null,
                'status' => $current->status->value,
                'updated_at' => now(),
            ]);
            $after = $content->inventory($current->fresh(), $template);
            $this->writeReport($provenance + [
                'after' => $after,
                'recovery_export' => $path,
                'commit_check' => 'Verify the committed database fingerprint against after.fingerprint.',
            ]);

            return $after;
        });
    }

    private function requireBackup(): void
    {
        $backup = $this->option('backup');
        if (! is_string($backup) || ! is_file($backup) || filesize($backup) === 0 || str_starts_with((string) realpath($backup), public_path() . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('A non-empty private database backup is required.');
        }
    }

    /** @return array<string, mixed> */
    private function readInventory(): array
    {
        $filename = $this->option('inventory');
        if (! is_string($filename)) {
            throw new RuntimeException('A reviewed --inventory report is required for mutations.');
        }

        return json_decode(Storage::disk('local')->get($this->reportPath($filename)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{path: string, sha256: string} */
    private function backupMetadata(): array
    {
        $backup = (string) $this->option('backup');

        return ['path' => realpath($backup) ?: $backup, 'sha256' => hash_file('sha256', $backup)];
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): void
    {
        if (! $this->option('report')) {
            return;
        }
        $filename = (string) $this->option('report');
        $path = $this->reportPath($filename);
        if (! $this->reportCreated && Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Report already exists. Choose a new filename; recovery artifacts are never overwritten.');
        }
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 'private')) {
            throw new RuntimeException('Unable to persist the private Page recovery report.');
        }
        $this->reportCreated = true;
    }

    private function reportPath(string $filename): string
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.json\z/D', $filename)) {
            throw new RuntimeException('Report names must be plain .json filenames, not paths.');
        }

        return 'phi-cms/pages/' . $this->argument('page') . '/' . $filename;
    }
}
