# FAQ

## NVIDIA Wayland support (nvidia_drm.modeset)

Wolf composes its game stream through a Wayland compositor, and Wayland on
NVIDIA hardware requires the `nvidia_drm` kernel module to load with
`modeset=1`. Without it, Wolf either fails to start the compositor or the
remote client shows a black frame.

### Check

```bash
cat /sys/module/nvidia_drm/parameters/modeset
```

This should print `Y`. If it prints `N`, or the file does not exist, modeset
is off.

### Fix

1. Open **Tools > System Drivers** in the Unraid webGui.
2. Find `nvidia_drm` in the driver list.
3. Click the edit/config action for that row.
4. In **Modprobe.d Config File**, enter exactly:

   ```text
   options nvidia_drm modeset=1
   ```

5. Apply the change and reboot so the module reloads with the new parameter.

Unraid persists that editor content under
`/boot/config/modprobe.d/nvidia_drm.conf` and copies it into `/etc/modprobe.d`
at boot. If you prefer editing `syslinux.cfg`, the equivalent kernel command
line option is `nvidia-drm.modeset=1`; do not put that kernel-command-line form
inside the Modprobe.d Config File editor.

### References

- Unraid forum thread: https://forums.unraid.net/topic/98978-plugin-nvidia-driver/page/164/#findComment-1425257
- Short summary: "for Wayland support, you need to set `nvidia_drm` modeset
  in Tools > System Drivers for your driver, then restart."

## Moonlight discovery and mDNS/Avahi warnings

Wolf advertises the Moonlight service with mDNS on UDP port 5353. Unraid also
commonly runs `avahi-daemon` on the same port. When both are active, Avahi may
log a warning like:

```text
Detected another IPv4 mDNS stack running on this host.
```

This does not normally stop the GoW plugin or Wolf containers from starting,
but it can make Moonlight automatic discovery unreliable.

### Check

```bash
ss -ulpn | grep 5353
```

If the output shows both `avahi-daemon` and `wolf`, discovery may be flaky.

### Workaround

Use the direct pairing URL shown on the GoW settings page, or manually add the
Unraid server IP in Moonlight. Do not disable Unraid's Avahi service unless you
understand the impact on other Unraid network discovery features.

## Moonlight pairing after Wolf updates or container recreation

Moonlight pairing is **not** stored inside the Wolf Docker container. It lives
on the Unraid flash/array under your appdata path:

```text
/mnt/user/appdata/gow/cfg/config.toml   # host uuid + paired client certificates
/mnt/user/appdata/gow/cfg/key.pem       # Wolf server private key
/mnt/user/appdata/gow/cfg/cert.pem      # Wolf server certificate
```

As long as that `cfg/` folder survives, you should **not** need to enter a new
PIN when Wolf is updated or recreated (`docker compose up --force-recreate`,
plugin **Update**, or **Reconfigure**).

The plugin now:

1. Backs up those files to `appdata/.pairing-backup/` before stopping or
   recreating the stack.
2. Restores them automatically if Wolf starts without a saved identity.
3. Pins explicit certificate paths in `docker-compose.yml` so Wolf always reads
   the same files from appdata.

### When you *will* need to pair again

- You change the **Appdata** path in setup (new folder = new Wolf identity).
- You delete `appdata/gow/cfg/` or run `rm -rf` on the whole appdata tree.
- You run `docker compose down -v` **and** also remove the appdata bind mount
  contents (the `-v` flag alone only removes the internal `wolf-socket` volume,
  not appdata).
- You intentionally reset Wolf by deleting `key.pem` / `cert.pem` / `config.toml`.

### Check pairing state

```bash
ls -la /mnt/user/appdata/gow/cfg/
grep -c '^\[\[paired_clients\]\]' /mnt/user/appdata/gow/cfg/config.toml
```

The GoW settings dashboard also shows how many Moonlight clients are paired.

### Manual restore

If pairing was lost but `.pairing-backup/` still exists:

```bash
APPDATA=/mnt/user/appdata/gow
cp -a "$APPDATA/.pairing-backup/"* "$APPDATA/cfg/"
docker compose -f "$APPDATA/docker-compose.yml" restart wolf wolf-den
```

## ES-DE, Steam, Lutris, and library mounts

Games on Whales uses **two layers** for shared folders:

1. **Wolf service (compose)** — your Unraid paths appear at `/etc/wolf/roms`, `/etc/wolf/steam`, etc. **inside the Wolf container only**.
2. **App sessions (config.toml)** — each Moonlight app (ES-DE, Steam, Lutris, …) needs the host path bound to an app-specific path (e.g. `/ROMs`, `~/.local/share/Steam`, `/var/lutris`).

The plugin writes layer 2 via `apply-mount-presets.py` when you deploy or click **Advanced → Fix mounts**. The dashboard **Libraries in apps** card and health check **Libraries in apps** show whether each app is mapped.

Full contract: [ECOSYSTEM_DEV_SKELETON.md §4.2](ECOSYSTEM_DEV_SKELETON.md).

### Library mount contract (session paths)

| Library (Setup) | Apps | Container path |
|-----------------|------|------------------|
| ROMs | EmulationStation, RetroArch, Pegasus | `/ROMs` |
| BIOS | EmulationStation, Pegasus | `/bioses` |
| Media | EmulationStation, Kodi | `/media` |
| Steam | Steam | `/home/retro/.local/share/Steam` |
| PC games | Prismlauncher, Heroic, Desktop | `/games` |
| Lutris | Lutris | `/var/lutris` |

### ES-DE Custom Scripts and ROM layout

EmulationStation (ES-DE) in Games on Whales expects shared libraries at fixed paths
inside the app container:

| Library | Container path | Also used as |
|---------|----------------|--------------|
| ROMs | `/ROMs` | ES-DE `ROMDirectory` |
| BIOS files | `/bioses` | Many emulators via `~/bioses` |
| Disc / media images | `/media` | ISO mounting in launchers |

The Unraid plugin writes these into Wolf's `config.toml` app runner mounts using
your Unraid share paths (for example `/mnt/user/roms:/ROMs:rw`). Mounting folders
only into the Wolf container at `/etc/wolf/roms` does **not** expose them to
ES-DE, Pegasus, or RetroArch.

### Two layers: host mounts vs. ES-DE client state

Getting games to show up involves two independent layers:

1. **Host mounts (plugin scope).** The plugin makes your Unraid share visible to
   the emulator app by writing `…:/ROMs:rw` into Wolf's `config.toml` app runner.
   The plugin owns this layer. Use **Advanced → Fix mounts** if apps are not mapped
   (the dashboard **Libraries in apps** card shows per-app status).
2. **ES-DE client state (emulator/image scope, *not* plugin scope).** ES-DE seeds
   its Custom Scripts platform and writes `es_settings.xml` on first run, stored at:

   ```text
   /mnt/user/appdata/gow/<client-id>/EmulationStation/ES-DE/
   ```

   If an earlier setup wrote a bad ROM path into `es_settings.xml`, ES-DE keeps
   using it. **This is internal emulator state and the plugin deliberately does not
   edit it** — repairing app-internal config from the host is fragile and easy to
   get wrong across image updates.

### Custom Scripts missing or emulators won't launch

Once `/ROMs` is correctly mounted (layer 1 above), fix ES-DE's own state from
inside the app rather than from the host:

- In ES-DE: **Menu → Other Settings → ROM Directory**, point it at `/ROMs`, then
  reload. ES-DE recreates `es_systems.xml` / Custom Scripts for the detected
  systems.
- If ES-DE state is badly corrupted, remove the client's
  `…/EmulationStation/ES-DE/` folder and relaunch so the image reseeds defaults.
- Lay your ROMs out as `/ROMs/<system>/…` (for example `/ROMs/snes/…`) so ES-DE's
  systems are detected.

> The plugin's older "Repair ES-DE" action was removed: editing an emulator's
> internal config from the host belongs in the ES-DE image / upstream `gow`, not
> in the host installer. See the ecosystem dev skeleton for the upstream tracking.

## Health check

The settings dashboard includes a **Health check** panel (refreshes every 30
seconds) that verifies:

- Docker, Wolf, and Wolf Den are running
- GPU render node and NVIDIA Wayland settings (if applicable)
- Pairing files and Moonlight client count
- Wolf Den HTTP response
- Boot auto-start hook and udev rules
- Library mount presets in `config.toml` (when library paths are configured)
- **Libraries in apps** health item (Steam, Lutris, emulators, …)
- Stale session containers and OOM state

From the Unraid terminal:

```bash
bash /boot/config/plugins/gow/scripts/health-check.sh
```

Exit code `0` = healthy, `2` = degraded (warnings only), `1` = unhealthy.

The setup form shows the same style of check **before** you install (Docker, GPU,
NVIDIA settings, library paths, ghcr.io reachability).

### Fix all (one click)

When the dashboard health is not **healthy**, use **Fix all** on the health
panel (or Advanced). It runs, in order:

1. Cleanup stale Wolf session containers (exited)
2. Re-apply library mount presets in `config.toml`
3. Restart Wolf + Wolf Den
4. Remove running Wolf app session containers so the next Moonlight launch uses new mounts

From the terminal:

```bash
bash /boot/config/plugins/gow/scripts/fix-all.sh
```

### Library audit (diagnostics)

```bash
bash /boot/config/plugins/gow/scripts/library-audit.sh
```

### Check Wolf mount presets (all apps)

```bash
APPDATA=/mnt/user/appdata/gow
for app in EmulationStation Steam Lutris RetroArch; do
  echo "=== $app ==="
  grep -A20 "title = \"$app\"" "$APPDATA/cfg/config.toml" | grep -E 'mounts|GOW_REQUIRED'
done
```

You should see host paths like `/mnt/user/roms:/ROMs:rw` and
`/mnt/user/steam:/home/retro/.local/share/Steam:rw`, not `/etc/wolf/*` alone.

### E2E acceptance (after Fix mounts + Moonlight relaunch)

| App | Pass |
|-----|------|
| ES-DE | At least one system lists games; ROM layout `ROMs/<system>/` |
| Steam | Can add/install to library; files appear under host Steam share `steamapps/` |
| Lutris | Opens; `/var/lutris` is your host Lutris path, not an empty Docker volume |

## Out-of-memory (OOM) crashes

Wolf streaming is memory-heavy: Wolf itself encodes video, and each Moonlight
session spawns extra containers (ES-DE, Steam, PulseAudio helpers, etc.). On
Unraid boxes with limited RAM and many other Docker containers, the Linux OOM
killer may stop Wolf — or worse, freeze the whole server.

### Check whether Wolf was OOM-killed

```bash
docker inspect wolf --format '{{.State.OOMKilled}} {{.State.Status}}'
```

If the first value is `true`, Docker stopped Wolf because it hit a memory limit
or the host ran out of RAM.

Also check the kernel log:

```bash
dmesg -T | grep -i 'out of memory' | tail -20
```

The GoW settings dashboard warns when Wolf was OOM-killed or when host RAM is
under about 12 GiB.

### Quick fixes (most common)

1. **Update Wolf images** — older builds had encoding-pipeline memory leaks,
   especially when the client could not keep up with the requested framerate.
   Use **Update Images** in the plugin or:

   ```bash
   docker compose -f /mnt/user/appdata/gow/docker-compose.yml pull
   docker compose -f /mnt/user/appdata/gow/docker-compose.yml up -d --force-recreate
   ```

2. **Lower Moonlight load** — try 1080p, 60 fps, 20–40 Mbps. On **AMD GPUs**,
   force **H.264** instead of HEVC/H.265 in the Moonlight client; bad HEVC
   encoder behaviour has caused runaway memory use until Wolf was updated.

3. **Enable Unraid swap** — Settings → Docker / VM manager (or add a swap file
   on the cache/array). Some headroom prevents a single leak from killing Unraid.

4. **Clean up stale sessions** — after a crash, exited `Wolf*` containers may
   linger. In the plugin: Advanced → **Cleanup stale sessions**, or:

   ```bash
   bash /boot/config/plugins/gow/scripts/cleanup-wolf-sessions.sh
   ```

5. **Optional Wolf memory cap** — Reconfigure → **Memory limits** → set Wolf to
   something like `6G` on a 16 GiB box. Wolf may restart if it hits the cap, but
   Unraid stays up. Leave blank if you have plenty of RAM and want no cap.

### Heavy apps

First launch of ES-DE can download large RetroArch asset packs. Steam games,
RPCS3, and Yuzu-style emulators can use several gigabytes each. Avoid running
those alongside many other array-heavy containers if RAM is tight. The Apps
panel tags RAM-heavy apps (Steam, EmulationStation, Prism Launcher, Lutris,
Heroic) so you know which ones to watch.

### Capping a single app (e.g. PrismLauncher / Minecraft)

The plugin's memory cap applies to the **Wolf** container as a whole. To cap a
single app instead, edit that app in **Wolf Den**, or edit `config.toml`
directly and add a per-app limit plus a Java heap cap. For Prism Launcher /
Minecraft:

```toml
[[apps]]
title = "Prism Launcher"
# ... existing image / mounts ...
env = [
  "JAVA_MAX_MEM=4G",   # caps the Minecraft/Java heap
]
# Per-app container memory limit (Wolf passes this to Docker):
mem_limit = "6g"
mem_reservation = "4g"
```

Restart Wolf after editing (`docker compose -f /mnt/user/appdata/gow/docker-compose.yml restart wolf`).
`JAVA_MAX_MEM` caps the JVM heap; `mem_limit` is the hard container ceiling.
Keep `mem_limit` a couple of GiB above `JAVA_MAX_MEM` to leave room for the JVM
and native memory.

## Wolf management socket (Wolf ↔ Wolf Den)

Wolf Den talks to Wolf over a Unix socket, not a TCP port. The plugin shares it
through a Docker volume so both containers see the same socket:

| Where | Path |
|-------|------|
| Plugin compose (this repo) | `wolf-socket` volume mounted at `/tmp/sockets`, socket at `/tmp/sockets/wolf.sock` |
| Wolf Den env | `WOLF_SOCKET_PATH=/tmp/sockets/wolf.sock` |
| Wolf upstream default | `$XDG_RUNTIME_DIR/wolf.sock` (often `/var/run/wolf/wolf.sock`) |

If Wolf Den shows "cannot reach Wolf", confirm both containers mount the same
`wolf-socket` volume and that `WOLF_SOCKET_PATH` points at `/tmp/sockets/wolf.sock`.
The socket is **not** exposed on the network; only Wolf Den (same host) uses it.

## Pinning Wolf / Wolf Den image versions

By default the plugin runs the rolling `:stable` images. For a reproducible
install (so an upstream change can't shift your setup unexpectedly), pin a
specific digest. After a deploy/update the resolved digests are recorded at
`…/appdata/gow/cfg/.image-digests` and shown in the dashboard **Status** card.

To pin, copy a digest into `gow.cfg`:

```bash
# /boot/config/plugins/gow/gow.cfg
WOLF_IMAGE=ghcr.io/games-on-whales/wolf@sha256:<digest>
WOLF_DEN_IMAGE=ghcr.io/games-on-whales/wolf-den@sha256:<digest>
```

Then **Deploy** again. Leave them at `…:stable` to keep tracking the latest build.

## Managing Wolf apps

Add, remove, or edit apps (images, mounts, environment) in **Wolf Den**, not
in the plugin. After changing library paths in the plugin settings, use
**Deploy** / **Update Images** or Advanced → **Fix mounts** to re-apply ROM/BIOS
mount presets into Wolf's `config.toml`.

## Clean-slate wipe (fresh install)

Run on the Unraid server as **root**. This stops Wolf, removes boot hooks and
udev rules, uninstalls the settings UI package, and **deletes GoW appdata**
(including Moonlight pairing). Your ROMs/Steam shares elsewhere are not touched.

If the plugin is already installed:

```bash
bash /boot/config/plugins/gow/scripts/wipe-full.sh
```

To also remove the plugin files from the flash drive (reinstall from **Plugins**
afterward):

```bash
bash /boot/config/plugins/gow/scripts/wipe-full.sh --remove-plugin
```

Optional after an NVIDIA install (forces the driver volume to rebuild):

```bash
docker volume rm nvidia-driver-vol
docker image rm gow/nvidia-driver:latest 2>/dev/null || true
```

Then open **Settings → Games on Whales** and run setup again (or reinstall the
`.plg` first if you used `--remove-plugin`).

Self-contained one-liner (if the script is not on disk yet):

```bash
bash <<'GOW_WIPE'
set -eo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }
GOW_CFG=/boot/config/plugins/gow/gow.cfg
APPDATA=/mnt/user/appdata/gow
[[ -f "$GOW_CFG" ]] && source "$GOW_CFG"
APPDATA="${APPDATA:-/mnt/user/appdata/gow}"
COMPOSE="${APPDATA}/docker-compose.yml"
GO=/boot/config/go
echo "==> Stopping containers..."
[[ -f "$COMPOSE" ]] && docker compose -f "$COMPOSE" down 2>/dev/null || true
docker rm -f wolf wolf-den WolfPulseAudio 2>/dev/null || true
while read -r c; do [[ -n "$c" ]] && docker rm -f "$c" 2>/dev/null || true; done < <(docker ps -aq --filter 'name=Wolf' 2>/dev/null || true)
remove_go() {
  local marker="${1-}"
  [[ -n "$marker" ]] || return 0
  grep -qF "$marker" "$GO" 2>/dev/null || return 0
  echo "==> Removing $marker from /boot/config/go"
  local end="# End ${marker#\# }"
  local mr="${marker//\//\\/}" er="${end//\//\\/}"
  if grep -qF "$end" "$GO" 2>/dev/null; then sed -i "/${mr}/,/${er}/d" "$GO"
  else sed -i "/${mr}/,/^$/d" "$GO"; fi
}
remove_go "# GoW udev rules"
remove_go "# GoW docker-compose"
rm -f /etc/udev/rules.d/85-gow-virtual-inputs.rules /boot/config/gow-virtual-inputs.rules
udevadm control --reload-rules 2>/dev/null || true
rm -f /etc/cron.d/gow-health /tmp/gow-deploy.log /tmp/gow-update.log /tmp/gow-autostart.log
pkg=$(ls /boot/config/plugins/gow/packages/settings-ui-*.txz 2>/dev/null | tail -1 || true)
[[ -n "$pkg" ]] && /sbin/removepkg "$pkg" 2>/dev/null || true
echo "==> Removing appdata: $APPDATA"
rm -rf "$APPDATA"
echo "Done. Settings → Games on Whales → setup / Install."
GOW_WIPE
```
