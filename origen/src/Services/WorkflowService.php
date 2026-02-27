<?php

namespace Origen\Services;

class WorkflowService
{
    /**
     * Check if a status transition is allowed for the given role.
     * Simplified: if no workflow definitions exist, allow all transitions.
     */
    public function canTransition(array $site, string $contentType, string $fromStatus, string $toStatus, string $role): bool
    {
        // In v1, no workflow definitions table — allow all transitions
        return true;
    }
}
