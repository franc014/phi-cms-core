<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;

final class SectionImageResolver
{
    /** @var array<string, Media> */
    private array $media = [];

    /** @var array<string, true> */
    private array $references = [];

    /** @var list<array{reference: string, url: string}>|null */
    private ?array $legacyBindings = null;

    private bool $legacyBindingsLoaded = false;

    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        private Section $section,
        array $content,
        private bool $preview,
        private ?Page $page = null,
    ) {
        $definition = app(SectionRegistry::class)->get($section->type);
        $references = $definition::imageReferences($content);
        $uuids = [];
        foreach ($references as $item) {
            $reference = $item['reference'];
            $this->references[$reference] = true;
            if (str_starts_with($reference, 'media:')) {
                $uuids[] = Str::after($reference, 'media:');
            }
        }

        if ($uuids === []) {
            return;
        }

        $mediaClass = config('media-library.media_model');
        foreach ($mediaClass::query()
            ->whereIn('uuid', array_values(array_unique($uuids)))
            ->where('model_type', $section->getMorphClass())
            ->where('model_id', $section->getKey())
            ->where('collection_name', config('phi-cms.images.collection', 'phi-section-images'))
            ->get() as $media) {
            $this->media[$media->uuid] = $media;
        }
    }

    public function url(string $reference): string
    {
        abort_unless(isset($this->references[$reference]), 404);

        if (! str_starts_with($reference, 'media:')) {
            $binding = $this->legacyBinding($reference);

            return is_string($binding['url'] ?? null) ? $binding['url'] : $reference;
        }

        $uuid = Str::after($reference, 'media:');
        $media = $this->media[$uuid] ?? abort(404);
        abort_if($media->getCustomProperty('phi_tombstoned_at'), 404);

        if ($this->preview) {
            return route('phi-cms.images.preview', [
                'section' => $this->section,
                'uuid' => $media->uuid,
                'page' => $this->page?->getKey(),
            ]);
        }

        abort_unless($media->getCustomProperty('phi_first_published_at'), 503);

        return route('phi-cms.images.public', ['uuid' => $media->uuid]);
    }

    private function legacyBinding(string $reference): ?array
    {
        if (! $this->legacyBindingsLoaded) {
            $bindings = $this->section->getAttribute('legacy_image_sources');

            if (! is_array($bindings) && ! array_key_exists('legacy_image_sources', $this->section->getAttributes())) {
                $bindings = $this->section->newQuery()
                    ->select(['id', 'legacy_image_sources'])
                    ->find($this->section->getKey())
                    ?->legacy_image_sources;
            }

            $this->legacyBindings = is_array($bindings) ? $bindings : [];
            $this->legacyBindingsLoaded = true;
        }

        foreach ($this->legacyBindings as $binding) {
            if (is_array($binding) && ($binding['reference'] ?? null) === $reference) {
                return $binding;
            }
        }

        return null;
    }
}
