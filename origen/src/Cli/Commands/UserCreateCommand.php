<?php

namespace Origen\Cli\Commands;

use Origen\Cli\ArgParser;
use Origen\Cli\CommandInterface;
use Origen\Container;
use Origen\Storage\Database\SiteRepository;
use Origen\Storage\Database\UserRepository;

class UserCreateCommand implements CommandInterface
{
    public function name(): string
    {
        return 'user:create';
    }

    public function description(): string
    {
        return 'Create a user with site membership';
    }

    public function run(Container $container, array $args): int
    {
        $userRepo = $container->make(UserRepository::class);
        $siteRepo = $container->make(SiteRepository::class);

        $parser = new ArgParser($args);

        echo "Create a new user\n";
        echo "-----------------\n";

        $name = $parser->get('name') ?? $this->prompt('Name: ');
        $email = $parser->get('email') ?? $this->prompt('Email: ');
        $password = $parser->get('password') ?? $this->prompt('Password: ');

        if (!$name || !$email || !$password) {
            echo "Error: All fields are required.\n";
            return 1;
        }

        // Check if user exists
        $existing = $userRepo->findByEmail($email);
        if ($existing) {
            echo "User with email '{$email}' already exists (id={$existing['id']}).\n";
            $user = $existing;
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $user = $userRepo->create($name, $email, $hash);
            echo "User created (id={$user['id']}).\n";
        }

        // Resolve site from --site flag (slug or numeric ID) or interactive prompt
        $siteArg = $parser->get('site');
        if ($siteArg !== null) {
            if (ctype_digit($siteArg)) {
                $site = $siteRepo->findById((int) $siteArg);
            } else {
                $site = $siteRepo->findBySlug($siteArg);
            }
            if (!$site) {
                echo "Site not found: {$siteArg}\n";
                return 1;
            }
            $siteId = (int) $site['id'];
        } else {
            $sites = $siteRepo->all();
            if (empty($sites)) {
                echo "No sites available. Create a site first with: php hcms site:create\n";
                return 0;
            }

            echo "\nAvailable sites:\n";
            foreach ($sites as $s) {
                echo "  [{$s['id']}] {$s['name']} ({$s['slug']})\n";
            }

            $siteId = (int) $this->prompt('Site ID to add membership: ');
            $site = $siteRepo->findById($siteId);
            if (!$site) {
                echo "Site not found.\n";
                return 1;
            }
        }

        $roles = ['super_admin', 'tenant_admin', 'editor', 'author', 'viewer'];
        $role = $parser->get('role') ?? $this->prompt('Role [editor]: ') ?: 'editor';
        if (!$role) {
            $role = 'editor';
        }

        if (!in_array($role, $roles)) {
            echo "Invalid role.\n";
            return 1;
        }

        $existingMembership = $userRepo->findMembership($user['id'], $siteId);
        if ($existingMembership) {
            echo "Membership already exists with role '{$existingMembership['role']}'.\n";
            return 0;
        }

        $userRepo->createMembership($user['id'], $siteId, $role);
        echo "Membership created: {$user['email']} → {$site['name']} ({$role})\n";

        return 0;
    }

    private function prompt(string $message): string
    {
        echo $message;
        return trim(fgets(STDIN) ?: '');
    }
}
