<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;

class ContentBlockRegistry
{
    protected array $defaultBlocks = [];

    protected array $customBlocks = [];

    public function registerDefault(string $block): void
    {
        $this->defaultBlocks[] = $block;
    }

    public function registerCustom(string $block): void
    {
        $this->customBlocks[] = $block;
    }

    public function getDefault(): array
    {
        return array_map(fn ($block) => $block::make(), $this->defaultBlocks);
    }

    public function getCustom(): array
    {
        return array_map(fn ($block) => $block::make(), $this->customBlocks);
    }

    public function getAll(): array
    {
        return [
            ...$this->getDefault(),
            ...$this->getCustom(),
        ];
    }

    public function getByName(string $name): ?Block
    {
        return collect($this->getAll())
            ->first(fn (Block $block): bool => $block->getName() === $name);
    }
}
