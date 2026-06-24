<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;

class ContentBlockRegistry
{
    /**
     * @var array<class-string>
     */
    protected array $blocks = [];

    /**
     * @param  class-string  $block
     */
    public function register(string $block): void
    {
        $this->blocks[] = $block;
    }

    /**
     * @deprecated Use register() instead. The default/custom split is no longer meaningful.
     */
    public function registerDefault(string $block): void
    {
        $this->register($block);
    }

    /**
     * @deprecated Use register() instead. The default/custom split is no longer meaningful.
     */
    public function registerCustom(string $block): void
    {
        $this->register($block);
    }

    /**
     * @deprecated Use getAll() instead.
     */
    public function getDefault(): array
    {
        return [];
    }

    /**
     * @deprecated Use getAll() instead.
     */
    public function getCustom(): array
    {
        return [];
    }

    /**
     * @return array<Block>
     */
    public function getAll(): array
    {
        return array_map(fn ($block) => $block::make(), $this->blocks);
    }

    public function getByName(string $name): ?Block
    {
        return collect($this->getAll())
            ->first(fn (Block $block): bool => $block->getName() === $name);
    }
}
