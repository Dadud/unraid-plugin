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

    $rom_check = gow_check_rom_mount($cfg);
    if ($rom_check !== null) {
        $checks[] = $rom_check;
    }

    return [
        'summary' => gow_health_summary($checks),
        'checks'  => $checks,
        'at'      => gmdate('c'),
    ];
}

// If a ROMs library is configured, verify that Wolf's config.toml actually
// mounts a host ROM path into an emulator app runner. Mounting only at the
// Wolf service layer does NOT expose ROMs to session containers — the host
// path must appear in a [[profiles.apps]] mounts array (see apply-mount-presets).
// Returns a health item, or null when no ROMs library is configured.
function gow_check_rom_mount(array $cfg) {
    $roms = trim($cfg['ROMS_LIBRARY'] ?? '');
    if ($roms === '') {
        return null;
    }
    $appdata = rtrim($cfg['APPDATA'] ?? '/mnt/user/appdata/gow', '/');
    $cfg_file = $appdata . '/cfg/config.toml';
    if (!is_readable($cfg_file)) {
        return gow_health_item('warn', 'ROM library wired into apps',
            'config.toml not readable yet — deploy Wolf, then run Fix mounts.');
    }
    $text = file_get_contents($cfg_file);
    if ($text === false) {
        return gow_health_item('warn', 'ROM library wired into apps', 'Could not read config.toml.');
    }
    // Any app runner mount whose container destination is /ROMs (or ~/ROMs).
    if (preg_match('~"[^"]+:(?:/ROMs|/home/retro/ROMs)(?::[a-z]+)?"~', $text)) {
        return gow_health_item('ok', 'ROM library wired into apps', 'EmulationStation/RetroArch mount /ROMs.');
    }
    return gow_health_item('warn', 'ROM library wired into apps',
        'ROMs path set but no app mounts /ROMs yet — use Fix mounts, then relaunch the app.');
}

// Canonical emulator apps that receive ROM mounts (keep in sync with apply-mount-presets.py).
function gow_rom_emulator_presets() {
    return ['EmulationStation', 'RetroArch', 'Pegasus'];
}

function gow_normalize_preset_title($title) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$title));
}

// Map a config.toml app title to a canonical preset name (mirrors apply-mount-presets.py).
function gow_resolve_preset_title($title) {
    static $aliases = [
        'esde' => 'EmulationStation',
        'emulationstationdesktopedition' => 'EmulationStation',
        'emustation' => 'EmulationStation',
        'retroarchra' => 'RetroArch',
    ];
    static $canonical = null;
    if ($canonical === null) {
        $canonical = [];
        foreach (gow_rom_emulator_presets() as $name) {
            $canonical[gow_normalize_preset_title($name)] = $name;
        }
        $canonical[gow_normalize_preset_title('Steam')] = 'Steam';
        $canonical[gow_normalize_preset_title('Lutris')] = 'Lutris';
        $canonical[gow_normalize_preset_title('Pegasus')] = 'Pegasus';
        $canonical[gow_normalize_preset_title('RetroArch')] = 'RetroArch';
        $canonical[gow_normalize_preset_title('EmulationStation')] = 'EmulationStation';
    }
    $title = (string)$title;
    $norm = gow_normalize_preset_title($title);
    if (isset($canonical[$norm])) {
        return $canonical[$norm];
    }
    if (isset($aliases[$norm])) {
        return $aliases[$norm];
    }
    return null;
}

function gow_config_app_titles($config_text) {
    $titles = [];
    if (!preg_match_all('/^title\s*=\s*["\']([^"\']+)["\']/m', $config_text, $matches)) {
        return $titles;
    }
    foreach ($matches[1] as $title) {
        $titles[] = $title;
    }
    return $titles;
}

function gow_rom_emulators_in_config($config_text) {
    $found = [];
    foreach (gow_config_app_titles($config_text) as $title) {
        $canonical = gow_resolve_preset_title($title);
        if ($canonical !== null && in_array($canonical, gow_rom_emulator_presets(), true)) {
            $found[$canonical] = $title;
        }
    }
    return $found;
}

// Inspect a host library path for the setup form: does it exist, is it a
// directory, how many top-level entries, and is it in a fragile location.
// Pure read-only; never writes. Returns a structured summary for the UI.
function gow_validate_library_path($path) {
    $path = rtrim(trim((string)$path), '/');
    $out = [
        'path'      => $path,
        'state'     => 'empty',   // empty | missing | not_dir | ok
        'layout'    => 'empty',   // empty | esde_systems | flat_files | nested_roms | unknown
        'children'  => 0,
        'systems'   => 0,
        'sample'    => [],
        'warnings'  => [],
        'hints'     => [],
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

    // Common mistake: user picked the parent share instead of the ROMs subfolder.
    foreach (['roms', 'ROMs', 'Roms'] as $subdir) {
        $nested = $path . '/' . $subdir;
        if (is_dir($nested) && $out['children'] > 0) {
            $nested_count = count(array_filter(@scandir($nested) ?: [], function ($e) {
                return $e !== '.' && $e !== '..';
            }));
            if ($nested_count > 0) {
                $out['warnings'][] = "This folder contains a {$subdir}/ subfolder with content — you may want to select {$nested} instead.";
                $out['layout'] = 'nested_roms';
                break;
            }
        }
    }

    $rom_ext = '/\.(zip|7z|rar|chd|cue|bin|iso|pbp|cia|nsp|xci|rvz|wbfs|wad|gcm|nds|3ds)$/i';
    $system_dirs = 0;
    $files_at_root = 0;
    foreach ($entries as $entry) {
        $full = $path . '/' . $entry;
        if (is_dir($full)) {
            $system_dirs++;
            continue;
        }
        if (is_file($full) && preg_match($rom_ext, $entry)) {
            $files_at_root++;
        }
    }
    $out['systems'] = $system_dirs;

    if ($out['layout'] !== 'nested_roms') {
        if ($system_dirs >= 1 && $files_at_root === 0) {
            $out['layout'] = 'esde_systems';
            $out['hints'][] = 'Looks like an ES-DE-style layout: one subfolder per system (nes/, snes/, …).';
        } elseif ($files_at_root > 0 && $system_dirs === 0) {
            $out['layout'] = 'flat_files';
            $out['warnings'][] = 'ROM files sit directly in this folder — ES-DE expects subfolders per system (e.g. nes/, gba/).';
        } elseif ($system_dirs > 0 && $files_at_root > 0) {
            $out['layout'] = 'mixed';
            $out['hints'][] = 'Mix of system folders and loose ROM files — emulators may only index the subfolders.';
        } else {
            $out['layout'] = 'unknown';
        }
    }

    if ($out['children'] === 0) {
        $out['warnings'][] = 'Folder is empty — emulators will show no games until you add files.';
    } elseif ($out['layout'] === 'esde_systems' && $system_dirs > 0) {
        $out['hints'][] = "{$system_dirs} system folder(s) detected — ready for EmulationStation / RetroArch / Pegasus after install.";
    }

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
