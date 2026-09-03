<?php

/**
 * VpnHood — "is this install current, and do its VpnHood packages fit each other?"
 *
 * WHMCS has no update channel for third-party modules: its own updater covers the
 * core only, so an install can sit years behind and nobody ever finds out. This
 * answers that for every VpnHood package present — on the admin dashboard as a
 * widget, and on each of our addon pages through the same renderer.
 *
 * It TELLS, and never installs. No code here downloads a package, writes a module
 * file or upgrades anything; installing stays a deliberate human act.
 *
 * ── This file is SHIPPED IDENTICALLY by every VpnHood package ──────────────────
 * (VpnHood.WHMCS, VpnHood.WHMCS.Partner, VpnHood.WHMCS.Iap, VpnHood.WHMCS.SignIn)
 * always at this same path, so whichever package an install has, the notice is
 * there — a partner box with no hub, an iap-only box, any combination. Same path
 * means the filesystem itself de-duplicates: the last extract wins and every copy
 * behaves the same, because nothing here knows anything about a specific package.
 * KEEP THE COPIES IN STEP; if you change one, change all four.
 *
 * Discovery is by `vhcontract.json`, a small static file each module ships next to
 * itself: who it is, which package and GitHub repo it comes from, and the
 * cross-module contract it provides or requires. A static file rather than a
 * function to call is the point — any module can read any other module's
 * declaration without loading its code, which is what makes this work across
 * repos that ship independently.
 *
 * The contract replaces what the version pin used to guarantee. While vpnhoodiap
 * shipped inside the hub package, "a tested pair" was true by construction; once
 * packages install separately, any pair can occur, so the requirement is declared
 * and a mismatch is shown in red instead of being discovered by a broken checkout.
 * Bump a `provides` level only when the reused surface changes in a way an older
 * consumer would not survive.
 *
 * Network: NEVER from a page render. The daily cron refreshes the cache and an
 * admin can force a refresh from an addon page; the widget reads what is cached
 * and nothing else. A dashboard that waits on github.com is one people learn to
 * hate.
 *
 * Lives in modules/widgets/, which WHMCS loads on its own — that directory is
 * SHARED with WHMCS's own widgets, so it is only ever overlaid, never replaced.
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\AbstractWidget;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!class_exists('VpnHoodUpdateCheck')) {
    class VpnHoodUpdateCheck
    {

        /** Where the module manifests live, relative to /modules. */
        private const SCAN_DIRS = ['servers', 'addons'];

        /** How long a successful answer stands, and how long a failed one is not retried. */
        private const CACHE_TTL_OK = 86400;
        private const CACHE_TTL_FAIL = 3600;

        private const CACHE_MODULE = 'vpnhoodupdates';
        private const CACHE_SETTING = 'updateCheckCache';

        /** /modules — this file is …/modules/widgets/vpnhoodupdates.php */
        private static function modulesDir(): string
        {
            return dirname(__DIR__);
        }

        /**
         * Every VpnHood module installed here, with its version and declaration.
         *
         * @return array<int, array{module:string,label:string,kind:string,repo:string,
         *                          package:string,version:?string,provides:array,requires:array}>
         */
        public static function inventory(): array
        {
            $found = [];
            foreach (self::SCAN_DIRS as $kindDir) {
                foreach ((array) glob(self::modulesDir() . '/' . $kindDir . '/*/vhcontract.json') as $manifest) {
                    $declaration = self::readJson($manifest);
                    if ($declaration === null || !isset($declaration['module'])) {
                        continue;
                    }
                    $module = (string) $declaration['module'];
                    $found[] = [
                        'module'       => $module,
                        'label'        => (string) ($declaration['label'] ?? $module),
                        'kind'         => (string) ($declaration['kind'] ?? ($kindDir === 'servers' ? 'server' : 'addon')),
                        'repo'         => (string) ($declaration['repo'] ?? ''),
                        'package'      => (string) ($declaration['package'] ?? ''),
                        'packageLabel' => (string) ($declaration['packageLabel'] ?? ($declaration['label'] ?? $module)),
                        'version'  => self::installedVersion($module, dirname($manifest), (string) ($declaration['kind'] ?? '')),
                        'provides' => (array) ($declaration['provides'] ?? []),
                        'requires' => (array) ($declaration['requires'] ?? []),
                    ];
                }
            }
            usort($found, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
            return $found;
        }

        /**
         * The version ON DISK — the code that would actually run, which is the honest
         * answer to "what is installed". A provisioning module keeps it in whmcs.json;
         * an addon keeps it in its own `_config()`, so the module file is loaded (once,
         * guarded — WHMCS may already have loaded it) and asked.
         */
        private static function installedVersion(string $module, string $dir, string $kind): ?string
        {
            if ($kind === 'addon') {
                $configFn = $module . '_config';
                if (!function_exists($configFn)) {
                    $file = $dir . '/' . $module . '.php';
                    if (!is_readable($file)) {
                        return null;
                    }
                    require_once $file;
                }
                if (!function_exists($configFn)) {
                    return null;
                }
                $config = (array) $configFn();
                return isset($config['version']) ? (string) $config['version'] : null;
            }

            $manifest = self::readJson($dir . '/whmcs.json');
            return isset($manifest['version']) ? (string) $manifest['version'] : null;
        }

        /**
         * Contract verdicts, one line per unmet requirement. A requirement whose
         * provider is not installed at all is NOT a problem: `vpnhoodiap` runs on a
         * partner install where `vpnhoodstore` never exists, and degrades on purpose.
         * Only a provider that IS here and is too old is a mismatch.
         *
         * @param array $inventory result of inventory()
         * @return array<int, string> human-readable problems, empty when all is well
         */
        public static function contractProblems(array $inventory): array
        {
            $provided = [];
            foreach ($inventory as $row) {
                foreach ($row['provides'] as $name => $level) {
                    $provided[(string) $name] = max((int) $level, $provided[(string) $name] ?? 0);
                }
            }

            $problems = [];
            foreach ($inventory as $row) {
                foreach ($row['requires'] as $name => $level) {
                    $name = (string) $name;
                    if (!isset($provided[$name])) {
                        continue; // provider not installed here — a supported shape, not a fault
                    }
                    if ($provided[$name] < (int) $level) {
                        $problems[] = sprintf(
                            '%s needs "%s" contract %d, but this install provides %d — update the other package before relying on it.',
                            $row['label'], $name, (int) $level, $provided[$name]
                        );
                    }
                }
            }
            return $problems;
        }

        /**
         * The cached "latest release" answer per repo. Shape:
         * ['checkedAt' => unix, 'repos' => [repo => ['tag' => '1.2.4'|null, 'error' => ?string, 'at' => unix]]]
         */
        public static function cache(): array
        {
            $raw = (string) Capsule::table('tbladdonmodules')
                ->where('module', self::CACHE_MODULE)->where('setting', self::CACHE_SETTING)->value('value');
            $cache = json_decode($raw, true);
            return is_array($cache) ? $cache : ['checkedAt' => 0, 'repos' => []];
        }

        /**
         * Ask GitHub for the latest release of every repo the inventory names, and store
         * the answer. Called by the daily cron and by the admin's explicit "Check now" —
         * never by a page render.
         *
         * $force ignores the TTL. Returns the cache as written.
         */
        public static function refresh(bool $force = false): array
        {
            $cache = self::cache();
            $repos = [];
            foreach (self::inventory() as $row) {
                if ($row['repo'] !== '') {
                    $repos[$row['repo']] = true;
                }
            }

            $fetched = 0;
            foreach (array_keys($repos) as $repo) {
                $previous = $cache['repos'][$repo] ?? null;
                if (!$force && $previous !== null) {
                    $age = time() - (int) ($previous['at'] ?? 0);
                    $ttl = ($previous['tag'] ?? null) !== null ? self::CACHE_TTL_OK : self::CACHE_TTL_FAIL;
                    if ($age < $ttl) {
                        continue; // still fresh; a failure is not retried every minute either
                    }
                }
                $cache['repos'][$repo] = self::fetchLatest($repo) + ['at' => time()];
                $fetched++;
            }

            // repos that no longer exist on this install fall out of the cache
            $cache['repos'] = array_intersect_key($cache['repos'], $repos);
            $cache['checkedAt'] = time();

            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => self::CACHE_MODULE, 'setting' => self::CACHE_SETTING],
                ['value' => json_encode($cache)]
            );

            // Say it once in the activity log, and only on a refresh that actually
            // asked GitHub: every installed package registers the daily hook, so
            // without this guard a four-package install would log the same news four
            // times. An admin who never opens the dashboard still gets a trail.
            if ($fetched > 0) {
                $behind = self::status()['behind'];
                if ($behind > 0) {
                    try {
                        localAPI('LogActivity', ['description' =>
                            "vpnhood: {$behind} VpnHood package(s) have a newer release available — see the VpnHood! Modules dashboard widget."]);
                    } catch (\Throwable) {
                        // the notice on the dashboard is the durable one
                    }
                }
            }
            return $cache;
        }

        /**
         * The latest published release tag of a public repo, as a bare version.
         *
         * Unauthenticated on purpose: this runs on customer installs we do not own, so
         * it must work with no credential of ours anywhere near it. GitHub allows 60
         * such calls an hour per IP and we make one a day per repo.
         *
         * @return array{tag:?string,error:?string}
         */
        private static function fetchLatest(string $repo): array
        {
            $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT      => 'VpnHood-WHMCS-UpdateCheck',
                CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false || $error !== '') {
                return ['tag' => null, 'error' => 'network: ' . ($error !== '' ? $error : 'no response')];
            }
            if ($status !== 200) {
                return ['tag' => null, 'error' => 'github returned HTTP ' . $status];
            }
            $json = json_decode((string) $body, true);
            $tag = is_array($json) ? (string) ($json['tag_name'] ?? '') : '';
            if ($tag === '') {
                return ['tag' => null, 'error' => 'no tag in the release answer'];
            }
            return ['tag' => ltrim($tag, 'vV'), 'error' => null];
        }

        /**
         * The whole picture in one call, reported PER PACKAGE — a package is what an
         * admin actually installs, and every module inside one carries the same stamped
         * version, so four rows saying the same thing would be four times the noise.
         *
         * The exception is the reason the per-module versions are still carried: when
         * they DISAGREE, the package is half-deployed (an extract that missed a folder,
         * a hand-copied module), which is a real fault worth naming — such a package is
         * flagged "mixed" and lists what each module runs.
         *
         * @return array{packages:array,problems:array,checkedAt:int,behind:int}
         */
        public static function status(): array
        {
            $cache = self::cache();
            $packages = [];

            foreach (self::inventory() as $row) {
                $key = $row['repo'] . '|' . $row['package'];
                if (!isset($packages[$key])) {
                    $cached = $cache['repos'][$row['repo']] ?? null;
                    $packages[$key] = [
                        'label'    => $row['packageLabel'],
                        'repo'     => $row['repo'],
                        'package'  => $row['package'],
                        'latest'   => $cached['tag'] ?? null,
                        'error'    => $cached['error'] ?? null,
                        'modules'  => [],
                    ];
                }
                $packages[$key]['modules'][$row['label']] = $row['version'];
            }

            $behind = 0;
            foreach ($packages as &$package) {
                $versions = array_unique(array_filter(array_values($package['modules']), static fn($v) => $v !== null));
                $package['mixed'] = count($versions) > 1;
                // The package is only as current as its OLDEST module: a half-deployed
                // package that is also behind must say both things, not pick one.
                usort($versions, 'version_compare');
                $package['version'] = $versions === [] ? null : reset($versions);
                $package['outdated'] = $package['version'] !== null && $package['latest'] !== null
                    && version_compare($package['latest'], $package['version'], '>');
                if ($package['outdated']) {
                    $behind++;
                }
            }
            unset($package);

            return [
                'packages'  => array_values($packages),
                'problems'  => self::contractProblems(self::inventory()),
                'checkedAt' => (int) ($cache['checkedAt'] ?? 0),
                'behind'    => $behind,
            ];
        }

        /**
         * The table both faces show — the dashboard widget and the addon page. One
         * implementation on purpose: two renderings of the same facts drift, and the
         * one an admin happens to look at would be the stale one.
         */
        public static function renderTable(array $status): string
        {
            $out = '';
            foreach ($status['problems'] as $problem) {
                $out .= '<div class="alert alert-danger" style="margin:8px 0;"><strong>Module mismatch:</strong> '
                      . self::escape($problem) . '</div>';
            }

            $rows = '';
            foreach ($status['packages'] as $package) {
                if ($package['mixed']) {
                    // Never let a half-deployed package read as "up to date": whatever the
                    // release number says, this install is running a mixture nobody tested.
                    $state = '<span class="label label-danger">half-deployed</span> re-extract '
                           . self::escape($package['package'] !== '' ? $package['package'] : 'the package');
                    if ($package['outdated']) {
                        $state .= ' (' . self::escape((string) $package['latest']) . ' is out)';
                    }
                } elseif ($package['outdated']) {
                    $state = '<span class="label label-warning">update available</span> '
                           . '<a href="https://github.com/' . self::escape($package['repo']) . '/releases/latest"'
                           . ' target="_blank" rel="noopener">' . self::escape((string) $package['latest']) . '</a>';
                } elseif ($package['error'] !== null) {
                    $state = '<span class="text-muted" title="' . self::escape((string) $package['error']) . '">check failed</span>';
                } elseif ($package['latest'] === null) {
                    $state = '<span class="text-muted">not checked yet</span>';
                } else {
                    $state = '<span class="text-success">up to date</span>';
                }

                $installed = $package['mixed']
                    ? '<span class="label label-danger">mixed</span>'
                    : '<code>' . self::escape((string) ($package['version'] ?? 'unknown')) . '</code>';

                $detail = '';
                if ($package['mixed']) {
                    $parts = [];
                    foreach ($package['modules'] as $label => $version) {
                        $parts[] = self::escape($label) . ' <code>' . self::escape((string) ($version ?? 'unknown')) . '</code>';
                    }
                    $detail = '<div class="text-muted" style="font-size:11px;">half-deployed package: '
                            . implode(', ', $parts) . '</div>';
                }

                $rows .= '<tr><td>' . self::escape($package['label'])
                       . ($package['package'] !== '' ? ' <span class="text-muted">(' . self::escape($package['package']) . ')</span>' : '')
                       . $detail . '</td>'
                       . '<td>' . $installed . '</td><td>' . $state . '</td></tr>';
            }

            if ($rows === '') {
                return $out . '<p>No VpnHood module declarations found on this install.</p>';
            }

            return $out
                 . '<table class="table table-condensed" style="width:auto;">'
                 . '<thead><tr><th>Package</th><th>Installed</th><th>Status</th></tr></thead>'
                 . '<tbody>' . $rows . '</tbody></table>';
        }

        /** When the check last ran, said the way a person reads it. */
        public static function lastCheckedText(array $status): string
        {
            return $status['checkedAt'] > 0
                ? 'Last checked ' . self::escape(date('Y-m-d H:i', $status['checkedAt']))
                : 'No check has run yet — it runs with the daily cron.';
        }

        private static function escape(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        /** @return array<string,mixed>|null */
        private static function readJson(string $file): ?array
        {
            if (!is_readable($file)) {
                return null;
            }
            // WHMCS' own tooling writes some of these manifests, sometimes with a UTF-8 BOM.
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file));
            $json = json_decode((string) $raw, true);
            return is_array($json) ? $json : null;
        }
    }
}

if (!class_exists('VpnHoodUpdatesWidget')) {
    class VpnHoodUpdatesWidget extends AbstractWidget
    {
        protected $title = 'VpnHood! Modules';
        protected $description = 'Installed VpnHood packages, available updates and contract checks.';
        protected $weight = 150;
        protected $columns = 1;
        protected $cache = false;

        public function getData()
        {
            // A widget must never be the reason an admin cannot open the dashboard.
            try {
                return VpnHoodUpdateCheck::status();
            } catch (\Throwable $e) {
                return ['fatal' => $e->getMessage()];
            }
        }

        public function generateOutput($data)
        {
            if (isset($data['fatal'])) {
                return '<div class="widget-content-padded">'
                    . htmlspecialchars((string) $data['fatal'], ENT_QUOTES, 'UTF-8') . '</div>';
            }

            return VpnHoodUpdateCheck::renderTable($data)
                . '<div class="widget-content-padded text-muted" style="font-size:11px;">'
                . VpnHoodUpdateCheck::lastCheckedText($data)
                . ' — updates are installed by hand; this only reports them.</div>';
        }
    }

    add_hook('AdminHomeWidgets', 1, static function () {
        return new VpnHoodUpdatesWidget();
    });
}
