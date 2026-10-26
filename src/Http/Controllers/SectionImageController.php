<?php

namespace JFA\PhiCmsCore\Http\Controllers;

use Illuminate\Routing\Controller;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Sections\SectionContent;
use JFA\PhiCmsCore\Sections\SectionImageContext;
use JFA\PhiCmsCore\Sections\SectionImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SectionImageController extends Controller
{
    public function servePublic(string $uuid, SectionImages $images): BinaryFileResponse
    {
        $mediaClass = config('media-library.media_model');
        $media = $mediaClass::query()
            ->where('uuid', $uuid)
            ->where('collection_name', config('phi-cms.images.collection', 'phi-section-images'))
            ->firstOrFail();
        $path = $images->publicPath($media);

        return new BinaryFileResponse($path, 200, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], true);
    }

    public function preview(Section $section, string $uuid, SectionImages $images, SectionContent $content): BinaryFileResponse
    {
        request()->attributes->set('phi-cms.private', true);
        $pageId = request()->query('page');
        $page = $pageId === null ? null : Page::query()->findOrFail($pageId);
        $context = app(SectionImageContext::class);
        $context->set($section, $page, preview: true);
        $context->authorizeRead();
        if ($context->canReadDraft()) {
            $content->draft($section);
        } else {
            $content->published($section);
        }
        $mediaClass = config('media-library.media_model');
        $media = $mediaClass::query()->where('uuid', $uuid)->firstOrFail();
        $path = $images->privatePath($section, $media);

        return new BinaryFileResponse($path, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], false);
    }
}
