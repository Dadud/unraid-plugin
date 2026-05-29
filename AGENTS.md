## Cursor Cloud specific instructions

This is an Unraid plugin codebase (Bash scripts + PHP settings page + Python helper). It is **not** a traditional application — there is no `package.json`, `Makefile`, or Dockerfile. The development workflow centers on linting, building a Slackware `.txz` package, and serving files via a local HTTP server for installation on an Unraid target.

### Languages and linting

| Language | Files | Lint command |
|----------|-------|--------------|
| Bash | `scripts/*.sh`, `utils/fmakepkg.sh` | `shellcheck scripts/*.sh utils/fmakepkg.sh` |
| PHP | `packages/settings-ui/root/usr/local/emhttp/plugins/gow/**/*.{page,php}` | `php -l <file>` on each file |
| Python | `scripts/apply-mount-presets.py` | `python3 -c "import ast; ast.parse(open('scripts/apply-mount-presets.py').read())"` |

Shellcheck will report SC1090/SC1091 (info) for `source` directives — these are expected because scripts source each other at runtime via relative paths.

### Building the settings-ui package

```sh
cd packages/settings-ui/root
../../../utils/fmakepkg.sh ../../../dist/settings-ui.txz
cd /workspace/dist
sha256sum settings-ui.txz | awk '{print $1}' > settings-ui.txz.sha256
md5sum    settings-ui.txz | awk '{print $1}' > settings-ui.txz.md5
```

### Serving files for development

Per `DEVELOPING.md`, serve the repo root on port 8888:

```sh
python3 -m http.server 8888
```

Key files (`gow.plg`, `scripts/*`, `dist/settings-ui.txz`) must all be reachable from the Unraid target at `http://<dev-ip>:8888/`.

### No automated test suite

This project has no unit/integration test framework. Validation is:
1. `shellcheck` on Bash scripts
2. `php -l` on PHP files
3. Python syntax check on `scripts/apply-mount-presets.py`
4. Building the `.txz` package successfully
5. End-to-end testing requires an actual Unraid server (VM or bare metal)
