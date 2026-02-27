<?php

namespace Origen\Cli\Commands;

use Origen\Cli\CommandInterface;
use Origen\Container;
use Origen\Storage\Database\SiteRepository;
use Origen\Storage\FlatFile\SiteConfigManager;

class SiteCreateCommand implements CommandInterface
{
    public function name(): string
    {
        return 'site:create';
    }

    public function description(): string
    {
        return 'Create a new site with _site.yaml';
    }

    public function run(Container $container, array $args): int
    {
        $siteConfigManager = $container->make(SiteConfigManager::class);
        $siteRepo = $container->make(SiteRepository::class);

        echo "Create a new site\n";
        echo "-----------------\n";

        $name = $this->prompt('Site name: ');
        $slug = $this->prompt('Slug (e.g. marketing): ');
        $domain = $this->prompt('Domain (e.g. marketing.example.com): ');

        if (!$name || !$slug || !$domain) {
            echo "Error: All fields are required.\n";
            return 1;
        }

        // Normalize slug
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug));
        $slug = trim($slug, '-');

        // Generate API key
        $apiKey = 'htx-' . $slug . '-' . bin2hex(random_bytes(8));

        $config = [
            'name' => $name,
            'domain' => $domain,
            'api_key' => $apiKey,
            'active' => true,
            'settings' => [],
        ];

        // Write _site.yaml
        $path = $siteConfigManager->write($slug, $config);
        echo "Config written: {$path}\n";

        // Bootstrap into SQLite
        $config['slug'] = $slug;
        $site = $siteRepo->upsert($config);
        echo "Site created (id={$site['id']}).\n";
        echo "API Key: {$apiKey}\n";
        echo "\nUse this API key as the X-Site-Key header.\n";

        return 0;
    }

    private function prompt(string $message): string
    {
        echo $message;
        return trim(fgets(STDIN) ?: '');
    }
}
