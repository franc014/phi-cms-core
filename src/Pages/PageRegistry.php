<?php

namespace JFA\PhiCmsCore\Pages;

use InvalidArgumentException;
use JFA\PhiCmsCore\Sections\SectionRegistry;

final class PageRegistry
{
    /** @var array<string, class-string<PageTemplate>> */
    private array $templates = [];

    /**
     * @param  array<mixed>  $templates
     */
    public function __construct(array $templates, SectionRegistry $sections)
    {
        foreach ($templates as $template) {
            if (! is_string($template) || ! is_subclass_of($template, PageTemplate::class)) {
                throw new InvalidArgumentException('Invalid Page template.');
            }

            $key = $template::key();
            if (! preg_match('/\A[a-z][a-z0-9_-]*\z/D', $key) || isset($this->templates[$key])) {
                throw new InvalidArgumentException("Invalid or duplicate Page template key: {$key}.");
            }

            if (! view()->exists($template::view())) {
                throw new InvalidArgumentException("Missing Blade view for Page template: {$key}.");
            }

            $composition = $template::composition();
            $this->validateComposition($key, $composition, $sections);
            $this->templates[$key] = $template;
        }
    }

    /** @return class-string<PageTemplate> */
    public function get(string $key): string
    {
        return $this->templates[$key] ?? throw new InvalidArgumentException("Missing Page template: {$key}.");
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    /** @return array<string, class-string<PageTemplate>> */
    public function all(): array
    {
        return $this->templates;
    }

    /** @return array<string, mixed> */
    public function composition(string $key): array
    {
        return $this->get($key)::composition();
    }

    public function declarationHash(string $key): string
    {
        return hash('sha256', json_encode([
            'key' => $key,
            'view' => $this->get($key)::view(),
            'composition' => $this->composition($key),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $composition
     */
    private function validateComposition(string $key, array $composition, SectionRegistry $sections): void
    {
        $mode = $composition['mode'] ?? null;
        $allowed = $composition['allowed'] ?? null;
        $required = $composition['required'] ?? null;

        if (! in_array($mode, ['ordered', 'fixed'], true) || ! is_array($allowed) || ! array_is_list($allowed) || ! is_array($required) || ! array_is_list($required)) {
            throw new InvalidArgumentException("Invalid composition declaration for Page template: {$key}.");
        }

        foreach ([...$allowed, ...$required] as $sectionKey) {
            if (! is_string($sectionKey) || ! preg_match('/\A[a-z][a-z0-9_-]*\z/D', $sectionKey)) {
                throw new InvalidArgumentException("Invalid Section key in Page template: {$key}.");
            }

            try {
                $sections->get($sectionKey);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException("Page template {$key} references an unregistered Section: {$sectionKey}.", previous: $exception);
            }
        }

        if (array_diff($required, $allowed) !== []) {
            throw new InvalidArgumentException("Required Sections must be allowed by Page template: {$key}.");
        }

        if ($mode === 'fixed' && (! isset($composition['slots']) || ! is_array($composition['slots']))) {
            throw new InvalidArgumentException("Fixed Page templates require slots: {$key}.");
        }
    }
}
