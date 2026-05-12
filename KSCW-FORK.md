# KSC Wiedikon Fork

This is a fork of [OpnForm/OpnForm](https://github.com/OpnForm/OpnForm) with minimal customizations for the KSC Wiedikon self-hosted instance at `forms.kscw.ch`.

## Changes from upstream

All customizations live on the `kscw-customizations` branch. Two files only:

1. **`client/css/app.css`** — `--bg-form-color` / `--form-color` defaulted to `#4A55A2` (KSCW brand blue) instead of `var(--color-blue-500)`.
2. **`client/components/pages/forms/show/PoweredBy.vue`** — emptied. Hides the "Made with OpnForm" badge on rendered forms. (AGPL-compliant: this fork's source is public, so the modification obligation is satisfied.)

## CI

`.github/workflows/kscw-build.yml`:
- On push to `kscw-customizations` → builds & pushes `ghcr.io/lucanepa/opnform-client:kscw-latest` (+ version- and SHA-tagged variants)
- Weekly (Monday 04:00 UTC) → rebases `kscw-customizations` on upstream `main`, rebuilds. If rebase conflicts, workflow fails loudly — manual fix required.
- Manual `workflow_dispatch` → optional rebase + build.

## Consumer

Used by KSCW's Coolify-managed OpnForm deployment. To upgrade: nothing — the weekly job keeps the image fresh. To pin: Coolify image tag → `ghcr.io/lucanepa/opnform-client:kscw-<version>`.

## Conflict-resolution playbook

If the weekly rebase fails (a kscw-customizations patch touches the same lines as an upstream change):

```bash
git clone https://github.com/Lucanepa/OpnForm.git && cd OpnForm
git checkout kscw-customizations
git remote add upstream https://github.com/OpnForm/OpnForm.git
git fetch upstream main
git rebase upstream/main
# resolve conflicts in: client/css/app.css and/or PoweredBy.vue
git rebase --continue
git push --force-with-lease origin kscw-customizations
```

## Upstream source

https://github.com/OpnForm/OpnForm (AGPL-3.0)
