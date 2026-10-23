<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Media as PhiCmsMedia;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Sections\SectionRegistry;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class ServicesCommand extends Command
{
    protected $signature = 'phi-cms:services
        {--apply : Adopt every inventoried legacy Services Section atomically}
        {--rollback= : before-edit or current-public; retain all snapshots and recovery exports}
        {--inventory= : Reviewed inventory report filename}
        {--report= : New private report filename; required for mutations}
        {--backup= : Existing operator database backup absolute path}
        {--freeze-confirmed : Confirm all applicable writers and old requests are frozen}
        {--accept-live-editing : Accept immediate-live legacy semantics on rollback}
        {--compare-public : Compare captured anonymous public HTML without writing the database}
        {--before-html= : Private baseline HTML filename}
        {--after-html= : Private candidate HTML filename}
        {--max-records=1000 : Refuse larger inventories}';

    protected $description = 'Inventory, adopt or safely roll back existing Services content without lossy projections';

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

            if ($this->option('apply') && $this->option('rollback')) {
                throw new RuntimeException('Choose apply or rollback, not both.');
            }
            if (config('phi-cms.editing', false) || ! $this->option('freeze-confirmed')) {
                throw new RuntimeException('Disable PHI editing and freeze all applicable writers before changing ownership.');
            }
            if (! $this->option('report') || ! $this->option('inventory')) {
                throw new RuntimeException('A reviewed inventory and a new private report are required.');
            }
            $reviewed = json_decode(Storage::disk('local')->get($this->reportPath($this->option('inventory'))), true, flags: JSON_THROW_ON_ERROR);
            if ($reviewed['fingerprint'] !== $before['fingerprint']) {
                throw new RuntimeException('Source changed since inventory. Generate and review a fresh inventory.');
            }
            $backup = $this->option('backup');
            if (! is_string($backup) || ! is_file($backup) || filesize($backup) === 0 || str_starts_with(realpath($backup), public_path() . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('A non-empty private database backup is required. Verify that it can be restored before proceeding.');
            }
            $provenance = ['backup_path' => realpath($backup), 'backup_sha256' => hash_file('sha256', $backup), 'before' => $before];
            $this->writeReport($provenance);

            $after = DB::transaction(function () use ($registry, $before, $provenance): array {
                $mediaIds = array_column($before['media'], 'id');
                DB::table('media')->whereIn('id', $mediaIds)->orderBy('id')->lockForUpdate()->get();
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('LOCK TABLE sections IN SHARE ROW EXCLUSIVE MODE');
                }
                Section::query()->where('type', 'services')->orderBy('id')->lockForUpdate()->get();
                $current = $this->inventory($registry);
                if ($current['fingerprint'] !== $before['fingerprint']) {
                    throw new RuntimeException('Source or usages changed under locks. Nothing was adopted.');
                }

                foreach ($current['sections'] as $row) {
                    if ($this->option('rollback')) {
                        $this->rollbackSection($row, $registry);
                    } elseif ($row['content_version'] === null) {
                        if ($row['draft_content'] !== null || $row['published_content'] !== null) {
                            throw new RuntimeException("Section {$row['id']} retains rollback/recovery snapshots; refusing to reset them.");
                        }
                        $snapshot = $this->legacyContent($row, $registry);
                        DB::table('sections')->where('id', $row['id'])->update([
                            'draft_content' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                            'published_content' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                            'content_version' => 1,
                        ]);
                    }
                }

                $after = $this->inventory($registry);
                $this->writeReport($provenance + ['after' => $after, 'operation' => $this->option('rollback') ?: 'adopt', 'commit_check' => 'Verify current database fingerprint against after.fingerprint; report is written before commit so report failures roll back the database.']);

                return $after;
            });
            $this->info('Completed atomically. Editing remains disabled. Review public output before enabling edits. Recovery snapshots and source records are retained.');
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
        $limit = filter_var($this->option('max-records'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            throw new RuntimeException('max-records must be between 1 and 10000.');
        }
        $rows = DB::table('sections')->where('type', 'services')->orderBy('id')->limit($limit + 1)->get()->map(fn (object $row): array => (array) $row)->all();
        if (count($rows) > $limit) {
            throw new RuntimeException('Services inventory exceeds the requested bound.');
        }
        foreach ($rows as $row) {
            if ($row['content_version'] === null) {
                $this->legacyContent($row, $registry);
            } else {
                foreach (['draft_content', 'published_content'] as $snapshot) {
                    $registry->get('services')::normalize(json_decode($row[$snapshot] ?? 'null', true, flags: JSON_THROW_ON_ERROR) ?? throw new RuntimeException("Section {$row['id']} has no {$snapshot}."));
                }
            }
        }
        $ids = array_column($rows, 'id');
        $placements = DB::table('page_section')->whereIn('section_id', $ids)->orderBy('page_id')->orderBy('order_column')->orderBy('section_id')->get()->map(fn (object $row): array => (array) $row)->all();
        $pageIds = array_column($placements, 'page_id');
        $ambiguousOrder = DB::table('page_section')->whereIn('page_id', $pageIds)->select(['page_id', 'order_column'])
            ->groupBy('page_id', 'order_column')->havingRaw('COUNT(*) > 1')->first();
        if ($ambiguousOrder !== null) {
            throw new RuntimeException("Page {$ambiguousOrder->page_id}: ambiguous placement order {$ambiguousOrder->order_column}; refusing adoption.");
        }
        $pages = DB::table('pages')->whereIn('id', $pageIds)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $media = [];
        $mediaClass = config('media-library.media_model', Media::class);
        if (! in_array($mediaClass, [Media::class, PhiCmsMedia::class], true)) {
            throw new RuntimeException('Custom Media models require an explicit inventory review before Services adoption.');
        }
        foreach ($mediaClass::query()->where('model_type', (new Section)->getMorphClass())->whereIn('model_id', $ids)->orderBy('id')->get() as $item) {
            $raw = $item->getRawOriginal();
            $raw['file_hashes'] = [];
            if (config('filesystems.disks.' . $item->disk . '.driver') !== 'local' || $item->responsive_images) {
                throw new RuntimeException("Media {$item->id}: unsupported disk or responsive-image inventory; refusing adoption.");
            }
            $paths = [$item->getPath()];
            foreach ($item->generated_conversions ?? [] as $conversion => $generated) {
                if ($generated) {
                    $paths[] = $item->getPath($conversion);
                }
            }
            foreach ($paths as $path) {
                if (! is_file($path) || ! is_readable($path)) {
                    throw new RuntimeException("Media {$item->id}: missing or unreadable local bytes.");
                }
                $raw['file_hashes'][$path] = hash_file('sha256', $path);
            }
            $media[] = $raw;
        }
        $inventory = ['sections' => $rows, 'placements' => $placements, 'pages' => $pages, 'media' => $media];

        return $inventory + ['fingerprint' => hash('sha256', json_encode($inventory, JSON_THROW_ON_ERROR))];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function legacyContent(array $row, SectionRegistry $registry): array
    {
        try {
            $envelope = json_decode($row['content'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ! array_is_list($envelope) || count($envelope) !== 1
                || ! is_array($envelope[0]) || array_diff(array_keys($envelope[0]), ['type', 'data'])
                || ($envelope[0]['type'] ?? null) !== 'services' || ! is_array($envelope[0]['data'] ?? null)) {
                throw new RuntimeException('Expected exactly one services block with only type/data keys.');
            }
            $snapshot = $registry->get('services')::normalize($envelope[0]['data']);
            if ($snapshot !== $envelope[0]['data']) {
                throw new RuntimeException('Normalization would change raw values or ordered items.');
            }

            return $snapshot;
        } catch (Throwable $exception) {
            $reason = $exception instanceof ValidationException ? json_encode($exception->errors(), JSON_THROW_ON_ERROR) : $exception->getMessage();

            throw new RuntimeException("Section {$row['id']}: {$reason}", previous: $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function rollbackSection(array $row, SectionRegistry $registry): void
    {
        $stage = $this->option('rollback');
        if (! in_array($stage, ['before-edit', 'current-public'], true) || ! $this->option('accept-live-editing')) {
            throw new RuntimeException('Choose before-edit or current-public rollback and explicitly accept legacy immediate-live semantics.');
        }
        if ($row['content_version'] === null) {
            return;
        }
        $published = $registry->get('services')::normalize(json_decode($row['published_content'], true, flags: JSON_THROW_ON_ERROR));
        if ($stage === 'before-edit' && ((int) $row['content_version'] !== 1 || $published !== $this->legacyContent($row, $registry)
            || $row['draft_content'] !== $row['published_content'])) {
            throw new RuntimeException("Section {$row['id']} has changed since adoption; use current-public rollback.");
        }
        $updates = ['content_version' => null];
        if ($stage === 'current-public') {
            $updates['content'] = json_encode([['type' => 'services', 'data' => $published]], JSON_THROW_ON_ERROR);
        }
        DB::table('sections')->where('id', $row['id'])->update($updates);
    }

    private function reportPath(string $filename): string
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.json\z/D', $filename)) {
            throw new RuntimeException('Report names must be plain .json filenames, not paths.');
        }

        return 'phi-cms/services/' . $filename;
    }

    private function comparePublic(): int
    {
        $outputs = [];
        foreach (['before-html', 'after-html'] as $option) {
            $filename = $this->option($option);
            if (! is_string($filename) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.html\z/D', $filename)) {
                throw new RuntimeException('Both captured private HTML filenames are required.');
            }
            $html = Storage::disk('local')->get('phi-cms/services/' . $filename);
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
            $outputs[$option] = ['file' => $filename, 'raw_sha256' => hash('sha256', $html), 'rendered_sha256' => hash('sha256', json_encode($this->publicTree($document->documentElement), JSON_THROW_ON_ERROR))];
        }
        $equivalent = $outputs['before-html']['rendered_sha256'] === $outputs['after-html']['rendered_sha256'];
        $report = ['equivalent' => $equivalent, 'outputs' => $outputs, 'normalization' => 'Ignore comments, wire:id/snapshot/effects/key, per-request data-csrf/nonces, and indentation-only nodes between known block elements. Preserve all other attributes and text bytes, including interactive directives, inline-boundary and preformatted whitespace. Compare ordered elements, classes, head metadata and URLs. Raw HTML is retained separately.'];
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
