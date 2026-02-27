<?php

namespace Origen\Http\Middleware;

use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Storage\Database\SiteRepository;

class ResolveTenant implements MiddlewareInterface
{
    public function __construct(private SiteRepository $siteRepository) {}

    public function handle(Request $request, callable $next): Response
    {
        $siteKey = $request->header('x-site-key');

        if (!$siteKey) {
            return Response::json(['error' => 'X-Site-Key header required.'], 401);
        }

        $site = $this->siteRepository->findByApiKey($siteKey);

        if (!$site) {
            return Response::json(['error' => 'Invalid site key.'], 403);
        }

        $request->setAttribute('current_site', $site);
        $request->merge(['current_site' => $site]);

        return $next($request);
    }
}
