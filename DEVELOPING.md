# Developer How-To

## Overview

The plugin has two installation phases:

1. **Phase 1 (headless, during plugin install)** — `preinstall.sh` runs checks, `install.sh` detects GPUs and writes `/boot/config/plugins/gow/gow.cfg`, and the `settings-ui` package is installed to register the emhttp page.
2. **Phase 2 (user-triggered, via the settings page)** — the user opens Settings > Games on Whales, picks a GPU and appdata path, and clicks Install. This calls `deploy.sh`, which writes udev rules, generates `docker-compose.yml`, builds the NVIDIA driver volume if needed, and starts Wolf + Wolf Den.

## Prerequisites

- Unraid 6.12+ (for testing in a VM or bare metal)
- Unraid installs plugins by fetching files over HTTP, so you need a local HTTP server to serve your development files.

## Serving files locally

From the repo root:

```sh
# Node.js
npx http-server -p 8888

# Python 3
python3 -m http.server 8888
```

Your files will be available at `http://<your-dev-machine-ip>:8888/`.

## Installing the development version

Open `gow.plg` and temporarily change `gitPkgURL` to point at your local server:

```xml
<!ENTITY gitPkgURL "http://<your-dev-machine-ip>:8888">
```

Also change `gitReleaseURL` to the same base with `/packages/settings-ui/dist`:

```xml
<!ENTITY gitReleaseURL "http://<your-dev-machine-ip>:8888/packages/settings-ui/dist">
```

> Do not commit these changes. Revert before pushing.

Then on your Unraid server:

```sh
plugin remove gow.plg          # remove any existing version
plugin install http://<your-dev-machine-ip>:8888/gow.plg
```

## Script reference

| Script | When it runs | What it does |
|---|---|---|
| `preinstall.sh` | Plugin install / boot replay | Unraid version check, plus non-fatal Docker, NVIDIA driver plugin, and network warnings |
| `install.sh` | Plugin install | GPU detection, writes `gow.cfg`, installs `settings-ui.txz` |
| `deploy.sh` | User clicks Install in UI | udev rules, appdata dirs, `docker-compose.yml`, containers, retrying boot hook |
| `uninstall.sh` | Plugin remove | Stops containers, cleans `/boot/config/go`, removes udev rules |
| `update.sh` | User clicks Update in UI | `docker compose pull && up -d --force-recreate`, re-applies mounts |
| `vars.sh` | Sourced by all scripts | Shared env vars (`GOW_CFG`, `GOW_PLUGIN`, `DEFAULT_APPDATA`, …) |
| `utils.sh` | Sourced by install/update | Package name/URL helpers and checksum-verified downloads |
| `pairing-state.sh` | Sourced by deploy/update | Backup/restore Wolf pairing identity (`config.toml`, `key.pem`, `cert.pem`) |
| `library-links.sh` | Deploy/update/mount presets | Symlink user library paths under `${APPDATA}/` when they live outside GoW appdata |
| `apply-mount-presets.sh` / `.py` | Deploy/update/fix | Merge plugin library paths into Wolf app runner mounts in `config.toml` |
| `detect-paths.sh` | Plugin install | Suggest existing ROM/BIOS/Steam/etc. share paths to pre-fill setup |
| `cleanup-wolf-sessions.sh` | UI / stop / Fix mounts | Remove exited `Wolf*` session containers that hold memory |
| `health-check.sh` | CLI | Print stack health; exit code reflects healthy/degraded/unhealthy |
| `fix-all.sh` | UI "Fix mounts" | Cleanup sessions, re-apply mount presets, restart Wolf |
| `reset.sh` | UI "Reset to Defaults" | Reset plugin settings to defaults (appdata kept) |
| `wipe-full.sh` | CLI (documented in FAQ) | Full clean-slate wipe of stack, hooks, UI package, and appdata |
| `hotfix-page.sh` | Dev | Install `dist/settings-ui.txz` only (UI under `/usr/local/emhttp/plugins/gow/`) |
| `library-audit.sh` | CLI / dev | Layer 1 vs Layer 2 library mount diagnostics |
| `dev-sync.sh` | Dev | Pull all scripts + settings UI from local HTTP server |
| `apply-ui.sh` | Dev / after plugin update | Re-run `installpkg` on the newest `settings-ui-*.txz` under `/boot/config/plugins/gow/packages/` |

## Config file

`/boot/config/plugins/gow/gow.cfg` (on the Unraid flash drive, persists across reboots):

```bash
APPDATA=/mnt/user/appdata/gow
RENDER_NODE=/dev/dri/renderD128
GPU_VENDOR=NVIDIA
GPU_NAME=RTX 3090
GPU_DRIVER=nvidia
WOLF_DEN_PORT=8080
WOLF_NETWORK_MODE=host
WOLF_NETWORK_NAME=
WOLF_NETWORK_IPV4=
DEPLOYED=true
```

`install.sh` creates this file. `gow.page` reads and writes it. `deploy.sh` sources it.

## Building the settings-ui package

The emhttp page (`gow.page`) and its assets are shipped as a Slackware `.txz` package.

```sh
cd packages/settings-ui/root
../../../utils/fmakepkg.sh ../../../packages/settings-ui/dist/settings-ui-<version>.txz
cd ../dist
sha256sum settings-ui-<version>.txz | awk '{print $1}' > settings-ui-<version>.txz.sha256
md5sum    settings-ui-<version>.txz | awk '{print $1}' > settings-ui-<version>.txz.md5
```

Update the `<SHA256>` and `<MD5>` fields in `gow.plg` after each rebuild.

During development, serve the **repo root** (not only `packages/settings-ui/dist`) from your local HTTP server so `gow-dev.plg`, `scripts/*`, and `dist/settings-ui.txz` are reachable. The `unraid-dev/prepare.ps1` + `serve.ps1` helpers automate this on Windows; see `unraid-dev/prepare.ps1` output for exact URLs.

### Local dev install (recommended)

On your dev PC (repo root, branch with your changes):

```powershell
cd unraid-dev
.\prepare.ps1          # build dist/settings-ui.txz, generate gow-dev.plg
.\serve.ps1            # keep running — serves the repo on :8888
```

On Unraid (**first time only**):

```sh
plugin remove gow.plg
plugin install http://<dev-ip>:8888/gow-dev.plg
```

Then open **Settings → Games on Whales** and complete setup (GPU, appdata, Install).

**Production (no dev server):**

```sh
plugin remove gow.plg 2>/dev/null
plugin install https://github.com/games-on-whales/unraid-plugin/releases/latest/download/gow.plg
```

### After you change code

The plugin ships two things that live in different places on Unraid:

| What | Where on Unraid | How it gets there |
|---|---|---|
| Shell/Python scripts (`deploy.sh`, `apply-mount-presets.py`, …) | `/boot/config/plugins/gow/scripts/` | Downloaded from your dev server on plugin install/update |
| Settings UI (`gow.page`, `php/*`) | `/usr/local/emhttp/plugins/gow/` | Installed from `dist/settings-ui.txz` via `installpkg` |

**Update Images** on the dashboard only refreshes Wolf Docker images — it does **not** update the plugin UI or scripts.

After edits on your PC:

```powershell
.\prepare.ps1 -SkipGit    # rebuild txz only; keep serve.ps1 running
```

On Unraid:

```sh
bash /boot/config/plugins/gow/scripts/dev-sync.sh http://<dev-ip>:8888
```

That one command re-downloads all scripts and reinstalls the settings UI. You only need `plugin install …/gow-dev.plg` again when `dev-sync.sh` is missing (very first dev install) or when you change `gow.plg` itself.

`hotfix-page.sh` alone only updates the UI — use it only if you changed `gow.page`/`php/*` and not any script under `scripts/`.

### UI changes not showing on Unraid

The settings page (`gow.page`, `php/*`) is **not** read from `/boot/config/plugins/gow/`. It is installed into `/usr/local/emhttp/plugins/gow/` by `installpkg` when the plugin runs `install.sh`. These actions **do not** refresh the UI:

- Clicking **Update Images** on the GoW dashboard (that only updates Wolf Docker images).
- Editing files on your PC without rebuilding and reinstalling the txz.
- Updating only shell scripts on the server (unless you also reinstall the txz).

**Check what is actually installed** (on Unraid as root):

```sh
grep -c gow-health-card /usr/local/emhttp/plugins/gow/gow.page
ls -la /usr/local/emhttp/plugins/gow/php/
ls -lt /boot/config/plugins/gow/packages/settings-ui-*.txz
```

If `gow-health-card` is missing, the running UI is still an old build. Fix:

1. Rebuild the package (from `packages/settings-ui/root`):
   ```sh
   ../../../utils/fmakepkg.sh ../../../dist/settings-ui.txz
   ```
2. Serve `dist/settings-ui.txz` from your dev machine (`python3 -m http.server 8888` in the repo root).
3. On Unraid, either:
   - `bash /boot/config/plugins/gow/scripts/hotfix-page.sh http://<dev-ip>:8888` (downloads txz and runs `installpkg`), or
     - Copy `settings-ui.txz` to `/boot/config/plugins/gow/packages/settings-ui-2026.05.30.txz` and run:
     ```sh
     bash /boot/config/plugins/gow/scripts/apply-ui.sh
     ```
   - Or **Plugins → Games on Whales → Update** after publishing a matching GitHub release (version in `gow.plg` must match the tag that ships `settings-ui.txz`).

4. Hard-refresh the browser (Ctrl+F5). If the page is still wrong, reload nginx: `/etc/rc.d/rc.nginx reload`.

Production installs pull `settings-ui.txz` from the GitHub **release** for the version in `gow.plg`. Bumping `gow.plg` to `2026.05.29` without a `2026.05.29` release tag leaves the plugin update unable to fetch the new txz.

## Releasing

1. Update the `version` entity in `gow.plg` to today's date (`YYYY.MM.DD`). For same-day hotfixes, append a suffix such as `a`.
2. Update `GOW_VERSION` in `scripts/vars.sh` to match.
3. Commit and merge the release change, then create a git tag matching the version:
   ```sh
   git tag 2026.04.10
   git push origin 2026.04.10
   ```
4. The `release` GitHub Actions workflow triggers automatically, builds the package, and creates a GitHub release with `settings-ui.txz`, its checksums, and `gow.plg` as release assets. The moving install/update URL points at that latest release asset, so do not bump `version` without publishing the matching tag.

## Adding a new package

1. Create `packages/<name>/root/` with the desired filesystem layout.
2. Add `packages/<name>/root/install/slack-desc` (copy an existing one and adapt).
3. Add any start/stop scripts under `packages/<name>/root/boot/config/plugins/gow/scripts/start/` and `.../stop/`.
4. Build with `fmakepkg.sh`, add a `<FILE>` entry in `gow.plg`, and install it in `install.sh`.
