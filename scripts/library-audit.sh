#!/bin/bash
# library-audit.sh — diagnose Layer 1 (compose) vs Layer 2 (config.toml app mounts)
set -euo pipefail

source "$(dirname "$0")/vars.sh"

err()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

[[ $EUID -eq 0 ]] || err "Run as root on Unraid"
[[ -f "$GOW_CFG" ]] || err "Config not found at ${GOW_CFG}"
source "$GOW_CFG"

APPDATA="${APPDATA:-${DEFAULT_APPDATA}}"
CFG_FILE="${APPDATA}/cfg/config.toml"

info "Games on Whales library audit"
echo ""
echo "Layer 1 — Wolf service compose (visible inside Wolf only at /etc/wolf/*):"
if [[ -f "${APPDATA}/docker-compose.yml" ]]; then
    grep -E '/etc/wolf/(roms|steam|games|lutris|bioses|media)' "${APPDATA}/docker-compose.yml" 2>/dev/null || echo "  (no library binds in compose)"
else
    echo "  compose file missing — deploy Wolf first"
fi

echo ""
echo "Layer 2 — app session mounts (config.toml → Moonlight apps):"
if [[ ! -f "$CFG_FILE" ]]; then
    echo "  config.toml missing at ${CFG_FILE}"
    exit 1
fi

for app in EmulationStation Steam Lutris RetroArch Pegasus Prismlauncher Kodi; do
    echo ""
    echo "=== ${app} ==="
    grep -A25 "title = \"${app}\"" "$CFG_FILE" | grep -E '^\s*(mounts|title)|/ROMs|/var/lutris|\.local/share/Steam|/games|/media|/bioses' || echo "  (app not in config or no mounts)"
done

echo ""
info "Host library paths (gow.cfg):"
for key in ROMS_LIBRARY BIOS_LIBRARY MEDIA_LIBRARY STEAM_LIBRARY GAMES_LIBRARY LUTRIS_LIBRARY; do
    val="${!key:-}"
    [[ -n "$val" ]] || continue
    echo "  ${key}=${val}"
    if [[ -d "$val" ]]; then
        owner=$(stat -c '%u:%g' "$val" 2>/dev/null || echo "?")
        echo "    owner=${owner} (session apps run as 1000:1000)"
        [[ "$owner" == "1000:1000" ]] || echo "    WARN: chown may be needed — redeploy or Fix mounts after plugin update"
    else
        echo "    WARN: path missing"
    fi
done

echo ""
info "Running session containers (if any):"
for name in $(docker ps --format '{{.Names}}' 2>/dev/null | grep -E '^Wolf' || true); do
    case "$name" in wolf|wolf-den) continue ;; esac
    echo "--- ${name} ---"
    docker inspect --format '{{range .Mounts}}{{.Source}} -> {{.Destination}} ({{.Mode}}){{println}}{{end}}' "$name" 2>/dev/null | head -20
done

echo ""
info "Next: bash $(dirname "$0")/fix-all.sh  then relaunch apps from Moonlight"
