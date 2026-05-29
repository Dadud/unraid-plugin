#!/usr/bin/env python3
# apply-mount-presets.py — merge Unraid plugin library paths into Wolf app runners.
#
# Wolf app containers read mounts from config.toml using HOST paths, e.g.
#   /mnt/user/roms:/ROMs:rw
# Mounting libraries only into the Wolf container does not expose them to apps.

from __future__ import annotations

import re
import sys
from pathlib import Path

# title -> list of (config key, container destination)
APP_PRESETS: dict[str, list[tuple[str, str]]] = {
    "RetroArch": [("ROMS", "/ROMs")],
    "Pegasus": [("ROMS", "/ROMs"), ("BIOS", "/bioses")],
    "EmulationStation": [("ROMS", "/ROMs"), ("BIOS", "/bioses"), ("MEDIA", "/media")],
    "Steam": [("STEAM", "/home/retro/.local/share/Steam")],
    "Lutris": [("LUTRIS", "/var/lutris")],
    "Prismlauncher": [("GAMES", "/games")],
    "Kodi": [("MEDIA", "/media")],
    "Desktop (xfce)": [("GAMES", "/games")],
    "Heroic Games Launcher": [("GAMES", "/games")],
}

# Alternate titles Wolf/gow may ship for the same app.
TITLE_ALIASES: dict[str, str] = {
    "esde": "EmulationStation",
    "emulationstationdesktopedition": "EmulationStation",
    "emustation": "EmulationStation",
    "retroarchra": "RetroArch",
    "xfce": "Desktop (xfce)",
    "desktop": "Desktop (xfce)",
    "xfcedesktop": "Desktop (xfce)",
    "heroic": "Heroic Games Launcher",
    "heroicgameslauncher": "Heroic Games Launcher",
    "prism": "Prismlauncher",
    "prismlauncher": "Prismlauncher",
}

# Apps that consume the ROM library (for post-apply hints).
ROM_EMULATOR_PRESETS = ("EmulationStation", "RetroArch", "Pegasus")

# ES-DE / RetroArch configs often use ~/bioses while GOW mounts at /bioses.
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

_NORMALIZED_PRESETS = {
    re.sub(r"[^a-z0-9]", "", name.lower()): name for name in APP_PRESETS
}


def _normalize_title(title: str) -> str:
    return re.sub(r"[^a-z0-9]", "", title.lower())


def resolve_preset_title(title: str) -> str | None:
    if title in APP_PRESETS:
        return title
    norm = _normalize_title(title)
    if norm in _NORMALIZED_PRESETS:
        return _NORMALIZED_PRESETS[norm]
    if norm in TITLE_ALIASES:
        return TITLE_ALIASES[norm]
    return None


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


def ensure_mounts_array(block: str) -> tuple[str, tuple[int, int] | None]:
    span = find_bracket_array(block, "mounts")
    if span:
        return block, span
    if not re.search(r"^\[profiles\.apps\.runner\]", block, flags=re.MULTILINE):
        return block, None
    insert_at = len(block)
    for key in ("env", "devices", "ports", "base_create_json"):
        match = re.search(rf"^\s*{re.escape(key)}\s*=", block, flags=re.MULTILINE)
        if match:
            insert_at = min(insert_at, match.start())
    prefix = "" if insert_at == 0 or block[insert_at - 1] == "\n" else "\n"
    block = block[:insert_at] + f"{prefix}mounts = []\n" + block[insert_at:]
    return block, find_bracket_array(block, "mounts")


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

    required = GOW_REQUIRED_BASE
    new_entries: list[str] = []
    changed = False
    for entry in entries:
        if entry.startswith("GOW_REQUIRED_DEVICES="):
            base = entry.split("=", 1)[1].strip()
            for path in extra_paths:
                if path not in base:
                    base = f"{base} {path}"
                    changed = True
            new_entries.append(f"GOW_REQUIRED_DEVICES={base}")
        else:
            new_entries.append(entry)

    if not any(e.startswith("GOW_REQUIRED_DEVICES=") for e in entries):
        new_entries.append(f"GOW_REQUIRED_DEVICES={required} {' '.join(extra_paths)}")
        changed = True

    if not changed:
        return block, False

    inner = ", ".join(f'"{entry}"' for entry in new_entries)
    new_env = f"[{inner}]"
    return block[:start] + new_env + block[end:], True


def load_paths(argv: list[str]) -> dict[str, str]:
    keys = ["ROMS", "BIOS", "MEDIA", "STEAM", "GAMES", "LUTRIS", "COMPAT"]
    out: dict[str, str] = {}
    for key, value in zip(keys, argv):
        value = value.strip()
        if value:
            out[key] = value.rstrip("/")
    return out


def desired_for_title(title: str, paths: dict[str, str]) -> list[tuple[str, str, str]]:
    canonical = resolve_preset_title(title)
    if canonical is None:
        return []
    desired: list[tuple[str, str, str]] = []
    preset = APP_PRESETS.get(canonical, [])
    aliases = HOME_MOUNT_ALIASES.get(canonical, [])
    for cfg_key, dest in preset + aliases:
        host = paths.get(cfg_key, "")
        if host:
            desired.append((host, dest, "rw"))
    return desired


def patch_config(text: str, paths: dict[str, str]) -> tuple[str, int]:
    updated = 0
    blocks = re.split(r"(?=^\[\[profiles\.apps\]\])", text, flags=re.MULTILINE)
    out: list[str] = []

    for block in blocks:
        if not block.strip():
            continue
        if not block.lstrip().startswith("[[profiles.apps]]"):
            out.append(block)
            continue

        title_match = re.search(r'^title\s*=\s*["\']([^"\']+)["\']', block, flags=re.MULTILINE)
        if not title_match:
            out.append(block)
            continue

        title = title_match.group(1)
        desired = desired_for_title(title, paths)
        if not desired:
            out.append(block)
            continue

        block, mounts_span = ensure_mounts_array(block)
        if not mounts_span:
            out.append(block)
            continue

        start, end = mounts_span
        mounts_text = block[start:end]
        existing = sanitize_mounts(parse_mounts_array(mounts_text))
        merged = merge_mounts(existing, desired)
        new_mounts = format_mounts_array(merged)
        new_block = block[:start] + new_mounts + block[end:]

        extra_paths = required_device_paths(merged)
        new_block, env_changed = patch_gow_required_devices(new_block, extra_paths)

        if new_mounts.replace(" ", "") != mounts_text.replace(" ", "") or env_changed:
            updated += 1
        out.append(new_block)

    return "".join(out), updated


def rom_emulator_hint(patched: str, paths: dict[str, str]) -> str | None:
    if not paths.get("ROMS"):
        return None
    present: set[str] = set()
    for match in re.finditer(r'^title\s*=\s*["\']([^"\']+)["\']', patched, flags=re.MULTILINE):
        canonical = resolve_preset_title(match.group(1))
        if canonical in ROM_EMULATOR_PRESETS:
            present.add(canonical)
    missing = [name for name in ROM_EMULATOR_PRESETS if name not in present]
    if not missing:
        return None
    if ":/ROMs" not in patched:
        return (
            "No emulator app in Wolf yet — add EmulationStation, RetroArch, or Pegasus "
            "in Wolf Den (Apps), then run Fix mounts again."
        )
    return (
        "ROM path is set but these apps are not in your Wolf profile yet: "
        + ", ".join(missing)
        + ". Add them in Wolf Den (Apps), then run Fix mounts."
    )


def main() -> int:
    argv = sys.argv[1:]
    dry_run = False
    if "--dry-run" in argv:
        dry_run = True
        argv = [a for a in argv if a != "--dry-run"]

    if not argv:
        print(
            "Usage: apply-mount-presets.py [--dry-run] <config.toml> "
            "[ROMS BIOS MEDIA STEAM GAMES LUTRIS COMPAT]",
            file=sys.stderr,
        )
        return 2

    cfg_path = Path(argv[0])
    if not cfg_path.is_file():
        print(f"Config not found: {cfg_path}", file=sys.stderr)
        return 1

    paths = load_paths(argv[1:])
    if not paths:
        print("No library paths configured; skipping mount presets")
        return 0

    original = cfg_path.read_text(encoding="utf-8")
    patched, count = patch_config(original, paths)
    if dry_run:
        if count:
            print(f"[dry-run] Would update {count} app runner(s) in {cfg_path}")
        else:
            print(f"[dry-run] No app runners need mount preset updates in {cfg_path}")
        hint = rom_emulator_hint(patched, paths)
        if hint:
            print(f"[dry-run] Hint: {hint}", file=sys.stderr)
        return 0

    if count:
        cfg_path.write_text(patched, encoding="utf-8")
        print(f"Applied mount presets to {count} app runner(s) in {cfg_path}")
    else:
        print(f"No app runners needed mount preset updates in {cfg_path}")

    hint = rom_emulator_hint(patched, paths)
    if hint:
        print(f"Hint: {hint}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
