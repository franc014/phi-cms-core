<?php

namespace JFA\PhiCmsCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JFA\PhiCmsCore\Pages\PageTemporaryUploads;
use JFA\PhiCmsCore\Sections\SectionTemporaryUploads;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeSectionTemporaryPreview
{
    public function handle(Request $request, Closure $next): Response
    {
        $filename = $request->route('filename');
        if (is_string($filename) && str_starts_with($filename, 'phi-')) {
            $pageUploads = app(PageTemporaryUploads::class);
            if ($pageUploads->hasFile($filename)) {
                $pageUploads->file($filename, false);
            } else {
                app(SectionTemporaryUploads::class)->file($filename, false);
            }
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        }

        return $next($request);
    }
}
