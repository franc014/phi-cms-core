<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Sections\SectionImages;
use JFA\PhiCmsCore\Sections\SectionRegistry;
use RuntimeException;
use Throwable;

final class GalleryCommand extends Command
{
    protected $signature = 'phi-cms:gallery
        {--apply : Adopt every inventoried legacy Gallery Section atomically}
        {--rollback= : before-edit or current-public; retain all snapshots and recovery exports}
        {--inventory= : Reviewed inventory report filename}
        {--report= : New private report filename; required for mutations}
        {--backup= : Existing operator database backup absolute path}
        {--freeze-confirmed : Confirm all applicable writers and old requests are frozen}
        {--accept-live-editing : Accept immediate-live legacy semantics on rollback}
        {--compare-public : Compare captured anonymous public HTML without changing the database}
        {--before-html= : Private baseline HTML filename}
        {--after-html= : Private candidate HTML filename}
        {--max-records=1000 : Refuse larger inventories}';

    protected $description = 'Inventory, adopt or safely roll back legacy Gallery content without lossy projections';

    private bool $reportCreated = false;

    public function handle(SectionRegistry $registry): int
    {
        $this->reportCreated = false;

        try {
            if ($this->option('compare-public')) {
                return $this->comparePublic();
            }

            $before = $this->inventory($registry);
            $mutating = $this->option('apply') || $this->option('rollback');

            if (! $mutating) {
                $this->writeReport($before);
                $this->line(json_encode($before, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->assertMutationOptions();
            $reviewed = json_decode(Storage::disk('local')->get($this->reportPath($this->option('inventory'))), true, flags: JSON_THROW_ON_ERROR);
            if (($reviewed['fingerprint'] ?? null) !== $before['fingerprint']) {
                throw new RuntimeException('Source changed since inventory. Generate and review a fresh inventory.');
            }

            $backup = $this->option('backup');
            $backupPath = is_string($backup) ? realpath($backup) : false;
            if ($backupPath === false || ! is_file($backupPath) || filesize($backupPath) === 0 || str_starts_with($backupPath, public_path() . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('A non-empty private database backup is required. Verify that it can be restored before proceeding.');
            }

            $provenance = [
                'backup_path' => $backupPath,
                'backup_sha256' => hash_file('sha256', $backupPath),
                'before' => $before,
            ];
            $this->writeReport($provenance);

            $after = DB::transaction(function () use ($before, $provenance, $registry): array {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('LOCK TABLE sections IN SHARE ROW EXCLUSIVE MODE');
                }

                $ids = Section::query()->where('type', 'gallery')->pluck('id');
                Media::query()->where('model_type', (new Section)->getMorphClass())
                    ->whereIn('model_id', $ids)->orderBy('id')->lockForUpdate()->get();
                Section::query()->where('type', 'gallery')->orderBy('id')->lockForUpdate()->get();
                $current = $this->inventory($registry);
                if ($current['fingerprint'] !== $before['fingerprint']) {
                    throw new RuntimeException('Source or usages changed under locks. Nothing was adopted.');
                }

                foreach ($current['sections'] as $row) {
                    if ($this->option('rollback')) {
                        $this->rollbackSection($row, $registry);
                    } elseif ($row['content_version'] === null) {
                        if ($row['draft_content'] !== null || $row['published_content'] !== null) {
                            throw new RuntimeException('Recovery snapshots must not be reset by adoption.');
                        }
                        $legacy = $this->legacyContent($row, $registry);
                        $definition = $registry->get('gallery');
                        $snapshot = $definition::adoptLegacy($legacy);
                        $sources = $definition::legacyImageSources($legacy);

                        DB::table('sections')->where('id', $row['id'])->update([
                            'draft_content' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                            'published_content' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                            'content_version' => 1,
                            'legacy_image_sources' => json_encode($sources, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $after = $this->inventory($registry);
                $this->writeReport($provenance + [
                    'after' => $after,
                    'operation' => $this->option('rollback') ?: 'adopt',
                    'commit_check' => 'Verify current database fingerprint against after.fingerprint; the report is written before commit so report failures roll back the database.',
                ]);

                return $after;
            });

            $this->info('Completed atomically. Editing remains disabled. Review public output before enabling edits. Recovery snapshots and source bindings are retained.');
            $this->line('Committed fingerprint: ' . $after['fingerprint']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function inventory(SectionRegistry $registry): array
    {
        app(SectionImages::class)->assertPrivateDisk((string) config('phi-cms.images.disk', 'phi-cms-images'));
        $limit = filter_var($this->option('max-records'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            throw new RuntimeException('max-records must be between 1 and 10000.');
        }

        $rows = DB::table('sections')->where('type', 'gallery')->orderBy('id')->limit($limit + 1)->get()->map(fn (object $row): array => (array) $row)->all();
        if (count($rows) > $limit) {
            throw new RuntimeException('Gallery inventory exceeds the requested bound.');
        }

        $sources = [];
        foreach ($rows as $row) {
            if ($row['content_version'] === null && $row['published_content'] === null) {
                $sources[$row['id']] = $registry->get('gallery')::legacyImageSources($this->legacyContent($row, $registry));
            } else {
                foreach (['draft_content', 'published_content'] as $snapshot) {
                    $content = json_decode($row[$snapshot] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                    $registry->get('gallery')::normalize($content ?? throw new RuntimeException("Section {$row['id']} has no {$snapshot}."));
                }
                $bindings = json_decode($row['legacy_image_sources'] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($bindings)) {
                    throw new RuntimeException("Section {$row['id']} has no source inventory.");
                }
                foreach ($bindings as $binding) {
                    if (($binding['kind'] ?? null) === 'external') {
                        continue;
                    }
                    if (($binding['kind'] ?? null) !== 'disk' || ! is_string($binding['disk'] ?? null) || ! is_string($binding['key'] ?? null)) {
                        throw new RuntimeException("Section {$row['id']} has an unverified source binding.");
                    }
                    $path = realpath(Storage::disk($binding['disk'])->path($binding['key']));
                    if ($path === false || $path !== ($binding['physical_path'] ?? null) || ! is_file($path)
                        || hash_file('sha256', $path) !== ($binding['sha256'] ?? null)) {
                        throw new RuntimeException("Section {$row['id']} legacy image bytes or physical identity changed.");
                    }
                }
                $sources[$row['id']] = $bindings;
            }
        }

        $ids = array_column($rows, 'id');
        $placements = DB::table('page_section')->whereIn('section_id', $ids)->orderBy('page_id')->orderBy('order_column')->orderBy('section_id')->get()->map(fn (object $row): array => (array) $row)->all();
        $pageIds = array_values(array_unique(array_column($placements, 'page_id')));
        if ($pageIds !== []) {
            $ambiguousOrder = DB::table('page_section')->whereIn('page_id', $pageIds)->select(['page_id', 'order_column'])
                ->groupBy('page_id', 'order_column')->havingRaw('COUNT(*) > 1')->first();
            if ($ambiguousOrder !== null) {
                throw new RuntimeException("Page {$ambiguousOrder->page_id}: ambiguous placement order {$ambiguousOrder->order_column}; refusing adoption.");
            }
        }
        $pages = $pageIds === [] ? [] : DB::table('pages')->whereIn('id', $pageIds)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        $inventory = ['sections' => $rows, 'placements' => $placements, 'pages' => $pages];
        $inventory['sources'] = $sources;
        $inventory['attachments'] = [];
        foreach (Media::query()->where('model_type', (new Section)->getMorphClass())->whereIn('model_id', $ids)->orderBy('id')->get() as $media) {
            if (config('filesystems.disks.' . $media->disk . '.driver') !== 'local'
                || ($media->conversions_disk && config('filesystems.disks.' . $media->conversions_disk . '.driver') !== 'local') || $media->responsive_images) {
                throw new RuntimeException("Unsupported disk or responsive-image inventory for Gallery attachment {$media->id}.");
            }
            $disk = Storage::disk($media->disk);
            $files = [];
            if (! $disk->exists($media->getPathRelativeToRoot())) {
                throw new RuntimeException("Missing original for Gallery attachment {$media->id}.");
            }
            foreach ($disk->allFiles(dirname($media->getPathRelativeToRoot())) as $path) {
                $physical = realpath($disk->path($path));
                $root = realpath($disk->path(''));
                if ($physical === false || $root === false || ! str_starts_with($physical, $root . DIRECTORY_SEPARATOR) || ! is_file($physical) || ! is_readable($physical)) {
                    throw new RuntimeException("Unprovable Gallery attachment file {$media->id}.");
                }
                $files[$path] = ['physical_path' => $physical, 'sha256' => hash_file('sha256', $physical), 'bytes' => filesize($physical)];
            }
            foreach ($media->generated_conversions ?? [] as $conversion => $generated) {
                if (! $generated) {
                    continue;
                }
                $physical = realpath($media->getPath($conversion));
                $conversionRoot = realpath(Storage::disk($media->conversions_disk ?: $media->disk)->path(''));
                if ($physical === false || $conversionRoot === false || ! str_starts_with($physical, $conversionRoot . DIRECTORY_SEPARATOR) || ! is_file($physical) || ! is_readable($physical)) {
                    throw new RuntimeException("Missing or ambiguous Gallery attachment conversion {$media->id}:{$conversion}.");
                }
                $files['conversion:' . $conversion] = ['physical_path' => $physical, 'sha256' => hash_file('sha256', $physical), 'bytes' => filesize($physical)];
            }
            $inventory['attachments'][] = ['row' => $media->getRawOriginal(), 'files' => $files];
        }

        return $inventory + ['fingerprint' => hash('sha256', json_encode($inventory, JSON_THROW_ON_ERROR))];
    }

    private function assertMutationOptions(): void
    {
        if ($this->option('apply') && $this->option('rollback')) {
            throw new RuntimeException('Choose apply or rollback, not both.');
        }
        if (config('phi-cms.editing', false) || ! $this->option('freeze-confirmed')) {
            throw new RuntimeException('Disable PHI editing and freeze all applicable writers before changing ownership.');
        }
        if (! is_string($this->option('report')) || ! is_string($this->option('inventory'))) {
            throw new RuntimeException('A reviewed inventory and a new private report are required.');
        }
        if ($this->option('rollback') !== null && ! in_array($this->option('rollback'), ['before-edit', 'current-public'], true)) {
            throw new RuntimeException('Choose before-edit or current-public rollback.');
        }
    }

    /** @param array<string, mixed> $row */
    private function legacyContent(array $row, SectionRegistry $registry): array
    {
        try {
            $envelope = json_decode($row['content'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ! array_is_list($envelope) || count($envelope) !== 1
                || ! is_array($envelope[0]) || array_diff(array_keys($envelope[0]), ['type', 'data'])
                || ($envelope[0]['type'] ?? null) !== 'gallery' || ! is_array($envelope[0]['data'] ?? null)) {
                throw new RuntimeException('Expected exactly one Gallery block with only type/data keys.');
            }

            $data = $envelope[0]['data'];
            $definition = $registry->get('gallery');
            $definition::adoptLegacy($data);
            foreach ($definition::legacyImageSources($data) as $source) {
                if (str_starts_with($source['reference'], 'media:')) {
                    throw new RuntimeException('Legacy Gallery content cannot contain private media references.');
                }
            }

            return $data;
        } catch (Throwable $exception) {
            $reason = $exception instanceof ValidationException
                ? json_encode($exception->errors(), JSON_THROW_ON_ERROR)
                : $exception->getMessage();

            throw new RuntimeException("Section {$row['id']}: {$reason}", previous: $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function rollbackSection(array $row, SectionRegistry $registry): void
    {
        if (! $this->option('accept-live-editing')) {
            throw new RuntimeException('Explicitly accept immediate-live legacy semantics on rollback.');
        }
        if ($row['content_version'] === null) {
            return;
        }

        $definition = $registry->get('gallery');
        $published = $definition::normalize(json_decode($row['published_content'], true, flags: JSON_THROW_ON_ERROR));
        if ($this->option('rollback') === 'before-edit') {
            $adopted = $definition::adoptLegacy($this->legacyContent($row, $registry));
            $draft = $definition::normalize(json_decode($row['draft_content'], true, flags: JSON_THROW_ON_ERROR));
            if ((int) $row['content_version'] !== 1 || $published !== $adopted || $draft !== $published) {
                throw new RuntimeException("Section {$row['id']} has changed since adoption; use current-public rollback.");
            }
        }

        $updates = ['content_version' => null, 'updated_at' => now()];
        if ($this->option('rollback') === 'current-public') {
            $legacy = $definition::toLegacy($published);
            $updates['content'] = json_encode([['type' => 'gallery', 'data' => $legacy]], JSON_THROW_ON_ERROR);
        }

        DB::table('sections')->where('id', $row['id'])->update($updates);
    }

    private function reportPath(string $filename): string
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.json\z/D', $filename)) {
            throw new RuntimeException('Report names must be plain .json filenames, not paths.');
        }

        return 'phi-cms/gallery/' . $filename;
    }

    private function comparePublic(): int
    {
        $outputs = [];
        foreach (['before-html', 'after-html'] as $option) {
            $filename = $this->option($option);
            if (! is_string($filename) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.html\z/D', $filename)) {
                throw new RuntimeException('Both captured private HTML filenames are required.');
            }

            $html = Storage::disk('local')->get('phi-cms/gallery/' . $filename);
            $document = new \DOMDocument;
            $previousErrors = libxml_use_internal_errors(true);

            try {
                $loaded = $document->loadHTML($html);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }

            if (! $loaded || $document->documentElement === null || $document->getElementsByTagName('body')->length !== 1) {
                throw new RuntimeException('Captured public HTML is not a complete document.');
            }

            $outputs[$option] = [
                'file' => $filename,
                'raw_sha256' => hash('sha256', $html),
                'rendered_sha256' => hash('sha256', json_encode($this->publicTree($document->documentElement), JSON_THROW_ON_ERROR)),
            ];
        }

        $equivalent = $outputs['before-html']['rendered_sha256'] === $outputs['after-html']['rendered_sha256'];
        $report = [
            'equivalent' => $equivalent,
            'outputs' => $outputs,
            'normalization' => 'Ignore comments, wire:id/snapshot/effects/key, per-request data-csrf/nonces, and indentation-only nodes between known block elements. Preserve all other attributes and text bytes, including URLs and alternative text.',
        ];
        $this->writeReport($report);
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $equivalent ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed>|null */
    private function publicTree(\DOMNode $node): ?array
    {
        if ($node instanceof \DOMText) {
            $text = $node->nodeValue;
            if (trim($text, " \t\n\r\f") === '' && $this->isBlockIndentation($node)) {
                return null;
            }

            return ['text' => $text];
        }
        if (! $node instanceof \DOMElement) {
            return null;
        }

        $attributes = [];
        foreach ($node->attributes as $attribute) {
            if (! in_array($attribute->name, ['wire:id', 'wire:snapshot', 'wire:effects', 'wire:key', 'data-csrf', 'nonce'], true)) {
                $attributes[$attribute->name] = $attribute->value;
            }
        }
        ksort($attributes);
        $children = [];
        foreach ($node->childNodes as $child) {
            if (($value = $this->publicTree($child)) !== null) {
                $children[] = $value;
            }
        }

        return ['tag' => $node->tagName, 'attributes' => $attributes, 'children' => $children];
    }

    private function isBlockIndentation(\DOMText $node): bool
    {
        for ($ancestor = $node->parentNode; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), ['pre', 'textarea', 'script', 'style'], true)
                || $ancestor->getAttribute('xml:space') === 'preserve') {
                return false;
            }
        }

        $blocks = ['html', 'head', 'body', 'article', 'section', 'div', 'header', 'footer', 'nav', 'main', 'aside', 'form', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'figure', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'pre', 'hr', 'script', 'style', 'title', 'meta', 'link', 'base', 'template'];
        if (! $node->parentNode instanceof \DOMElement || ! in_array(strtolower($node->parentNode->tagName), $blocks, true)) {
            return false;
        }
        foreach ($node->parentNode->childNodes as $sibling) {
            if ($sibling instanceof \DOMElement && ! in_array(strtolower($sibling->tagName), $blocks, true)) {
                return false;
            }
            if ($sibling instanceof \DOMText && trim($sibling->nodeValue, " \t\n\r\f") !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): void
    {
        if (! $this->option('report')) {
            return;
        }

        $path = $this->reportPath($this->option('report'));
        if (! $this->reportCreated && Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Report already exists. Choose a new filename; recovery artifacts are never overwritten.');
        }
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 'private')) {
            throw new RuntimeException('Unable to persist the private recovery report.');
        }

        $this->reportCreated = true;
    }
}
