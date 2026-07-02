<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use JFA\PhiCmsCore\Generators\BlockGenerator;

class GenerateBlocksCommand extends Command
{
    protected $signature = 'phi-cms:generate-blocks
        {--force : Overwrite generated files}
        {--all : Overwrite all files including handwritten ones (requires --force)}
        {--dry-run : Preview what would be generated without writing files}
        {--no-pint : Skip Pint formatting on generated files}';

    protected $description = 'Generate host block scaffolding from the schema';

    public function handle(BlockGenerator $generator): int
    {
        if ($this->option('all') && ! $this->option('force')) {
            $this->error('The --all flag requires --force to be used together.');

            return self::FAILURE;
        }

        if ($this->option('all') && ! $this->confirm('This will overwrite ALL block files, including handwritten ones. Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');

        $result = $generator->generate(
            config('phi-cms-schema.blocks', []),
            base_path(),
            app()->getNamespace(),
            (bool) $this->option('force'),
            (bool) $this->option('all'),
            $dryRun,
        );

        if ($result['generated'] !== [] && ! $this->option('no-pint') && ! $dryRun) {
            $this->runPint($result['generated']);
        }

        $this->displayResults($result, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function runPint(array $paths): void
    {
        $relativePaths = array_map(fn (string $path): string => $this->relativePath($path), $paths);
        $escapedPaths = array_map('escapeshellarg', $relativePaths);
        $command = 'vendor/bin/pint ' . implode(' ', $escapedPaths);

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->components->warn('Pint formatting failed. Generated files may need manual formatting.');
        }
    }

    /**
     * @param  array{generated: array<int, string>, skipped: array<int, string>, protected: array<int, string>}  $result
     */
    private function displayResults(array $result, bool $dryRun = false): void
    {
        $this->newLine();

        if ($dryRun) {
            $this->components->warn('DRY RUN — no files were written.');
            $this->newLine();
        }

        if ($result['generated'] !== []) {
            $this->components->info('Generated files:');
            foreach ($result['generated'] as $path) {
                $this->components->bulletList([$this->relativePath($path)]);
            }
            $this->newLine();
        }

        if ($result['skipped'] !== []) {
            $this->components->info('Skipped files (already exist):');
            foreach ($result['skipped'] as $path) {
                $this->components->bulletList([$this->relativePath($path)]);
            }
            $this->newLine();
        }

        if ($result['protected'] !== []) {
            $this->components->warn('Protected files (handwritten):');
            foreach ($result['protected'] as $path) {
                $this->components->bulletList([$this->relativePath($path)]);
            }
            $this->newLine();
        }

        $this->components->info(sprintf(
            'Total: %d generated, %d skipped, %d protected.',
            count($result['generated']),
            count($result['skipped']),
            count($result['protected']),
        ));
    }

    private function relativePath(string $path): string
    {
        $base = base_path() . '/';

        return str_starts_with($path, $base)
            ? str_replace($base, '', $path)
            : $path;
    }
}
