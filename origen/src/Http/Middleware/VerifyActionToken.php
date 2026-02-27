<?php

namespace Origen\Http\Middleware;

use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Services\ActionTokenService;
use Origen\Services\ReplayGuardService;

class VerifyActionToken implements MiddlewareInterface
{
    public function __construct(
        private ActionTokenService $tokenService,
        private ReplayGuardService $replayGuard,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->input('htx-token');
        $context = $request->input('htx-context');
        $recordId = $request->input('htx-recordId');
        $site = $request->input('current_site');

        // Normalize "null" string to actual null
        if ($recordId === 'null' || $recordId === '') {
            $recordId = null;
        }

        if (!$token || !$context) {
            return Response::json(['error' => 'Missing htx-token or htx-context.'], 400);
        }

        if (!$site) {
            return Response::json(['error' => 'Tenant not resolved.'], 403);
        }

        $siteId = is_array($site) ? $site['id'] : $site;

        try {
            $claims = $this->tokenService->validate($token, (int) $siteId, $context, $recordId);
        } catch (\Firebase\JWT\ExpiredException $e) {
            return Response::json(['error' => 'Token expired.'], 401);
        } catch (\Exception $e) {
            return Response::json(['error' => 'Invalid token: ' . $e->getMessage()], 403);
        }

        // Check for replay
        $jti = $claims['jti'] ?? null;
        if (!$jti) {
            return Response::json(['error' => 'Token missing jti claim.'], 403);
        }

        if ($this->replayGuard->isReplayed($jti)) {
            return Response::json(['error' => 'Token has already been used.'], 409);
        }

        // Mark token as used
        $expiresAt = date('Y-m-d H:i:s', $claims['exp']);
        $this->replayGuard->markUsed($jti, (int) $siteId, $expiresAt);

        // Store decoded claims on request for controller use
        $request->merge(['htx_claims' => $claims]);

        return $next($request);
    }
}
