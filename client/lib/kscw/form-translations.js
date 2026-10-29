/**
 * KSCW fork: multilingual forms (see api/app/Service/Kscw/FormTranslations.php).
 *
 * English is always the form's own language. Translations are stored per language as a
 * flat map in `form.translations.kscw.texts[lang]`:
 *   "form.title" | "form.description" | "form.submitted_text" | "form.submit_button_text" |
 *   "form.re_fill_button_text" | "field.<id>.<name|placeholder|help|content|next_btn_text|
 *   previous_btn_text>" | "field.<id>.option.<English option name>"
 * Each entry is { t: translation, s: the English it was made from } — when the English
 * changes, `s` no longer matches and the entry counts as outdated.
 *
 * A translated form only changes what is SHOWN: select options keep their English `name`
 * (the stored value, what logic conditions compare against) and get a `kscw_label`.
 */

export const LANGUAGES = [
  { code: 'de', label: 'Deutsch', short: 'DE' },
  { code: 'fr', label: 'Français', short: 'FR' },
  { code: 'it', label: 'Italiano', short: 'IT' },
]
export const SOURCE = { code: 'en', label: 'English', short: 'EN' }

const FORM_KEYS = [
  ['title', false, 'Form title'],
  ['description', true, 'Form description'],
  ['submit_button_text', false, 'Submit button'],
  ['submitted_text', true, 'Text after submission'],
  ['re_fill_button_text', false, 'Fill again button'],
]
const FIELD_KEYS = [
  ['name', false, 'label'],
  ['placeholder', false, 'placeholder'],
  ['help', true, 'help text'],
  ['content', true, 'text'],
  ['next_btn_text', false, 'next button'],
  ['previous_btn_text', false, 'previous button'],
]
const OPTION_TYPES = ['select', 'multi_select']

const filled = (v) => typeof v === 'string' && v.trim() !== ''

export function kscwTranslations(form) {
  const k = form?.translations?.kscw
  return {
    languages: Array.isArray(k?.languages) ? k.languages.filter((l) => LANGUAGES.some((x) => x.code === l)) : [],
    texts: k?.texts && typeof k.texts === 'object' ? k.texts : {},
  }
}

/** Languages the respondent can pick: English plus the enabled ones that have any text. */
export function availableLanguages(form) {
  const { languages, texts } = kscwTranslations(form)
  const usable = languages.filter((l) => Object.keys(texts[l] ?? {}).length > 0)
  return usable.length ? [SOURCE.code, ...usable] : []
}

/** Every English text of the form that can be translated, in form order. */
export function sourceTexts(form) {
  const out = []
  for (const [key, html, label] of FORM_KEYS) {
    if (filled(form?.[key])) out.push({ key: `form.${key}`, text: form[key], html, label })
  }
  for (const p of form?.properties ?? []) {
    if (!p?.id) continue
    const fieldName = filled(p.name) ? p.name : p.type
    for (const [key, html, label] of FIELD_KEYS) {
      if (key === 'name' && p.type === 'nf-text') continue
      if (filled(p[key])) out.push({ key: `field.${p.id}.${key}`, text: p[key], html, label: `“${fieldName}” ${label}` })
    }
    if (OPTION_TYPES.includes(p.type)) {
      for (const o of p[p.type]?.options ?? []) {
        if (filled(o?.name)) out.push({ key: `field.${p.id}.option.${o.name}`, text: o.name, html: false, label: `“${fieldName}” choice` })
      }
    }
  }
  return out
}

/** Source texts of `lang` with no translation yet, or whose English changed since. */
export function pendingTexts(form, lang, { all = false } = {}) {
  const existing = kscwTranslations(form).texts[lang] ?? {}
  return sourceTexts(form).filter((s) => all || !filled(existing[s.key]?.t) || existing[s.key]?.s !== s.text)
}

/** The form as shown in `lang`; English (or an unknown language) returns it unchanged. */
export function translateForm(form, lang) {
  if (!form || !lang || lang === SOURCE.code || !availableLanguages(form).includes(lang)) return form
  const texts = kscwTranslations(form).texts[lang] ?? {}
  const tr = (key, english) => (filled(texts[key]?.t) ? texts[key].t : english)

  const out = { ...form, language: lang }
  for (const [key] of FORM_KEYS) {
    if (filled(form[key])) out[key] = tr(`form.${key}`, form[key])
  }
  out.properties = (form.properties ?? []).map((p) => {
    const q = { ...p }
    for (const [key] of FIELD_KEYS) {
      if (filled(p[key])) q[key] = tr(`field.${p.id}.${key}`, p[key])
    }
    if (OPTION_TYPES.includes(p.type) && Array.isArray(p[p.type]?.options)) {
      q[p.type] = {
        ...p[p.type],
        options: p[p.type].options.map((o) => ({ ...o, kscw_label: tr(`field.${p.id}.option.${o.name}`, o.name) })),
      }
    }
    return q
  })
  return out
}

/**
 * First language to show: ?lang= wins, then the browser's preferences (Accept-Language on
 * the server, navigator.languages in the browser), then English.
 */
export function pickLanguage(form, { query, preferred = [] } = {}) {
  const available = availableLanguages(form)
  if (!available.length) return SOURCE.code
  const wanted = [query, ...preferred]
    .filter(filled)
    .map((l) => l.toLowerCase().split(/[-_;]/)[0].trim())
  return wanted.find((l) => available.includes(l)) ?? SOURCE.code
}

/** "de-CH,de;q=0.9,en;q=0.8" → ['de-CH', 'de', 'en'] */
export function parseAcceptLanguage(header) {
  return String(header ?? '')
    .split(',')
    .map((part) => part.split(';')[0].trim())
    .filter(Boolean)
}
