#!/bin/bash
# apply-mount-presets.sh — push plugin library paths into Wolf config.toml app mounts

set -euo pipefail

source "$(dirname "$0")/vars.sh"
source "$(dirname "$0")/library-links.sh"

err()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

[[ -f "$GOW_CFG" ]] || err "Config not found at ${GOW_CFG}"
source "$GOW_CFG"

APPDATA="${APPDATA:-${DEFAULT_APPDATA}}"
CFG_FILE="${APPDATA}/cfg/config.toml"
PRESET_SCRIPT="$(dirname "$0")/apply-mount-presets.py"

[[ -f "$PRESET_SCRIPT" ]] || err "Missing ${PRESET_SCRIPT}"

if [[ ! -f "$CFG_FILE" ]]; then
    info "Wolf config not found yet at ${CFG_FILE}; mount presets will apply on first Wolf start"
    exit 0
fi

info "Syncing library symlinks under ${APPDATA}"
gow_resolve_library_mounts "$APPDATA"

if [[ -z "$ROMS_LIBRARY$BIOS_LIBRARY$MEDIA_LIBRARY$STEAM_LIBRARY$GAMES_LIBRARY$LUTRIS_LIBRARY$COMPAT_TOOLS_PATH" ]]; then
    info "No shared library paths configured; skipping mount presets"
    exit 0
fi

info "Applying shared library mounts to Wolf app runners in ${CFG_FILE}"
python3 "$PRESET_SCRIPT" "$CFG_FILE" \
    "$ROMS_LIBRARY" \
    "$BIOS_LIBRARY" \
    "$MEDIA_LIBRARY" \
    "$STEAM_LIBRARY" \
    "$GAMES_LIBRARY" \
    "$LUTRIS_LIBRARY" \
    "$COMPAT_TOOLS_PATH"

exit 0
