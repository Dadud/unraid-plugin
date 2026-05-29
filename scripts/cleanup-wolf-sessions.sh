#!/bin/bash
# cleanup-wolf-sessions.sh — remove exited Wolf streaming/app session containers.
#
# Wolf spawns per-session containers (WolfES-DE_*, WolfSteam_*, WolfPulseAudio, …).
# After a crash or disconnect they can linger in "exited" state and still hold memory
# references until removed. This script only removes stopped containers.
#
# Usage: cleanup-wolf-sessions.sh [--all-sessions]
#   --all-sessions  also remove running Wolf* session containers (fix-all after mount update)

set -euo pipefail

source "$(dirname "$0")/vars.sh"

info() { echo "==> $*"; }
warn() { echo "WARN:  $*" >&2; }

remove_all=""
if [[ "${1:-}" == "--all-sessions" ]]; then
    remove_all="--all-sessions"
fi

removed=0

while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    name=$(docker inspect --format '{{.Name}}' "$id" 2>/dev/null | sed 's|^/||')
    [[ -n "$name" ]] || continue

    case "$name" in
        wolf|wolf-den)
            continue
            ;;
        Wolf*)
            info "Removing exited session container ${name}"
            docker rm -f "$id" >/dev/null 2>&1 || warn "Could not remove ${name}"
            removed=$((removed + 1))
            ;;
    esac
done < <(docker ps -aq --filter "status=exited" 2>/dev/null || true)

# fix-all passes --all-sessions so relaunch picks up new config.toml mounts.
if [[ "$remove_all" == "--all-sessions" ]]; then
    while IFS= read -r id; do
        [[ -n "$id" ]] || continue
        name=$(docker inspect --format '{{.Name}}' "$id" 2>/dev/null | sed 's|^/||')
        [[ -n "$name" ]] || continue
        case "$name" in
            wolf|wolf-den) continue ;;
            Wolf*)
                info "Removing session container ${name} (mount refresh)"
                docker rm -f "$id" >/dev/null 2>&1 || warn "Could not remove ${name}"
                removed=$((removed + 1))
                ;;
        esac
    done < <(docker ps -aq --filter "status=running" 2>/dev/null || true)
fi

# PulseAudio helper is recreated per stream; remove if stopped.
if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qx 'WolfPulseAudio'; then
    state=$(docker inspect --format '{{.State.Status}}' WolfPulseAudio 2>/dev/null || true)
    if [[ "$state" == "exited" ]]; then
        info "Removing exited WolfPulseAudio"
        docker rm -f WolfPulseAudio >/dev/null 2>&1 || true
        removed=$((removed + 1))
    fi
fi

if (( removed > 0 )); then
    info "Removed ${removed} stale Wolf session container(s)"
else
    info "No stale Wolf session containers to remove"
fi

exit 0
