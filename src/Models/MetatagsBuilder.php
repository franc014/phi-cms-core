<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JFA\PhiCmsCore\Pages\PageMetatagsGuard;

/** @extends Builder<Metatags> */
class MetatagsBuilder extends Builder
{
    public function update(array $values): int
    {
        return DB::transaction(function () use ($values): int {
            $rows = (clone $this)->select($this->getModel()->getTable() . '.*')->get();
            foreach ($rows as $metatags) {
                $metatags->forceFill($values);
                PageMetatagsGuard::check($metatags);
            }

            $this->whereKey($rows->modelKeys());

            return parent::update($values);
        });
    }

    public function delete(): mixed
    {
        return DB::transaction(function (): mixed {
            $rows = (clone $this)->select($this->getModel()->getTable() . '.*')->get();
            foreach ($rows as $metatags) {
                PageMetatagsGuard::check($metatags);
            }

            $this->whereKey($rows->modelKeys());

            return parent::delete();
        });
    }
}
