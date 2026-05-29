#!/bin/bash
# apply-mount-presets.sh — push plugin library paths into Wolf app runners.
#
# Uses the Wolf REST API when the Unix socket is available (see wolf-api.sh);
# otherwise patches config.toml on disk.

set -euo pipefail

source "$(dirname "$0")/vars.sh"
source "$(dirname "$0")/library-links.sh"
# shellcheck source=wolf-api.sh
source "$(dirname "$0")/wolf-api.sh"

err()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

[[ -f "$GOW_CFG" ]] || err "Config not found at ${GOW_CFG}"
source "$GOW_CFG"

APPDATA="${APPDATA:-${DEFAULT_APPDATA}}"
CFG_FILE="${APPDATA}/cfg/config.toml"
PRESET_SCRIPT="$(dirname "$0")/apply-mount-presets.py"
WOLF_SOCKET="${APPDATA}/run/wolf.sock"

[[ -f "$PRESET_SCRIPT" ]] || err "Missing ${PRESET_SCRIPT}"

info "Syncing library symlinks under ${APPDATA}"
gow_resolve_library_mounts "$APPDATA"

if [[ -z "$ROMS_LIBRARY$BIOS_LIBRARY$MEDIA_LIBRARY$STEAM_LIBRARY$GAMES_LIBRARY$LUTRIS_LIBRARY$COMPAT_TOOLS_PATH" ]]; then
    info "No shared library paths configured; skipping mount presets"
    exit 0
fi

LIB_ARGS=(
    "$ROMS_LIBRARY"
    "$BIOS_LIBRARY"
    "$MEDIA_LIBRARY"
    "$STEAM_LIBRARY"
    "$GAMES_LIBRARY"
    "$LUTRIS_LIBRARY"
    "$COMPAT_TOOLS_PATH"
)

if gow_wolf_api_ready "$APPDATA"; then
    info "Applying library mounts via Wolf API (${WOLF_SOCKET})"
    python3 "$PRESET_SCRIPT" --socket "$WOLF_SOCKET" "${LIB_ARGS[@]}"
    exit 0
fi

if [[ ! -f "$CFG_FILE" ]]; then
    info "Wolf API socket and config.toml not ready; mount presets will apply after Wolf starts"
    exit 0
fi

info "Wolf API unavailable — patching app mounts in ${CFG_FILE}"
python3 "$PRESET_SCRIPT" "$CFG_FILE" "${LIB_ARGS[@]}"

exit 0
