<?php

namespace JFA\PhiCmsCore\Http\Controllers;

use Illuminate\Routing\Controller;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Pages\PageSeoImageContext;
use JFA\PhiCmsCore\Pages\PageSeoImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PageSeoImageController extends Controller
{
    public function servePublic(string $uuid, PageSeoImages $images): BinaryFileResponse
    {
        $mediaClass = config('media-library.media_model');
        $media = $mediaClass::query()
            ->where('uuid', $uuid)
            ->where('collection_name', $images->collectionName())
            ->firstOrFail();
        $path = $images->publicPath($media);

        return new BinaryFileResponse($path, 200, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], true);
    }

    public function preview(Page $page, string $uuid, PageSeoImages $images, PageSeoImageContext $context): BinaryFileResponse
    {
        request()->attributes->set('phi-cms.private', true);
        $context->set($page, preview: true);
        $page = $context->authorizeRead();
        $mediaClass = config('media-library.media_model');
        $media = $mediaClass::query()->where('uuid', $uuid)->firstOrFail();
        $path = $images->privatePath($page, $media);

        return new BinaryFileResponse($path, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], false);
    }
}
