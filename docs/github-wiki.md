# GitHub Wiki Workflow

This repository keeps all wiki pages under version control in `docs/wiki/`.

Keeping wiki pages inside `docs/wiki/` ensures architectural docs evolve in the same pull requests as migrations, services, and contracts instead of drifting in a separate unreviewed repo.

## Syncing to the GitHub Wiki

1. Ensure the GitHub Wiki is initialized on `adamgreenwell/coqui-platform` (GitHub creates the `.wiki.git` repository automatically as soon as the first wiki page is saved in the GitHub web UI, or if already initialized).
2. Clone the wiki repository beside `coqui-platform`:

```bash
git clone https://github.com/adamgreenwell/coqui-platform.wiki.git ../coqui-platform.wiki
```

3. Sync `docs/wiki/` into the wiki checkout:

- **PowerShell (Windows):**

```powershell
./scripts/sync-github-wiki.ps1 ../coqui-platform.wiki
```

- **Bash (macOS / Linux):**

```bash
./scripts/sync-github-wiki.sh ../coqui-platform.wiki
```

4. Inspect `git -C ../coqui-platform.wiki status --short` and `diff --stat`, then commit and push to publish.
