#!/usr/bin/env python3
# apply-mount-presets.py — merge Unraid plugin library paths into Wolf app runners.
#
# Prefers the Wolf REST API (GET/POST /api/v1/apps) when a Unix socket is available;
# falls back to editing config.toml directly. See:
#   https://games-on-whales.github.io/wolf/stable/dev/api.html
#
# App runners need HOST paths in mounts, e.g. /mnt/user/games/roms:/ROMs:rw
# Binding libraries only into the Wolf container (/etc/wolf/*) does not reach sessions.

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

# Wolf Den may use different title strings than our preset keys.
TITLE_ALIASES: dict[str, str] = {
    "Prism Launcher": "Prismlauncher",
}

# title -> list of (config key, container destination)
APP_PRESETS: dict[str, list[tuple[str, str]]] = {
    "RetroArch": [("ROMS", "/ROMs"), ("BIOS", "/bioses")],
    "Pegasus": [("ROMS", "/ROMs"), ("BIOS", "/bioses")],
    "EmulationStation": [("ROMS", "/ROMs"), ("BIOS", "/bioses"), ("MEDIA", "/media")],
    "Steam": [("STEAM", "/home/retro/.local/share/Steam")],
    "Lutris": [("LUTRIS", "/var/lutris")],
    "Prismlauncher": [("GAMES", "/games")],
    "Kodi": [("MEDIA", "/media")],
    "Desktop (xfce)": [("GAMES", "/games")],
    "Heroic Games Launcher": [("GAMES", "/games")],
}

HOME_MOUNT_ALIASES: dict[str, list[tuple[str, str]]] = {
    "RetroArch": [("BIOS", "/home/retro/bioses")],
    "Pegasus": [("BIOS", "/home/retro/bioses")],
    "EmulationStation": [
        ("BIOS", "/home/retro/bioses"),
        ("ROMS", "/home/retro/ROMs"),
    ],
}

DEPRECATED_DESTINATIONS = {
    "/home/retro/ROMs",
    "/home/retro/bioses",
    "/home/retro/media",
    "/etc/wolf/roms",
    "/etc/wolf/bioses",
    "/etc/wolf/media",
}

GOW_REQUIRED_BASE = "/dev/input/* /dev/dri/* /dev/nvidia*"

def parse_mount_line(line: str) -> tuple[str, str, str] | None:
    line = line.strip().strip(",").strip('"').strip("'")
    if not line:
        return None
    parts = line.split(":")
    if len(parts) < 2:
        return None
    mode = parts[2] if len(parts) >= 3 else "rw"
    return parts[0], parts[1], mode


def parse_mounts_array(text: str) -> list[tuple[str, str, str]]:
    mounts: list[tuple[str, str, str]] = []
    for raw in re.findall(r'"([^"]+)"', text):
        parsed = parse_mount_line(raw)
        if parsed:
            mounts.append(parsed)
    return mounts


def format_mounts_array(mounts: list[tuple[str, str, str]]) -> str:
    if not mounts:
        return "[]"
    inner = ",\n    ".join(f'"{src}:{dst}:{mode}"' for src, dst, mode in mounts)
    return f"[\n    {inner}\n]"


def find_bracket_array(block: str, key: str) -> tuple[int, int] | None:
    match = re.search(rf"^\s*{re.escape(key)}\s*=\s*", block, flags=re.MULTILINE)
    if not match:
        return None
    idx = match.end()
    while idx < len(block) and block[idx] in " \t\n\r":
        idx += 1
    if idx >= len(block) or block[idx] != "[":
        return None
    depth = 0
    start = idx
    for pos in range(idx, len(block)):
        char = block[pos]
        if char == "[":
            depth += 1
        elif char == "]":
            depth -= 1
            if depth == 0:
                return start, pos + 1
    return None


def merge_mounts(
    existing: list[tuple[str, str, str]],
    desired: list[tuple[str, str, str]],
) -> list[tuple[str, str, str]]:
    by_dest = {dst: (src, dst, mode) for src, dst, mode in existing}
    for src, dst, mode in desired:
        by_dest[dst] = (src, dst, mode)
    return list(by_dest.values())


def sanitize_mounts(mounts: list[tuple[str, str, str]]) -> list[tuple[str, str, str]]:
    cleaned: list[tuple[str, str, str]] = []
    for src, dst, mode in mounts:
        if dst in DEPRECATED_DESTINATIONS:
            continue
        if src.startswith("/etc/wolf/"):
            continue
        cleaned.append((src, dst, mode))
    return cleaned


def required_device_paths(mounts: list[tuple[str, str, str]]) -> list[str]:
    paths: list[str] = []
    destinations = {dst for _, dst, _ in mounts}
    if "/ROMs" in destinations or "/home/retro/ROMs" in destinations:
        paths.append("/ROMs/")
    if "/bioses" in destinations or "/home/retro/bioses" in destinations:
        paths.append("/bioses/")
    if "/media" in destinations:
        paths.append("/media/")
    if "/var/lutris" in destinations:
        paths.append("/var/lutris/")
    return paths


def preset_key(title: str) -> str | None:
    aliased = TITLE_ALIASES.get(title, title)
    if aliased in APP_PRESETS or aliased in HOME_MOUNT_ALIASES:
        return aliased
    lower = aliased.lower()
    for key in APP_PRESETS:
        if key.lower() == lower:
            return key
    for key in HOME_MOUNT_ALIASES:
        if key.lower() == lower:
            return key
    return None


def load_paths(argv: list[str]) -> dict[str, str]:
    keys = ["ROMS", "BIOS", "MEDIA", "STEAM", "GAMES", "LUTRIS", "COMPAT"]
    out: dict[str, str] = {}
    for key, value in zip(keys, argv):
        value = value.strip()
        if value:
            out[key] = value.rstrip("/")
    return out


def desired_for_title(title: str, paths: dict[str, str]) -> list[tuple[str, str, str]]:
    key = preset_key(title)
    if not key:
        return []
    desired: list[tuple[str, str, str]] = []
    preset = APP_PRESETS.get(key, [])
    aliases = HOME_MOUNT_ALIASES.get(key, [])
    for cfg_key, dest in preset + aliases:
        host = paths.get(cfg_key, "")
        if host:
            desired.append((host, dest, "rw"))
    return desired


def mount_strings(mounts: list[tuple[str, str, str]]) -> list[str]:
    return [f"{src}:{dst}:{mode}" for src, dst, mode in mounts]


def patch_gow_required_env(env: list[str], extra_paths: list[str]) -> tuple[list[str], bool]:
    if not extra_paths:
        return env, False
    changed = False
    found = False
    new_env: list[str] = []
    for entry in env:
        if entry.startswith("GOW_REQUIRED_DEVICES="):
            found = True
            base = entry.split("=", 1)[1].strip()
            for path in extra_paths:
                if path not in base:
                    base = f"{base} {path}"
                    changed = True
            new_env.append(f"GOW_REQUIRED_DEVICES={base}")
        else:
            new_env.append(entry)
    if not found:
        suffix = " " + " ".join(extra_paths)
        new_env.append(f"GOW_REQUIRED_DEVICES={GOW_REQUIRED_BASE}{suffix}")
        changed = True
    return new_env, changed


def patch_gow_required_devices(block: str, extra_paths: list[str]) -> tuple[str, bool]:
    if not extra_paths:
        return block, False

    span = find_bracket_array(block, "env")
    if not span:
        return block, False

    start, end = span
    env_text = block[start:end]
    entries = re.findall(r'"([^"]+)"', env_text)
    if not entries:
        return block, False

    new_entries, changed = patch_gow_required_env(entries, extra_paths)
    if not changed:
        return block, False

    inner = ", ".join(f'"{entry}"' for entry in new_entries)
    new_env = f"[{inner}]"
    return block[:start] + new_env + block[end:], True


def patch_toml_block(block: str, paths: dict[str, str]) -> tuple[str, bool]:
    title_match = re.search(r'^title\s*=\s*["\']([^"\']+)["\']', block, flags=re.MULTILINE)
    if not title_match:
        return block, False

    title = title_match.group(1)
    desired = desired_for_title(title, paths)
    if not desired:
        return block, False

    mounts_span = find_bracket_array(block, "mounts")
    if not mounts_span:
        return block, False

    start, end = mounts_span
    mounts_text = block[start:end]
    existing = sanitize_mounts(parse_mounts_array(mounts_text))
    merged = merge_mounts(existing, desired)
    new_mounts = format_mounts_array(merged)
    new_block = block[:start] + new_mounts + block[end:]

    extra_paths = required_device_paths(merged)
    new_block, env_changed = patch_gow_required_devices(new_block, extra_paths)

    changed = new_mounts.replace(" ", "") != mounts_text.replace(" ", "") or env_changed
    return new_block, changed


def patch_config_toml(text: str, paths: dict[str, str]) -> tuple[str, int]:
    updated = 0
    pattern = r"(?=^\[\[(?:profiles\.)?apps\]\])"
    parts = re.split(pattern, text, flags=re.MULTILINE)
    out: list[str] = []

    for part in parts:
        if not part.strip():
            continue
        if not re.match(r"^\[\[(?:profiles\.)?apps\]\]", part.lstrip()):
            out.append(part)
            continue
        new_part, changed = patch_toml_block(part, paths)
        if changed:
            updated += 1
        out.append(new_part)

    return "".join(out), updated


def curl_unix_json(socket: Path, method: str, path: str, body: dict | None = None) -> dict:
    url = f"http://localhost{path}"
    cmd = ["curl", "-sfS", "--unix-socket", str(socket), "-X", method, url]
    if body is not None:
        cmd.extend(["-H", "Content-Type: application/json", "-d", json.dumps(body)])
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or proc.stdout.strip() or f"curl failed ({method} {path})")
    return json.loads(proc.stdout)


def runner_is_docker(runner: object) -> bool:
    return isinstance(runner, dict) and runner.get("type") in ("docker", "Docker")


def patch_runner_mounts(runner: dict, desired: list[tuple[str, str, str]]) -> tuple[dict, bool]:
    existing_raw = runner.get("mounts") or []
    existing: list[tuple[str, str, str]] = []
    for raw in existing_raw:
        parsed = parse_mount_line(str(raw))
        if parsed:
            existing.append(parsed)
    merged = merge_mounts(sanitize_mounts(existing), desired)
    new_mounts = mount_strings(merged)
    changed = new_mounts != list(existing_raw)
    runner = dict(runner)
    runner["mounts"] = new_mounts
    env = list(runner.get("env") or [])
    new_env, env_changed = patch_gow_required_env(env, required_device_paths(merged))
    if env_changed:
        runner["env"] = new_env
        changed = True
    return runner, changed


def patch_config_api(socket: Path, paths: dict[str, str]) -> int:
    data = curl_unix_json(socket, "GET", "/api/v1/apps")
    if not data.get("success"):
        raise RuntimeError("GET /api/v1/apps returned success=false")
    apps: list[dict] = data.get("apps") or []
    updated = 0

    for app in apps:
        title = app.get("title", "")
        desired = desired_for_title(title, paths)
        if not desired:
            continue
        runner = app.get("runner")
        if not runner_is_docker(runner):
            continue
        new_runner, changed = patch_runner_mounts(dict(runner), desired)
        if not changed:
            continue
        app_id = app.get("id")
        if not app_id:
            continue
        app = dict(app)
        app["runner"] = new_runner
        curl_unix_json(socket, "POST", "/api/v1/apps/delete", {"id": app_id})
        curl_unix_json(socket, "POST", "/api/v1/apps/add", app)
        updated += 1
        print(f"  Updated mounts for {title!r} via Wolf API")

    return updated


def main() -> int:
    parser = argparse.ArgumentParser(description="Apply Unraid library mount presets to Wolf apps")
    parser.add_argument(
        "config",
        nargs="?",
        help="Path to config.toml (offline mode)",
    )
    parser.add_argument(
        "--socket",
        help="Wolf API Unix socket (e.g. ${APPDATA}/run/wolf.sock)",
    )
    parser.add_argument(
        "libraries",
        nargs="*",
        help="ROMS BIOS MEDIA STEAM GAMES LUTRIS COMPAT paths",
    )
    args = parser.parse_args()

    # When --socket is set, library paths are in libraries; else config is first arg.
    if args.socket:
        lib_argv = args.libraries
    elif args.config and args.config.startswith("-"):
        parser.error("unexpected option before library paths")
        lib_argv = []
    else:
        lib_argv = args.libraries

    paths = load_paths(lib_argv)
    if not paths:
        print("No library paths configured; skipping mount presets")
        return 0

    if args.socket:
        sock = Path(args.socket)
        if not sock.is_socket():
            print(f"Wolf API socket not ready: {sock}", file=sys.stderr)
            return 1
        try:
            count = patch_config_api(sock, paths)
        except (RuntimeError, json.JSONDecodeError, OSError) as exc:
            print(f"Wolf API mount preset failed: {exc}", file=sys.stderr)
            return 1
        if count:
            print(f"Applied mount presets to {count} app(s) via Wolf API")
        else:
            print("No Moonlight apps needed mount preset updates (API)")
        return 0

    if not args.config:
        parser.print_usage(file=sys.stderr)
        return 2

    cfg_path = Path(args.config)
    if not cfg_path.is_file():
        print(f"Config not found: {cfg_path}", file=sys.stderr)
        return 1

    original = cfg_path.read_text(encoding="utf-8")
    patched, count = patch_config_toml(original, paths)
    if count:
        cfg_path.write_text(patched, encoding="utf-8")
        print(f"Applied mount presets to {count} app runner(s) in {cfg_path}")
    else:
        print(f"No app runners needed mount preset updates in {cfg_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
