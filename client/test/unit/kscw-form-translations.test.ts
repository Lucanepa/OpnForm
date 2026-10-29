// KSCW fork: multilingual forms (lib/kscw/form-translations.js).
import { describe, it, expect } from 'vitest'
import {
  availableLanguages, pendingTexts, pickLanguage, parseAcceptLanguage, sourceTexts, translateForm,
} from '../../lib/kscw/form-translations.js'
import { getFieldOptions } from '../../lib/forms/field-options.js'

const base = {
  title: 'Referee course',
  description: '<p>Sign up <b>now</b></p>',
  submit_button_text: 'Send',
  properties: [
    { id: 'n', type: 'text', name: 'Name', placeholder: 'Your full name' },
    { id: 's', type: 'select', name: 'Day', select: { options: [{ id: '1', name: 'Saturday' }, { id: '2', name: 'Sunday' }] } },
    { id: 't', type: 'nf-text', name: 'Text', content: '<p>Bring shoes</p>' },
  ],
}
const withDe = (texts: Record<string, { t: string, s: string }>, languages = ['de']) =>
  ({ ...base, translations: { focused_next_button_text: 'x', kscw: { languages, texts: { de: texts } } } })

describe('sourceTexts', () => {
  it('collects form, field, choice and block texts with their HTML flag', () => {
    const keys = sourceTexts(base).map((s) => [s.key, s.html])
    expect(keys).toEqual([
      ['form.title', false], ['form.description', true], ['form.submit_button_text', false],
      ['field.n.name', false], ['field.n.placeholder', false],
      ['field.s.name', false], ['field.s.option.Saturday', false], ['field.s.option.Sunday', false],
      ['field.t.content', true],
    ])
  })
})

describe('translateForm', () => {
  const form = withDe({
    'form.title': { t: 'Schiedsrichterkurs', s: 'Referee course' },
    'field.n.name': { t: 'Name', s: 'Name' },
    'field.n.placeholder': { t: 'Dein vollständiger Name', s: 'Your full name' },
    'field.s.option.Saturday': { t: 'Samstag', s: 'Saturday' },
  })

  it('shows translations, falls back to English, keeps English option values', () => {
    const de = translateForm(form, 'de')
    expect(de.title).toBe('Schiedsrichterkurs')
    expect(de.language).toBe('de')
    expect(de.submit_button_text).toBe('Send') // no translation yet
    expect(de.properties[0].placeholder).toBe('Dein vollständiger Name')
    expect(getFieldOptions(de.properties[1])).toEqual([
      { name: 'Samstag', value: 'Saturday', image: null },
      { name: 'Sunday', value: 'Sunday', image: null },
    ])
    expect(form.title).toBe('Referee course') // source untouched
  })

  it('returns the form itself for English, unknown or disabled languages', () => {
    expect(translateForm(form, 'en')).toBe(form)
    expect(translateForm(form, 'fr')).toBe(form)
    expect(translateForm(withDe({ 'form.title': { t: 'X', s: 'Referee course' } }, []), 'de').title).toBe('Referee course')
  })
})

describe('pendingTexts', () => {
  it('lists missing and outdated texts, or everything on request', () => {
    const form = withDe({
      'form.title': { t: 'Kurs', s: 'Old title' }, // English changed since
      'field.n.name': { t: 'Name', s: 'Name' },
    })
    const pending = pendingTexts(form, 'de').map((s) => s.key)
    expect(pending).toContain('form.title')
    expect(pending).not.toContain('field.n.name')
    expect(pendingTexts(form, 'de', { all: true })).toHaveLength(sourceTexts(base).length)
  })
})

describe('language choice', () => {
  const form = withDe({ 'form.title': { t: 'Kurs', s: 'Referee course' } })

  it('offers English plus enabled languages that have texts', () => {
    expect(availableLanguages(form)).toEqual(['en', 'de'])
    expect(availableLanguages(base)).toEqual([])
    expect(availableLanguages(withDe({}, ['de']))).toEqual([])
  })

  it('?lang= wins, then the browser, then English', () => {
    expect(pickLanguage(form, { query: 'en', preferred: ['de-CH'] })).toBe('en')
    expect(pickLanguage(form, { preferred: parseAcceptLanguage('fr-CH,de-CH;q=0.8,en;q=0.5') })).toBe('de')
    expect(pickLanguage(form, { preferred: ['it'] })).toBe('en')
    expect(pickLanguage(base, { query: 'de' })).toBe('en')
  })
})
