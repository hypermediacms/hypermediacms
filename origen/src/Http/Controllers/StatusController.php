<?php

namespace Origen\Http\Controllers;

use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Services\AuthTokenService;
use Origen\Storage\Database\Connection;
use Origen\Storage\Database\SiteRepository;
use Origen\Storage\Database\UserRepository;

class StatusController
{
    public function __construct(
        private Connection $connection,
        private SiteRepository $siteRepository,
        private UserRepository $userRepository,
        private AuthTokenService $authTokenService,
    ) {}

    public function dashboard(Request $request): Response
    {
        if ($this->hasNoUsers()) {
            return Response::html($this->renderSetupPage());
        }

        $authUser = $request->input('auth_user');

        if (!$authUser) {
            return Response::html($this->renderLoginPage());
        }

        if ($authUser['role'] !== 'super_admin') {
            return Response::html($this->renderLoginPage('Forbidden. Super admin access required.'), 403);
        }

        return $this->renderDashboardResponse($authUser);
    }

    public function login(Request $request): Response
    {
        // Route to setup if no users exist yet
        if ($this->hasNoUsers()) {
            return $this->setup($request);
        }

        $email = $request->input('email');
        $password = $request->input('password');

        if (!$email || !$password) {
            return Response::html($this->renderLoginPage('Email and password are required.'), 422);
        }

        $user = $this->userRepository->findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return Response::html($this->renderLoginPage('Invalid credentials.'), 401);
        }

        // Find a super_admin membership on any site
        $membership = $this->connection->query(
            'SELECT * FROM memberships WHERE user_id = ? AND role = ? LIMIT 1',
            [$user['id'], 'super_admin']
        )->fetch();

        if (!$membership) {
            return Response::html($this->renderLoginPage('Forbidden. Super admin access required.'), 403);
        }

        $site = $this->siteRepository->findById((int) $membership['site_id']);
        $token = $this->authTokenService->issue($user, $site, 'super_admin');

        $response = Response::html('', 302);
        $response->header('Location', '/');
        $response->header('Set-Cookie', 'dashboard_token=' . $token . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=86400');

        return $response;
    }

    private function setup(Request $request): Response
    {
        $name = trim($request->input('name') ?? '');
        $email = trim($request->input('email') ?? '');
        $password = $request->input('password') ?? '';
        $passwordConfirm = $request->input('password_confirm') ?? '';

        if (!$name || !$email || !$password) {
            return Response::html($this->renderSetupPage('All fields are required.'), 422);
        }

        if ($password !== $passwordConfirm) {
            return Response::html($this->renderSetupPage('Passwords do not match.'), 422);
        }

        if (strlen($password) < 6) {
            return Response::html($this->renderSetupPage('Password must be at least 6 characters.'), 422);
        }

        // Create the user
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $user = $this->userRepository->create($name, $email, $hash);

        // Grant super_admin on every active site
        $sites = $this->siteRepository->all();
        $firstSite = null;
        foreach ($sites as $site) {
            $this->userRepository->createMembership($user['id'], $site['id'], 'super_admin');
            if (!$firstSite) {
                $firstSite = $site;
            }
        }

        if (!$firstSite) {
            // Edge case: no sites configured yet — still created the user, show login
            return Response::html($this->renderLoginPage('Account created. Sign in to continue.', 'success'));
        }

        // Auto-login
        $token = $this->authTokenService->issue($user, $firstSite, 'super_admin');

        $response = Response::html('', 302);
        $response->header('Location', '/');
        $response->header('Set-Cookie', 'dashboard_token=' . $token . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=86400');

        return $response;
    }

    private function hasNoUsers(): bool
    {
        return (int) $this->connection->query('SELECT COUNT(*) as c FROM users')->fetch()['c'] === 0;
    }

    public function logout(Request $request): Response
    {
        $response = Response::html('', 302);
        $response->header('Location', '/');
        $response->header('Set-Cookie', 'dashboard_token=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0');

        return $response;
    }

    private function renderDashboardResponse(array $authUser): Response
    {
        $sites = $this->siteRepository->all();

        $contentByType = $this->connection->query(
            'SELECT site_id, type, COUNT(*) as count FROM content GROUP BY site_id, type'
        )->fetchAll();

        $contentByStatus = $this->connection->query(
            'SELECT site_id, status, COUNT(*) as count FROM content GROUP BY site_id, status'
        )->fetchAll();

        $usersBySite = $this->connection->query(
            'SELECT site_id, COUNT(*) as count FROM memberships GROUP BY site_id'
        )->fetchAll();

        $recentActivity = $this->connection->query(
            'SELECT c.title, c.type, c.status, c.updated_at, s.name as site_name
             FROM content c JOIN sites s ON c.site_id = s.id
             ORDER BY c.updated_at DESC LIMIT 10'
        )->fetchAll();

        $totalContent = $this->connection->query('SELECT COUNT(*) as count FROM content')->fetch()['count'];
        $totalUsers = $this->connection->query('SELECT COUNT(*) as count FROM users')->fetch()['count'];
        $walStatus = $this->connection->query('PRAGMA journal_mode')->fetch()['journal_mode'] ?? 'unknown';

        // Index lookups
        $typeMap = [];
        foreach ($contentByType as $row) {
            $typeMap[$row['site_id']][] = $row;
        }
        $statusMap = [];
        foreach ($contentByStatus as $row) {
            $statusMap[$row['site_id']][] = $row;
        }
        $usersMap = [];
        foreach ($usersBySite as $row) {
            $usersMap[$row['site_id']] = $row['count'];
        }

        $html = $this->renderDashboard($sites, $typeMap, $statusMap, $usersMap, $recentActivity, [
            'total_sites' => count($sites),
            'total_content' => $totalContent,
            'total_users' => $totalUsers,
            'wal_status' => $walStatus,
            'user_name' => $authUser['name'] ?? 'Admin',
        ]);

        return Response::html($html);
    }

    private function renderSetupPage(string $error = ''): string
    {
        $errorHtml = '';
        if ($error) {
            $errorHtml = '<div class="error">' . htmlspecialchars($error) . '</div>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Origen — Setup</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; min-height: 100vh; display: flex; flex-direction: column; }
                nav { background: #1a1a2e; color: #fff; padding: 1rem 2rem; }
                nav h1 { font-size: 1.25rem; font-weight: 600; }
                .login-wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; }
                .login-card { background: #fff; border-radius: 8px; padding: 2rem; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
                .login-card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: #1a1a2e; }
                .login-card p { font-size: 0.85rem; color: #666; margin-bottom: 1.5rem; }
                .field { margin-bottom: 1rem; }
                .field label { display: block; font-size: 0.8rem; font-weight: 500; color: #555; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em; }
                .field input { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9rem; outline: none; transition: border-color 0.15s; }
                .field input:focus { border-color: #1a1a2e; }
                button { width: 100%; padding: 0.65rem; background: #1a1a2e; color: #fff; border: none; border-radius: 5px; font-size: 0.9rem; font-weight: 500; cursor: pointer; transition: background 0.15s; }
                button:hover { background: #16213e; }
                .error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 5px; padding: 0.6rem 0.75rem; font-size: 0.85rem; margin-bottom: 1rem; }
            </style>
        </head>
        <body>
            <nav><h1>Origen</h1></nav>
            <div class="login-wrap">
                <div class="login-card">
                    <h2>Create Admin Account</h2>
                    <p>No users exist yet. Create the first super admin to get started.</p>
                    {$errorHtml}
                    <form method="POST" action="/">
                        <div class="field">
                            <label for="name">Name</label>
                            <input type="text" id="name" name="name" required autofocus>
                        </div>
                        <div class="field">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required minlength="6">
                        </div>
                        <div class="field">
                            <label for="password_confirm">Confirm Password</label>
                            <input type="password" id="password_confirm" name="password_confirm" required minlength="6">
                        </div>
                        <button type="submit">Create Account</button>
                    </form>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderLoginPage(string $message = '', string $type = 'error'): string
    {
        $errorHtml = '';
        if ($message) {
            $errorHtml = '<div class="' . $type . '">' . htmlspecialchars($message) . '</div>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Origen — Sign In</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; min-height: 100vh; display: flex; flex-direction: column; }
                nav { background: #1a1a2e; color: #fff; padding: 1rem 2rem; }
                nav h1 { font-size: 1.25rem; font-weight: 600; }
                .login-wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; }
                .login-card { background: #fff; border-radius: 8px; padding: 2rem; width: 100%; max-width: 380px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
                .login-card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1.5rem; color: #1a1a2e; }
                .field { margin-bottom: 1rem; }
                .field label { display: block; font-size: 0.8rem; font-weight: 500; color: #555; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em; }
                .field input { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9rem; outline: none; transition: border-color 0.15s; }
                .field input:focus { border-color: #1a1a2e; }
                button { width: 100%; padding: 0.65rem; background: #1a1a2e; color: #fff; border: none; border-radius: 5px; font-size: 0.9rem; font-weight: 500; cursor: pointer; transition: background 0.15s; }
                button:hover { background: #16213e; }
                .error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 5px; padding: 0.6rem 0.75rem; font-size: 0.85rem; margin-bottom: 1rem; }
                .success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-radius: 5px; padding: 0.6rem 0.75rem; font-size: 0.85rem; margin-bottom: 1rem; }
            </style>
        </head>
        <body>
            <nav><h1>Origen</h1></nav>
            <div class="login-wrap">
                <div class="login-card">
                    <h2>Sign in to Dashboard</h2>
                    {$errorHtml}
                    <form method="POST" action="/">
                        <div class="field">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required autofocus>
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <button type="submit">Sign In</button>
                    </form>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderDashboard(
        array $sites,
        array $typeMap,
        array $statusMap,
        array $usersMap,
        array $recentActivity,
        array $stats,
    ): string {
        $siteCards = '';
        foreach ($sites as $site) {
            $id = $site['id'];
            $name = htmlspecialchars($site['name']);
            $domain = htmlspecialchars($site['domain'] ?? '—');
            $slug = htmlspecialchars($site['slug']);
            $memberCount = $usersMap[$id] ?? 0;

            $typeCounts = '';
            if (!empty($typeMap[$id])) {
                $parts = [];
                foreach ($typeMap[$id] as $row) {
                    $parts[] = (int) $row['count'] . ' ' . htmlspecialchars($row['type']);
                }
                $typeCounts = implode(', ', $parts);
            } else {
                $typeCounts = 'No content';
            }

            $statusCounts = '';
            if (!empty($statusMap[$id])) {
                $parts = [];
                foreach ($statusMap[$id] as $row) {
                    $parts[] = (int) $row['count'] . ' ' . htmlspecialchars($row['status']);
                }
                $statusCounts = implode(' · ', $parts);
            } else {
                $statusCounts = '—';
            }

            // Find most recent update for this site
            $latestUpdate = '—';
            foreach ($recentActivity as $activity) {
                if ($activity['site_name'] === $site['name']) {
                    $latestUpdate = htmlspecialchars($activity['updated_at']);
                    break;
                }
            }

            $siteCards .= <<<CARD
            <div class="card">
                <div class="card-header">
                    <h3>{$name}</h3>
                    <span class="slug">{$slug}</span>
                </div>
                <div class="card-meta">{$domain}</div>
                <div class="card-row"><span class="label">Content</span> <span>{$typeCounts}</span></div>
                <div class="card-row"><span class="label">Status</span> <span>{$statusCounts}</span></div>
                <div class="card-row"><span class="label">Members</span> <span>{$memberCount}</span></div>
                <div class="card-row"><span class="label">Last update</span> <span>{$latestUpdate}</span></div>
            </div>
            CARD;
        }

        $activityRows = '';
        foreach ($recentActivity as $row) {
            $title = htmlspecialchars($row['title'] ?? 'Untitled');
            $type = htmlspecialchars($row['type']);
            $status = htmlspecialchars($row['status']);
            $siteName = htmlspecialchars($row['site_name']);
            $updatedAt = htmlspecialchars($row['updated_at']);

            $statusClass = match ($row['status']) {
                'published' => 'status-published',
                'draft' => 'status-draft',
                'review' => 'status-review',
                'archived' => 'status-archived',
                default => '',
            };

            $activityRows .= <<<ROW
            <tr>
                <td>{$title}</td>
                <td>{$type}</td>
                <td><span class="badge {$statusClass}">{$status}</span></td>
                <td>{$siteName}</td>
                <td>{$updatedAt}</td>
            </tr>
            ROW;
        }

        if ($activityRows === '') {
            $activityRows = '<tr><td colspan="5" style="text-align:center;color:#888;">No activity yet</td></tr>';
        }

        $totalSites = (int) $stats['total_sites'];
        $totalContent = (int) $stats['total_content'];
        $totalUsers = (int) $stats['total_users'];
        $walStatus = htmlspecialchars($stats['wal_status']);
        $userName = htmlspecialchars($stats['user_name']);

        if ($totalSites === 0) {
            $mainContent = $this->renderGettingStarted();
        } else {
            $mainContent = '<h2>Sites</h2>'
                . '<div class="grid">' . $siteCards . '</div>'
                . '<h2>Recent Activity</h2>'
                . '<table><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Site</th><th>Updated</th></tr></thead>'
                . '<tbody>' . $activityRows . '</tbody></table>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Origen — System Dashboard</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; }
                nav { background: #1a1a2e; color: #fff; padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; }
                nav h1 { font-size: 1.25rem; font-weight: 600; }
                nav .meta { font-size: 0.85rem; color: #aaa; display: flex; align-items: center; gap: 1.5rem; }
                nav .meta a { color: #aaa; text-decoration: none; font-size: 0.8rem; border: 1px solid #444; padding: 0.25rem 0.75rem; border-radius: 4px; transition: border-color 0.15s, color 0.15s; }
                nav .meta a:hover { color: #fff; border-color: #888; }
                .container { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; }
                .stats { display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; }
                .stat { background: #fff; border-radius: 8px; padding: 1.25rem 1.5rem; flex: 1; min-width: 140px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
                .stat .value { font-size: 1.75rem; font-weight: 700; color: #1a1a2e; }
                .stat .label { font-size: 0.8rem; color: #888; margin-top: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em; }
                h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #1a1a2e; }
                .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; margin-bottom: 2.5rem; }
                .card { background: #fff; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
                .card-header { display: flex; align-items: baseline; gap: 0.5rem; margin-bottom: 0.5rem; }
                .card-header h3 { font-size: 1rem; font-weight: 600; }
                .slug { font-size: 0.75rem; color: #888; background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
                .card-meta { font-size: 0.8rem; color: #666; margin-bottom: 0.75rem; }
                .card-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.3rem 0; border-top: 1px solid #f0f0f0; }
                .card-row .label { color: #888; }
                table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
                th, td { padding: 0.65rem 1rem; text-align: left; font-size: 0.85rem; }
                th { background: #fafafa; font-weight: 600; color: #555; border-bottom: 2px solid #eee; }
                td { border-bottom: 1px solid #f0f0f0; }
                tr:last-child td { border-bottom: none; }
                .badge { font-size: 0.75rem; padding: 2px 8px; border-radius: 3px; font-weight: 500; }
                .status-published { background: #e6f4ea; color: #1a7f37; }
                .status-draft { background: #fff3cd; color: #856404; }
                .status-review { background: #cff4fc; color: #055160; }
                .status-archived { background: #f0f0f0; color: #666; }
                .guide { background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
                .guide h2 { margin-bottom: 0.5rem; }
                .guide > p { color: #666; font-size: 0.9rem; margin-bottom: 1.5rem; }
                .guide h3 { font-size: 0.95rem; font-weight: 600; color: #1a1a2e; margin: 1.5rem 0 0.5rem; }
                .guide h3:first-of-type { margin-top: 0; }
                .guide p, .guide li { font-size: 0.85rem; line-height: 1.6; color: #444; }
                .guide ol, .guide ul { padding-left: 1.25rem; margin-bottom: 0.75rem; }
                .guide li { margin-bottom: 0.35rem; }
                .guide pre { background: #1a1a2e; color: #e0e0e0; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: 0.8rem; line-height: 1.5; margin: 0.75rem 0; }
                .guide code { font-family: 'SF Mono', Menlo, Consolas, monospace; font-size: 0.8rem; }
                .guide p code, .guide li code { background: #f0f0f0; padding: 1px 5px; border-radius: 3px; color: #1a1a2e; }
                .step { border-left: 3px solid #1a1a2e; padding-left: 1.25rem; margin-bottom: 1.5rem; }
            </style>
        </head>
        <body>
            <nav>
                <h1>Origen — System Dashboard</h1>
                <span class="meta">
                    <span>{$userName} · WAL: {$walStatus}</span>
                    <a href="/logout">Sign out</a>
                </span>
            </nav>
            <div class="container">
                <div class="stats">
                    <div class="stat"><div class="value">{$totalSites}</div><div class="label">Sites</div></div>
                    <div class="stat"><div class="value">{$totalContent}</div><div class="label">Content Entries</div></div>
                    <div class="stat"><div class="value">{$totalUsers}</div><div class="label">Users</div></div>
                </div>

                {$mainContent}
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderGettingStarted(): string
    {
        return '<div class="guide">'
. '<h2>Getting Started</h2>'
. '<p>No sites are connected to this Origen server yet. Follow the steps below to create your first Rufinus site.</p>'

. '<div class="step">'
. '<h3>1. Create a site configuration</h3>'
. '<p>Each site lives in a directory under <code>content/</code>. The directory name becomes the site slug. Create a <code>_site.yaml</code> file inside it:</p>'
. '<pre>mkdir -p content/my-site</pre>'
. '<p>Then create <code>content/my-site/_site.yaml</code>:</p>'
. '<pre>name: My Site
domain: localhost
api_key: htx-my-site-001
active: true
settings: {}</pre>'
. '<p>The <code>api_key</code> is a shared secret between Rufinus and Origen. Pick something unique per site.</p>'
. '</div>'

. '<div class="step">'
. '<h3>2. Create your first page</h3>'
. '<p>Rufinus uses <code>.htx</code> files for pages. They live in <code>rufinus/site/</code>. The file path maps directly to the URL.</p>'
. '<p>Create <code>rufinus/site/index.htx</code> for the home page:</p>'
. '<pre>&lt;htx&gt;
  &lt;h1&gt;Welcome to My Site&lt;/h1&gt;
  &lt;p&gt;This is a Rufinus-powered page.&lt;/p&gt;
&lt;/htx&gt;</pre>'
. '<p>For a data-driven page that loads content from Origen, use meta directives:</p>'
. '<pre>&lt;htx:type&gt;article&lt;/htx:type&gt;
&lt;htx:order&gt;recent&lt;/htx:order&gt;
&lt;htx:howmany&gt;10&lt;/htx:howmany&gt;

&lt;htx&gt;
  &lt;htx:each&gt;
    &lt;article&gt;
      &lt;h2&gt;&lt;a href="/articles/__slug__"&gt;__title__&lt;/a&gt;&lt;/h2&gt;
      &lt;p&gt;__body__&lt;/p&gt;
    &lt;/article&gt;
  &lt;/htx:each&gt;

  &lt;htx:none&gt;
    &lt;p&gt;No articles yet.&lt;/p&gt;
  &lt;/htx:none&gt;
&lt;/htx&gt;</pre>'
. '</div>'

. '<div class="step">'
. '<h3>3. Add a layout</h3>'
. '<p>Create <code>rufinus/site/_layout.htx</code> to wrap every page in a shared HTML shell:</p>'
. '<pre>&lt;!DOCTYPE html&gt;
&lt;html lang="en"&gt;
&lt;head&gt;
    &lt;meta charset="UTF-8"&gt;
    &lt;title&gt;My Site&lt;/title&gt;
&lt;/head&gt;
&lt;body&gt;
    &lt;nav&gt;My Site&lt;/nav&gt;
    __content__
&lt;/body&gt;
&lt;/html&gt;</pre>'
. '<p>The <code>__content__</code> placeholder is replaced with the page output. Layouts nest — put a <code>_layout.htx</code> in a subdirectory to wrap only that section.</p>'
. '</div>'

. '<div class="step">'
. '<h3>4. Dynamic routes</h3>'
. '<p>Use bracket notation for URL parameters. For example, <code>rufinus/site/articles/[slug].htx</code> matches <code>/articles/my-post</code> and captures <code>slug = "my-post"</code>.</p>'
. '<pre>&lt;htx:type&gt;article&lt;/htx:type&gt;
&lt;htx:howmany&gt;1&lt;/htx:howmany&gt;

&lt;htx&gt;
  &lt;htx:each&gt;
    &lt;h1&gt;__title__&lt;/h1&gt;
    &lt;div&gt;__body__&lt;/div&gt;
  &lt;/htx:each&gt;

  &lt;htx:none&gt;
    &lt;p&gt;Not found.&lt;/p&gt;
  &lt;/htx:none&gt;
&lt;/htx&gt;</pre>'
. '</div>'

. '<div class="step">'
. '<h3>5. Configure the Rufinus entry point</h3>'
. '<p>Edit <code>rufinus/site/serve.php</code> to point at your site\'s API key and the Origen server URL:</p>'
. '<pre>&lt;?php
require_once dirname(__DIR__, 2) . \'/vendor/autoload.php\';

use Rufinus\Runtime\RequestHandler;

(new RequestHandler())-&gt;handle(
    $_SERVER[\'REQUEST_METHOD\'],
    $_SERVER[\'REQUEST_URI\'],
    getallheaders(),
    __DIR__,                        // site root
    \'http://localhost:8080\',        // Origen API URL
    \'htx-my-site-001\'              // your site API key
);</pre>'
. '</div>'

. '<div class="step">'
. '<h3>6. Start both servers</h3>'
. '<p>From the <code>cms/</code> directory, run:</p>'
. '<pre>php hcms serve:all</pre>'
. '<p>This starts Origen on <code>:8080</code> and Rufinus on <code>:8081</code>. You can also start them separately:</p>'
. '<pre>php hcms serve          # Origen API on :8080
php hcms serve:site     # Rufinus site on :8081</pre>'
. '<p>Visit <code>http://localhost:8081</code> to see your site. Restart Origen after creating the <code>_site.yaml</code> so it discovers the new site.</p>'
. '</div>'

. '<div class="step">'
. '<h3>7. Authentication</h3>'
. '<p>As a super admin, you are automatically granted access to every site connected to this Origen instance. Rufinus protects <code>/admin/*</code> routes with session cookies — log in at <code>/admin/login</code> on any Rufinus site using your Origen credentials.</p>'
. '<p>The <code>htx_session</code> cookie is scoped per site. Your super admin role carries across all sites.</p>'
. '</div>'

. '</div>';
    }
}
