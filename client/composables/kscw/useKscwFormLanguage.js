import { computed, nextTick, watchEffect } from 'vue'
import {
  availableLanguages,
  parseAcceptLanguage,
  pickLanguage,
  translateForm,
} from '~/lib/kscw/form-translations.js'

/**
 * KSCW fork: the respondent's language on a multilingual public form.
 *
 * `displayForm` is what OpenCompleteForm renders. Switching language swaps it; the form
 * manager re-initialises on a new form object, so the answers typed so far (and the page
 * the respondent is on) are carried over by hand. The effective language is mirrored into
 * `useState('kscw-form-locale')`, which the API request helper sends as `X-Form-Locale`
 * (messages from the server in the same language).
 *
 * @param {import('vue').Ref<object|null>} form   the form as loaded (English)
 * @param {import('vue').Ref<object|null>} completeFormRef   OpenCompleteForm instance
 */
export function useKscwFormLanguage(form, completeFormRef) {
  const route = useRoute()
  const chosen = useState('kscw-form-lang-chosen', () => null)
  // Same list on the server (Accept-Language) and after hydration, so both render alike.
  const preferred = useState('kscw-form-lang-preferred', () => (import.meta.server
    ? parseAcceptLanguage(useRequestHeaders(['accept-language'])['accept-language'])
    : [...(globalThis.navigator?.languages ?? [])]))
  const locale = useState('kscw-form-locale', () => null)

  const languages = computed(() => availableLanguages(form.value))
  const lang = computed(() => {
    if (chosen.value && languages.value.includes(chosen.value)) return chosen.value
    return pickLanguage(form.value, { query: route.query.lang, preferred: preferred.value })
  })
  const displayForm = computed(() => translateForm(form.value, lang.value))

  watchEffect(() => {
    locale.value = languages.value.length ? lang.value : null
  })

  async function setLanguage(next) {
    if (!languages.value.includes(next) || next === lang.value) return
    const manager = completeFormRef.value?.formManager
    const answers = manager?.form?.data ? { ...manager.form.data() } : null
    const page = manager?.state?.currentPage ?? 0
    chosen.value = next
    if (!manager || !answers) return
    // OpenCompleteForm's prop watcher re-initialises with the translated form first.
    await nextTick()
    await manager.updateConfig(manager.config.value, { defaultData: answers })
    manager.state.currentPage = page
  }

  return { lang, languages, displayForm, setLanguage }
}
