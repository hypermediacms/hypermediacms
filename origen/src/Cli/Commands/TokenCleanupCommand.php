<?php

namespace Origen\Cli\Commands;

use Origen\Cli\CommandInterface;
use Origen\Container;
use Origen\Storage\Database\TokenRepository;

class TokenCleanupCommand implements CommandInterface
{
    public function name(): string
    {
        return 'token:cleanup';
    }

    public function description(): string
    {
        return 'Purge expired used_tokens';
    }

    public function run(Container $container, array $args): int
    {
        $tokenRepo = $container->make(TokenRepository::class);
        $count = $tokenRepo->cleanupExpired();
        echo "Cleaned up {$count} expired token(s).\n";
        return 0;
    }
}
