<?php

namespace Origen\Services;

use Origen\Storage\Database\TokenRepository;

class ReplayGuardService
{
    public function __construct(private TokenRepository $tokenRepository) {}

    public function isReplayed(string $jti): bool
    {
        return $this->tokenRepository->isUsed($jti);
    }

    public function markUsed(string $jti, int $siteId, string $expiresAt): void
    {
        $this->tokenRepository->markUsed($jti, $siteId, $expiresAt);
    }

    public function cleanup(): int
    {
        return $this->tokenRepository->cleanupExpired();
    }
}
