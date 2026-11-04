<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Menu extends Model
{
    use HasFactory;

    public function save(array $options = []): bool
    {
        $isAdopted = $this->getOriginal('menu_version') !== null
            || $this->getOriginal('key') !== null
            || $this->menu_version !== null
            || $this->key !== null;

        if ($isAdopted && (! $this->exists || $this->isDirty(['title', 'description', 'slug', 'key', 'menu_version', 'adoption_bindings']))) {
            throw ValidationException::withMessages(['menu' => 'Adopted menus must be changed through the reviewed menu workflow.']);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->exists && ($this->getOriginal('menu_version') !== null || $this->getOriginal('key') !== null)) {
            throw ValidationException::withMessages(['menu' => 'Declared adopted menus cannot be deleted through the legacy Menu resource.']);
        }

        return parent::delete();
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)
            ->where('stage', 'published')
            ->orderBy('order_column')
            ->orderBy('id');
    }

    public function draftItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)
            ->where('stage', 'draft')
            ->orderBy('order_column')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'menu_version' => 'integer',
            'adoption_bindings' => 'array',
        ];
    }
}
