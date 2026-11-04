<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class MenuItem extends Model implements Sortable
{
    use HasFactory;
    use SortableTrait;

    public function save(array $options = []): bool
    {
        $menuIds = array_values(array_unique(array_filter([
            $this->getAttribute('menu_id'),
            $this->getOriginal('menu_id'),
        ])));

        if ($menuIds !== [] && DB::table('menus')->whereIn('id', $menuIds)->where(function ($query): void {
            $query->whereNotNull('menu_version')->orWhereNotNull('key');
        })->exists()) {
            throw ValidationException::withMessages(['menu_item' => 'Adopted menu items must be changed through MenuContent.']);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->exists && DB::table('menus')->where('id', $this->getOriginal('menu_id'))->where(function ($query): void {
            $query->whereNotNull('menu_version')->orWhereNotNull('key');
        })->exists()) {
            throw ValidationException::withMessages(['menu_item' => 'Adopted menu items must be changed through MenuContent.']);
        }

        return parent::delete();
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'destination' => 'array',
        ];
    }
}
