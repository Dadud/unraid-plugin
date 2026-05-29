<?php
// health-lib.php — shared health checks for GoW plugin UI and API.

function gow_health_item($level, $label, $hint = '') {
    return [
        'level' => $level,
        'label' => $label,
        'hint'  => $hint,
    ];
}

function gow_health_summary(array $checks) {
    $has_fail = false;
    $has_warn = false;
    foreach ($checks as $check) {
        if ($check['level'] === 'fail') {
            $has_fail = true;
        } elseif ($check['level'] === 'warn') {
            $has_warn = true;
        }
    }
    if ($has_fail) {
        return 'unhealthy';
    }
    if ($has_warn) {
        return 'degraded';
    }
    return 'healthy';
}

function gow_run_health_checks(array $cfg) {
    $checks = [];
    $appdata = rtrim($cfg['APPDATA'] ?? '/mnt/user/appdata/gow', '/');
    $compose = $appdata . '/docker-compose.yml';
    $render_node = $cfg['RENDER_NODE'] ?? '';
    $vendor = $cfg['GPU_VENDOR'] ?? '';
    $port = $cfg['WOLF_DEN_PORT'] ?? '8080';
    if (!preg_match('/^[0-9]+$/', (string)$port) || (int)$port < 1 || (int)$port > 65535) {
        $port = '8080';
    }

    exec('docker info >/dev/null 2>&1', $out, $ret);
    $checks[] = gow_health_item(
        $ret === 0 ? 'ok' : 'fail',
        'Docker daemon',
        $ret === 0 ? '' : 'Enable Docker in Unraid Settings.'
    );

    $wolf_status = 'not found';
    exec("docker inspect --format '{{.State.Status}}' wolf 2>/dev/null", $wolf_out, $wolf_ret);
    if ($wolf_ret === 0 && !empty($wolf_out[0])) {
        $wolf_status = $wolf_out[0];
    }
    $checks[] = gow_health_item(
        $wolf_status === 'running' ? 'ok' : 'fail',
        'Wolf container',
        $wolf_status === 'running' ? '' : "Status: {$wolf_status}. Try Start or check logs."
    );

    $den_status = 'not found';
    exec("docker inspect --format '{{.State.Status}}' wolf-den 2>/dev/null", $den_out, $den_ret);
    if ($den_ret === 0 && !empty($den_out[0])) {
        $den_status = $den_out[0];
    }
    $checks[] = gow_health_item(
        $den_status === 'running' ? 'ok' : 'fail',
        'Wolf Den container',
        $den_status === 'running' ? '' : "Status: {$den_status}."
    );

    $oom = false;
    exec("docker inspect --format '{{.State.OOMKilled}}' wolf 2>/dev/null", $oom_out, $oom_ret);
    if ($oom_ret === 0 && ($oom_out[0] ?? '') === 'true') {
        $oom = true;
    }
    $checks[] = gow_health_item(
        $oom ? 'fail' : 'ok',
        'Wolf memory (OOM)',
        $oom ? 'Wolf was OOM-killed — see FAQ for fixes.' : ''
    );

    $cfg_file = $appdata . '/cfg/config.toml';
    $identity_ok = is_file($appdata . '/cfg/config.toml')
        && is_file($appdata . '/cfg/key.pem')
        && is_file($appdata . '/cfg/cert.pem');
    $checks[] = gow_health_item(
        $identity_ok ? 'ok' : 'warn',
        'Pairing identity on disk',
        $identity_ok ? '' : 'Missing key/cert or config in appdata/cfg/.'
    );

    $paired = null;
    if (is_readable($cfg_file)) {
        $text = file_get_contents($cfg_file);
        if ($text !== false) {
            $paired = preg_match_all('/^\[\[paired_clients\]\]/m', $text, $m) ? count($m[0]) : 0;
        }
    }
    if ($paired === null) {
        $checks[] = gow_health_item('warn', 'Moonlight clients paired', 'Config not readable yet.');
    } elseif ($paired === 0) {
        $checks[] = gow_health_item('warn', 'Moonlight clients paired', 'None yet — use Wolf Den pairing.');
    } else {
        $checks[] = gow_health_item('ok', 'Moonlight clients paired', "{$paired} client(s).");
    }

    if ($render_node === '') {
        $checks[] = gow_health_item('fail', 'GPU render node configured', 'Reconfigure and select a GPU.');
    } elseif (!is_readable($render_node)) {
        $checks[] = gow_health_item('fail', 'GPU render node present', "Missing {$render_node}");
    } else {
        $checks[] = gow_health_item('ok', 'GPU render node present', $render_node);
    }

    if ($vendor === 'NVIDIA') {
        $modeset = is_readable('/sys/module/nvidia_drm/parameters/modeset')
            && trim(@file_get_contents('/sys/module/nvidia_drm/parameters/modeset')) === 'Y';
        $checks[] = gow_health_item(
            $modeset ? 'ok' : 'fail',
            'NVIDIA Wayland (nvidia_drm.modeset)',
            $modeset ? '' : 'Set options nvidia_drm modeset=1 in System Drivers.'
        );
    }

    $stale = 0;
    exec('docker ps -a --filter "status=exited" --format "{{.Names}}" 2>/dev/null', $stale_out);
    foreach ($stale_out as $name) {
        $name = ltrim(trim($name), '/');
        if ($name !== '' && $name !== 'wolf' && $name !== 'wolf-den' && strncmp($name, 'Wolf', 4) === 0) {
            $stale++;
        }
    }
    $checks[] = gow_health_item(
        $stale === 0 ? 'ok' : 'warn',
        'Stale Wolf session containers',
        $stale === 0 ? '' : "{$stale} exited — use Cleanup stale sessions."
    );

    $lib_checks = gow_library_mount_health_checks($cfg);
    foreach ($lib_checks as $lib_check) {
        $checks[] = $lib_check;
    }

    return [
        'summary' => gow_health_summary($checks),
        'checks'  => $checks,
        'at'      => gmdate('c'),
    ];
}

// Canonical app titles — keep in sync with APP_PRESETS in apply-mount-presets.py.
function gow_preset_app_titles() {
    return [
        'RetroArch', 'Pegasus', 'EmulationStation', 'Steam', 'Lutris',
        'Prismlauncher', 'Kodi', 'Desktop (xfce)', 'Heroic Games Launcher',
    ];
}

function gow_title_aliases() {
    return [
        'esde' => 'EmulationStation',
        'emulationstationdesktopedition' => 'EmulationStation',
        'emustation' => 'EmulationStation',
        'retroarch' => 'RetroArch',
        'xfce' => 'Desktop (xfce)',
        'desktop' => 'Desktop (xfce)',
        'xfcedesktop' => 'Desktop (xfce)',
        'heroic' => 'Heroic Games Launcher',
        'heroicgameslauncher' => 'Heroic Games Launcher',
        'prism' => 'Prismlauncher',
        'prismlauncher' => 'Prismlauncher',
    ];
}

function gow_resolve_preset_title($title) {
    foreach (gow_preset_app_titles() as $canonical) {
        if ($title === $canonical || gow_normalize_app_title($title) === gow_normalize_app_title($canonical)) {
            return $canonical;
        }
    }
    $norm = gow_normalize_app_title($title);
    $aliases = gow_title_aliases();
    return $aliases[$norm] ?? null;
}

// Library key → apps that need a mount when the library is configured.
function gow_library_app_spec() {
    return [
        'ROMS_LIBRARY' => [
            'label' => 'ROMs',
            'dest'  => '/ROMs',
            'apps'  => ['EmulationStation', 'RetroArch', 'Pegasus'],
        ],
        'BIOS_LIBRARY' => [
            'label' => 'BIOS',
            'dest'  => '/bioses',
            'apps'  => ['EmulationStation', 'Pegasus'],
        ],
        'MEDIA_LIBRARY' => [
            'label' => 'Media',
            'dest'  => '/media',
            'apps'  => ['EmulationStation', 'Kodi'],
        ],
        'STEAM_LIBRARY' => [
            'label' => 'Steam',
            'dest'  => '/home/retro/.local/share/Steam',
            'apps'  => ['Steam'],
        ],
        'GAMES_LIBRARY' => [
            'label' => 'PC games',
            'dest'  => '/games',
            'apps'  => ['Prismlauncher', 'Heroic Games Launcher', 'Desktop (xfce)'],
        ],
        'LUTRIS_LIBRARY' => [
            'label' => 'Lutris',
            'dest'  => '/var/lutris',
            'apps'  => ['Lutris'],
        ],
    ];
}

function gow_block_has_mount_dest($block, $dest) {
    $quoted = preg_quote($dest, '~');
    return (bool)preg_match('~["\'][^"\']+:' . $quoted . '(?::[a-z]+)?["\']~', $block);
}

function gow_config_apps_by_title($text) {
    $blocks = preg_split('/(?=^\[\[profiles\.apps\]\])/m', $text);
    $by_title = [];
    foreach ($blocks as $block) {
        if (!preg_match('/^\s*\[\[profiles\.apps\]\]/', $block)) {
            continue;
        }
        if (!preg_match('/^title\s*=\s*["\']([^"\']+)["\']/m', $block, $tm)) {
            continue;
        }
        $title = $tm[1];
        $canonical = gow_resolve_preset_title($title);
        if ($canonical === null) {
            continue;
        }
        if (!isset($by_title[$canonical])) {
            $by_title[$canonical] = [
                'title'  => $title,
                'block'  => $block,
            ];
        }
    }
    return $by_title;
}

// Full audit: each configured library vs app runner mounts in config.toml.
function gow_library_mount_audit(array $cfg) {
    $appdata = rtrim($cfg['APPDATA'] ?? '/mnt/user/appdata/gow', '/');
    $audit = [
        'config_path'     => $appdata . '/cfg/config.toml',
        'config_readable' => false,
        'libraries'       => [],
        'has_any_library' => false,
        'all_wired'       => true,
    ];
    $spec = gow_library_app_spec();
    foreach ($spec as $cfg_key => $meta) {
        $path = rtrim(trim($cfg[$cfg_key] ?? ''), '/');
        if ($path === '') {
            continue;
        }
        $audit['has_any_library'] = true;
        $audit['libraries'][] = [
            'key'   => $cfg_key,
            'label' => $meta['label'],
            'path'  => $path,
            'dest'  => $meta['dest'],
            'apps'  => [],
        ];
    }
    if (!$audit['has_any_library']) {
        return $audit;
    }
    if (!is_readable($audit['config_path'])) {
        $audit['all_wired'] = false;
        return $audit;
    }
    $audit['config_readable'] = true;
    $text = file_get_contents($audit['config_path']);
    if ($text === false) {
        $audit['all_wired'] = false;
        return $audit;
    }
    $apps_by_title = gow_config_apps_by_title($text);
    foreach ($audit['libraries'] as &$lib) {
        $meta = $spec[$lib['key']];
        foreach ($meta['apps'] as $canonical) {
            if (!isset($apps_by_title[$canonical])) {
                continue;
            }
            $entry = $apps_by_title[$canonical];
            $wired = gow_block_has_mount_dest($entry['block'], $meta['dest']);
            $lib['apps'][] = [
                'title'     => $entry['title'],
                'canonical' => $canonical,
                'wired'     => $wired,
            ];
            if (!$wired) {
                $audit['all_wired'] = false;
            }
        }
        if (!$lib['apps']) {
            $audit['all_wired'] = false;
        }
    }
    unset($lib);
    return $audit;
}

function gow_library_mount_health_checks(array $cfg) {
    $audit = gow_library_mount_audit($cfg);
    if (!$audit['has_any_library']) {
        return [];
    }
    if (!$audit['config_readable']) {
        return [gow_health_item(
            'warn',
            'Libraries in apps',
            'Wolf has not created config.toml yet. Deploy Wolf, then Advanced → Fix mounts.'
        )];
    }
    if ($audit['all_wired']) {
        $labels = array_map(function ($l) {
            return $l['label'];
        }, $audit['libraries']);
        return [gow_health_item(
            'ok',
            'Libraries in apps',
            'Configured libraries are mapped in Wolf app runners (' . implode(', ', $labels) . ').'
        )];
    }
    $problems = [];
    foreach ($audit['libraries'] as $lib) {
        if (!$lib['apps']) {
            $problems[] = $lib['label'] . ': add ' . implode(' or ', gow_library_app_spec()[$lib['key']]['apps']) . ' in Wolf Den';
            continue;
        }
        foreach ($lib['apps'] as $app) {
            if (!$app['wired']) {
                $problems[] = $app['title'] . ' needs ' . $lib['dest'];
            }
        }
    }
    return [gow_health_item(
        'warn',
        'Libraries in apps',
        implode('; ', array_slice($problems, 0, 3))
            . (count($problems) > 3 ? '…' : '')
            . ' — Advanced → Fix mounts, then relaunch from Moonlight.'
    )];
}

// ROM-relevant emulator apps (keys in APP_PRESETS that mount /ROMs).
function gow_emulator_rom_titles() {
    return ['RetroArch', 'EmulationStation', 'Pegasus'];
}

function gow_normalize_app_title($title) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$title));
}

function gow_resolve_emulator_title($title) {
    $resolved = gow_resolve_preset_title($title);
    if ($resolved !== null && in_array($resolved, gow_emulator_rom_titles(), true)) {
        return $resolved;
    }
    return null;
}

function gow_text_has_any_rom_mount($text) {
    return (bool)preg_match('~["\']([^"\']+:(?:/ROMs|/home/retro/ROMs)(?::[a-z]+)?)["\']~', $text);
}

function gow_config_emulator_apps($text) {
    $blocks = preg_split('/(?=^\[\[profiles\.apps\]\])/m', $text);
    $by_canonical = [];
    foreach ($blocks as $block) {
        if (!preg_match('/^\s*\[\[profiles\.apps\]\]/', $block)) {
            continue;
        }
        if (!preg_match('/^title\s*=\s*["\']([^"\']+)["\']/m', $block, $tm)) {
            continue;
        }
        $title = $tm[1];
        $canonical = gow_resolve_emulator_title($title);
        if ($canonical === null) {
            continue;
        }
        $has_rom = gow_text_has_any_rom_mount($block);
        if (!isset($by_canonical[$canonical])) {
            $by_canonical[$canonical] = [
                'title'         => $title,
                'canonical'     => $canonical,
                'has_rom_mount' => $has_rom,
            ];
        } elseif ($has_rom) {
            $by_canonical[$canonical]['has_rom_mount'] = true;
        }
    }
    return array_values($by_canonical);
}

// Read-only audit: plugin ROM path vs per-emulator /ROMs mounts in config.toml.
function gow_rom_mount_audit(array $cfg) {
    $appdata = rtrim($cfg['APPDATA'] ?? '/mnt/user/appdata/gow', '/');
    $roms = rtrim(trim($cfg['ROMS_LIBRARY'] ?? ''), '/');
    $audit = [
        'roms'              => $roms,
        'config_path'       => $appdata . '/cfg/config.toml',
        'config_readable'   => false,
        'apps'              => [],
        'any_rom_mount'     => false,
    ];
    if ($roms === '') {
        return $audit;
    }
    if (!is_readable($audit['config_path'])) {
        return $audit;
    }
    $audit['config_readable'] = true;
    $text = file_get_contents($audit['config_path']);
    if ($text === false) {
        return $audit;
    }
    $audit['any_rom_mount'] = gow_text_has_any_rom_mount($text);
    $audit['apps'] = gow_config_emulator_apps($text);
    return $audit;
}

// If a ROMs library is configured, verify Wolf app runners mount it at /ROMs.
// Superseded by gow_library_mount_health_checks(); kept for rom wiring card helpers.
function gow_check_rom_mount(array $cfg) {
    foreach (gow_library_mount_health_checks($cfg) as $check) {
        if (strpos($check['label'], 'Libraries') !== false) {
            return $check;
        }
    }
    return null;
}

// Inspect a host library path for the setup form: does it exist, is it a
// directory, how many top-level entries, and is it in a fragile location.
// Pure read-only; never writes. Returns a structured summary for the UI.
function gow_validate_library_path($path) {
    $path = rtrim(trim((string)$path), '/');
    $out = [
        'path'      => $path,
        'state'     => 'empty',   // empty | missing | not_dir | ok
        'children'  => 0,
        'sample'    => [],
        'warnings'  => [],
    ];
    if ($path === '') {
        return $out;
    }
    if (!file_exists($path)) {
        $out['state'] = 'missing';
        $out['warnings'][] = 'Path does not exist yet — it will be created empty on install.';
        return $out;
    }
    if (!is_dir($path)) {
        $out['state'] = 'not_dir';
        $out['warnings'][] = 'Path is not a directory.';
        return $out;
    }
    $out['state'] = 'ok';
    $entries = @scandir($path) ?: [];
    $entries = array_values(array_filter($entries, function ($e) {
        return $e !== '.' && $e !== '..';
    }));
    $out['children'] = count($entries);
    $out['sample'] = array_slice($entries, 0, 6);
    if ($out['children'] === 0) {
        $out['warnings'][] = 'Folder is empty — emulators will show no games until you add files.';
    }
    // Fragile locations: inside appdata/cfg or the plugin flash dir.
    if (strpos($path, '/cfg') !== false && strpos($path, 'appdata') !== false) {
        $out['warnings'][] = 'Avoid placing libraries inside appdata/cfg — it holds Wolf config and pairing.';
    }
    if (strncmp($path, '/boot/', 6) === 0) {
        $out['warnings'][] = 'Avoid the USB flash (/boot) for libraries — it is small and slow.';
    }
    return $out;
}

function gow_health_ready(array $health) {
    foreach ($health['checks'] ?? [] as $check) {
        if (($check['level'] ?? '') === 'fail') {
            return false;
        }
    }
    return true;
}

function gow_detect_gpus_simple() {
    $gpus = [];
    foreach (glob('/sys/class/drm/renderD*/device/driver') ?: [] as $node) {
        $device_dir = dirname($node);
        $render_dev = '/dev/dri/' . basename(dirname($device_dir));
        $driver = basename(readlink($node));
        switch ($driver) {
            case 'i915':
            case 'xe':
                $vendor = 'Intel';
                break;
            case 'amdgpu':
                $vendor = 'AMD';
                break;
            case 'nvidia':
                $vendor = 'NVIDIA';
                break;
            default:
                $vendor = 'Unknown';
                break;
        }
        $gpus[] = ['node' => $render_dev, 'vendor' => $vendor, 'driver' => $driver];
    }
    return $gpus;
}

function gow_run_setup_health_checks(array $cfg, array $gpus) {
    $checks = [];

    exec('docker info >/dev/null 2>&1', $out, $ret);
    $checks[] = gow_health_item(
        $ret === 0 ? 'ok' : 'fail',
        'Docker daemon',
        $ret === 0 ? '' : 'Enable Docker in Unraid Settings before installing.'
    );

    $checks[] = gow_health_item(
        !empty($gpus) ? 'ok' : 'fail',
        'GPU render device detected',
        empty($gpus) ? 'Load your GPU driver and refresh this page.' : ''
    );

    $vendor = $cfg['GPU_VENDOR'] ?? '';
    if ($vendor === '' && count($gpus) === 1) {
        $vendor = $gpus[0]['vendor'] ?? '';
    }
    if ($vendor === 'NVIDIA') {
        $modeset = is_readable('/sys/module/nvidia_drm/parameters/modeset')
            && trim(@file_get_contents('/sys/module/nvidia_drm/parameters/modeset')) === 'Y';
        $checks[] = gow_health_item(
            $modeset ? 'ok' : 'fail',
            'NVIDIA Wayland (nvidia_drm.modeset)',
            $modeset ? '' : 'Required for Moonlight video — see FAQ.'
        );
    }

    $appdata = rtrim($cfg['APPDATA'] ?? '', '/');
    $appdata_ok = $appdata !== '' && strncmp($appdata, '/mnt/', 5) === 0;
    $checks[] = gow_health_item(
        $appdata_ok ? 'ok' : 'fail',
        'Appdata path',
        $appdata_ok ? $appdata : 'Pick a folder under /mnt/.'
    );

    return [
        'summary' => gow_health_summary($checks),
        'checks'  => $checks,
        'at'      => gmdate('c'),
    ];
}

function gow_load_cfg_for_health() {
    $cfg_file = '/boot/config/plugins/gow/gow.cfg';
    $defaults_file = '/usr/local/emhttp/plugins/gow/default.cfg';
    $cfg = [];

    if (is_readable($defaults_file)) {
        foreach (file($defaults_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') === false || trim($line)[0] === '#') {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $cfg[trim($k)] = trim($v);
        }
    }

    if (is_readable($cfg_file)) {
        foreach (file($cfg_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') === false || trim($line)[0] === '#') {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && $v[0] === "'" && $v[-1] === "'") {
                $v = str_replace("'\\''", "'", substr($v, 1, -1));
            }
            $cfg[$k] = $v;
        }
    }

    return $cfg;
}
