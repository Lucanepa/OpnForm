# KSC Wiedikon Fork

This is a fork of [OpnForm/OpnForm](https://github.com/OpnForm/OpnForm) with minimal customizations for the KSC Wiedikon self-hosted instance at `forms.kscw.ch`.

## Changes from upstream

All customizations live on the `kscw-customizations` branch, rebased on the latest upstream **release tag** (not `main`).

### Client

1. **`client/css/app.css`** — `--bg-form-color` / `--form-color` defaulted to `#4A55A2` (KSCW brand blue) instead of `var(--color-blue-500)`.
2. **`client/components/pages/forms/show/PoweredBy.vue`** — emptied. Hides the "Made with OpnForm" badge on rendered forms. (AGPL-compliant: this fork's source is public, so the modification obligation is satisfied.)
3. **`client/components/open/tables/OpenTable.vue`** — submission export bugfix. The export modal was passed the raw `columnVisibility` map, which includes UI-only synthetic columns (`select`, `actions`) and conditional meta columns (`status`, `ip_address`). The export API rejects any key that isn't a form property / `removed_property` / `created_at` with `422 "The columns contain invalid values: select, actions"`, so the in-app Export button was broken for every form. Fix adds an `exportColumnVisibility` computed that whitelists valid keys before the POST. **This is a genuine upstream bug** — submit upstream and drop this patch once merged. Since v2.6 the backend also accepts `status` and `meta.attribution.*`, so the whitelist includes those (mirror `FormSubmissionExportRequest::rules` if it changes again).

### API (since v2.6.1)

Upstream's `jhumanj/opnform-api` image ships a stale `composer.lock` (69 `composer audit` advisories at v2.6.1: 3 critical, 24 high), and the Laravel 11 email-rule CRLF advisory is only fixed in 12.x. So the fork builds its own API image:

4. **`api/composer.json` / `api/composer.lock`** — `laravel/framework` `^12.0`, `pestphp/pest(-plugin-laravel)` `^3.0`, `enshrined/svg-sanitize` `^1.1.1`; everything else updated within constraints (`spatie/laravel-sitemap` 7, `spatie/laravel-sluggable` 3, `openai-php/client` 0.19, `stripe/stripe-php` 17 held at their majors via `--with`).
5. **`api/tests/TestHelpers.php`** — `actingAsGuest($guard = null)` to match the Laravel 12 `TestCase` signature; **`api/tests/Unit/Service/Forms/MentionParserTest.php`** — one duplicate test name renamed (Pest 3 rejects duplicates).

Refreshing the lock (PHP 8.3, from `api/`): `composer update -W --no-install --no-scripts --ignore-platform-req='ext-*' --with spatie/laravel-sitemap:^7.3 --with spatie/laravel-sluggable:^3.7 --with openai-php/client:~0.19.0 --with stripe/stripe-php:^17`, then `composer audit --locked`.

### Check in Wiedisync (since 2026-10-29)

An email field can be set to **"Check email in Wiedisync"** (field options → Wiedisync). A public form then only accepts the address Wiedisync or ClubDesk knows for a current KSCW member, writes the member's section and teams into the stored submission, and — if the form is linked to a private sign-up event in Wiedisync (`events.form_signup_only`, wiedisync migration 434) — signs them in to it.

- Per-field keys on the email property: `wiedisync_lookup`, `wiedisync_name_field` (picks the member when siblings share one address), `wiedisync_section_field`, `wiedisync_teams_field`, `wiedisync_show_values` (default off: section/teams never go back to the browser, only into the submission — hide those fields; don't make them required).
- API (all new files under `Kscw/` except four one-line hooks): `app/Service/Kscw/WiedisyncMemberLookup.php`, `app/Rules/Kscw/WiedisyncMemberRule.php` (hooked into `AnswerFormRequest::getPropertyRules` `email` case), the fill in `PublicFormController::answer` before the job, `POST /forms/{form}/wiedisync-lookup` (`app/Http/Controllers/Kscw/WiedisyncLookupController.php`, `throttle:20,1`, in `routes/api.php`), the queued `app/Listeners/Kscw/NotifyWiedisyncSignup.php` on `FormSubmitted` (`EventServiceProvider`), `config/kscw.php`, messages `resources/lang/{en,de,fr,it}/kscw.php`. Tests `tests/Feature/Kscw/`.
- Client: `components/open/forms/fields/components/WiedisyncLookupOptions.vue` (+3 lines in `FieldOptions.vue`), `composables/kscw/useWiedisyncLookup.js` (+2 lines in `BlockRenderer.vue`). Test `test/unit/kscw-wiedisync-lookup.test.ts`.
- Env (api, api-worker, api-scheduler): `WIEDISYNC_API_URL` (Directus over the Docker network, e.g. `http://directus-kscw:8055` — Wiedisync answers 404 to anything coming through Cloudflare, so the API containers must share a network with Directus), `WIEDISYNC_SERVICE_TOKEN` (plain; Wiedisync holds its SHA-256 in `OPNFORM_SERVICE_TOKEN_HASH`), optional `WIEDISYNC_TIMEOUT` (s, default 5). Unset → the option is dormant (submissions accepted unchecked).
- Wiedisync down at submit → the email field says "try again in a minute" (refused, never accepted unchecked). The sign-up hand-off retries for ~1.5 h.
- Rebase conflicts: the four hooks are one-liners marked `KSCW fork`; re-apply them if upstream moved the surrounding code.

### Multilingual forms with DeepL (since 2026-10-30)

Forms are always written in **English**. Settings → **Translations**: enable Deutsch / Français / Italiano, press **Translate missing with DeepL**, correct any text, save the form. Each translation remembers the English it came from; when the English changes it is marked "English changed" and "Translate missing" picks it up again.

- Public form: a language switch (EN + enabled languages with texts) above the form, starting in the visitor's browser language (or `?lang=de`). Answers typed so far survive a switch.
- Only what is shown changes. Select options keep their English `name` (stored value, logic conditions, exports); the translation rides along as `kscw_label` (`client/lib/forms/field-options.js`).
- Storage: `forms.translations.kscw = {languages, texts: {de: {"form.title": {t, s}, "field.<id>.option.<English>": {t, s}, …}}}` in the existing `translations` json column — no migration. Upstream's own `translations.focused_next_button_text` stays untouched.
- The chosen language is sent as `X-Form-Locale` on public form requests (`client/lib/kscw/request-locale.js`, one line in `useOpnApi.js`); `App\Service\Kscw\FormTranslations::applyRequestLocale` (one line in `AnswerFormRequest`) switches validation and "check in Wiedisync" messages to it when the form offers that language.
- Translated: form title, description, submit / fill-again buttons, text after submission; field labels, placeholders, help texts, text blocks, page-break buttons, select / multi-select choices. Not (yet): matrix rows/columns (their names are stored keys), rating/scale labels, emails to respondents.
- API: `POST /open/forms/{form}/kscw-translate` (`App\Http\Controllers\Kscw\FormTranslationController`, form editors only, `throttle:20,1`) translates the texts the editor sends via `App\Service\Kscw\DeeplTranslator` (source EN, `formality: prefer_less`, HTML texts with `tag_handling=html`, ≤ 200 000 chars per run). Env `DEEPL_API_KEY` (a key ending in `:fx` = API Free → api-free.deepl.com). Unset → the editor says so (503).
- Client files: `lib/kscw/form-translations.js`, `composables/kscw/useKscwFormLanguage.js`, `components/open/forms/kscw/FormLanguageSwitch.vue`, `components/open/forms/components/form-components/FormTranslationsSettings.vue` (+ a tab in `FormSettingsModal.vue`), a few lines in `pages/forms/[slug]/index.vue`. Tests `test/unit/kscw-form-translations.test.ts`, `api/tests/Feature/Kscw/FormTranslationTest.php`.

## CI

`.github/workflows/kscw-build.yml`:
- On push to `kscw-customizations` → builds & pushes `ghcr.io/lucanepa/opnform-client` **and** `ghcr.io/lucanepa/opnform-api`, each as `kscw-latest`, `kscw-<upstream version>` and `kscw-<sha>`. Runs `composer audit` first (warning only).
- Weekly (Monday 04:00 UTC) → rebases `kscw-customizations` on the latest upstream release tag, rebuilds. If rebase conflicts, workflow fails loudly — manual fix required. `api/composer.lock` conflicts whenever upstream touches it: take upstream's file, re-run the lock refresh above, commit.
- Manual `workflow_dispatch` → optional rebase + build.

## Consumer

Used by KSCW's OpnForm deployment (plain Compose in `/opt/opnform` on the Infomaniak VPS since 26.10.2026; Coolify before) (`opnform-ui` ← client image; `opnform-api`, `api-worker`, `api-scheduler` ← API image). Pin both to the same `kscw-<sha>`; client and API must come from the same commit. After recreating api/ui containers, restart the nginx ingress (it caches upstream IPs) and check a live form via `/api/forms/<slug>`, not just the homepage.

## Conflict-resolution playbook

If the weekly rebase fails (a kscw-customizations patch touches the same lines as an upstream change):

```bash
git clone https://github.com/Lucanepa/OpnForm.git && cd OpnForm
git checkout kscw-customizations
git remote add upstream https://github.com/OpnForm/OpnForm.git
git fetch --tags upstream
git rebase "$(git tag --list 'v[0-9]*' --sort=-v:refname | head -1)"
# resolve conflicts in: api/composer.lock (see lock refresh above),
# client/css/app.css, PoweredBy.vue, and/or
# components/open/tables/OpenTable.vue (export whitelist patch — keep the
# exportColumnVisibility computed + the :columns binding on FormExportModal)
git rebase --continue
git push --force-with-lease origin kscw-customizations
```

## Upstream source

https://github.com/OpnForm/OpnForm (AGPL-3.0)
