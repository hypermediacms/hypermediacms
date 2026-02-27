<?php

namespace Origen\Cli\Commands;

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

        // Parse CLI options
        $opts = $this->parseArgs($args);

        // Check if we have enough args for non-interactive mode
        $nonInteractive = isset($opts['email']);

        if ($nonInteractive) {
            return $this->runNonInteractive($opts, $userRepo, $siteRepo);
        }

        return $this->runInteractive($userRepo, $siteRepo);
    }

    private function runNonInteractive(array $opts, UserRepository $userRepo, SiteRepository $siteRepo): int
    {
        $email = $opts['email'] ?? null;
        $name = $opts['name'] ?? 'Admin';
        $password = $opts['password'] ?? $this->generatePassword();
        $siteSlug = $opts['site'] ?? 'main';
        $role = $opts['role'] ?? 'super_admin';
        $showPassword = !isset($opts['password']); // Show if we generated it

        if (!$email) {
            echo "Error: --email is required.\n";
            return 1;
        }

        $validRoles = ['super_admin', 'tenant_admin', 'editor', 'author', 'viewer'];
        if (!in_array($role, $validRoles)) {
            echo "Error: Invalid role. Must be one of: " . implode(', ', $validRoles) . "\n";
            return 1;
        }

        // Find site by slug
        $site = $siteRepo->findBySlug($siteSlug);
        if (!$site) {
            echo "Error: Site '{$siteSlug}' not found.\n";
            return 1;
        }

        // Check if user exists
        $existing = $userRepo->findByEmail($email);
        if ($existing) {
            $user = $existing;
            echo "User exists (id={$user['id']}).\n";
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            // Force password reset if we auto-generated the password
            $forceReset = $showPassword;
            $user = $userRepo->create($name, $email, $hash, $forceReset);
            echo "User created (id={$user['id']}).\n";

            if ($showPassword) {
                echo "Generated password: {$password}\n";
                echo "Password reset required on first login.\n";
            }
        }

        // Check membership
        $existingMembership = $userRepo->findMembership($user['id'], $site['id']);
        if ($existingMembership) {
            echo "Membership exists: {$role}\n";
            return 0;
        }

        $userRepo->createMembership($user['id'], $site['id'], $role);
        echo "Membership created: {$email} → {$site['name']} ({$role})\n";

        return 0;
    }

    private function runInteractive(UserRepository $userRepo, SiteRepository $siteRepo): int
    {
        echo "Create a new user\n";
        echo "-----------------\n";

        $name = $this->prompt('Name: ');
        $email = $this->prompt('Email: ');
        $password = $this->prompt('Password: ');

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

        // List available sites
        $sites = $siteRepo->all();
        if (empty($sites)) {
            echo "No sites available. Create a site first with: php hcms site:create\n";
            return 0;
        }

        echo "\nAvailable sites:\n";
        foreach ($sites as $site) {
            echo "  [{$site['id']}] {$site['name']} ({$site['slug']})\n";
        }

        $siteId = (int) $this->prompt('Site ID to add membership: ');
        $site = $siteRepo->findById($siteId);
        if (!$site) {
            echo "Site not found.\n";
            return 1;
        }

        $roles = ['super_admin', 'tenant_admin', 'editor', 'author', 'viewer'];
        echo "Roles: " . implode(', ', $roles) . "\n";
        $role = $this->prompt('Role [editor]: ') ?: 'editor';

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

    private function parseArgs(array $args): array
    {
        $opts = [];
        foreach ($args as $arg) {
            if (strpos($arg, '--') === 0) {
                $arg = substr($arg, 2);
                if (strpos($arg, '=') !== false) {
                    [$key, $value] = explode('=', $arg, 2);
                    $opts[$key] = $value;
                } else {
                    $opts[$arg] = true;
                }
            }
        }
        return $opts;
    }

    private function generatePassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    private function prompt(string $message): string
    {
        echo $message;
        return trim(fgets(STDIN) ?: '');
    }
}
