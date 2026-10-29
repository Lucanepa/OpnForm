import { watch, onBeforeUnmount } from 'vue'
import { opnFetch } from '~/composables/useOpnApi.js'

const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

/**
 * KSCW fork: live "check in Wiedisync" for an email field with `wiedisync_lookup`
 * (see api/app/Service/Kscw/WiedisyncMemberLookup.php).
 *
 * Once the email (or the linked name field) settles, asks the API whether it belongs
 * to a club member and shows the answer under the email field. When the field opted in
 * with `wiedisync_show_values`, the response also carries the section/teams values to
 * fill. A convenience only — the submit runs the same check server-side — so a network
 * error or the rate limit is simply ignored.
 *
 * @param {() => object|null} getField      the rendered block
 * @param {() => object} getFormConfig      the form (slug)
 * @param {() => object} getDataForm        the reactive vForm (values + errors)
 * @param {import('vue').Ref<boolean>} isAdminPreview
 */
export function useWiedisyncLookup(getField, getFormConfig, getDataForm, isAdminPreview) {
  if (import.meta.server) return

  let timer = null
  let latest = 0
  let shownMessage = null

  const watched = () => {
    const field = getField()
    if (field?.type !== 'email' || !field.wiedisync_lookup || isAdminPreview?.value) return null
    const data = getDataForm()
    const email = data?.[field.id]
    const name = field.wiedisync_name_field ? data?.[field.wiedisync_name_field] : null
    // The form's language is part of the key: a language switch (multilingual forms) asks
    // again, so the message under the field follows the language.
    const language = getFormConfig()?.language ?? ''
    return `${typeof email === 'string' ? email.trim() : ''}\u0000${typeof name === 'string' ? name.trim() : ''}\u0000${language}`
  }

  watch(watched, (key) => {
    clearTimeout(timer)
    if (!key) return
    const [email, name] = key.split('\u0000')
    if (!EMAIL_SHAPE.test(email)) return
    timer = setTimeout(() => check(email, name), 700)
  })

  function check(email, name) {
    const field = getField()
    const slug = getFormConfig()?.slug
    const data = getDataForm()
    if (!field || !slug || !data) return
    const request = ++latest
    opnFetch(`/forms/${slug}/wiedisync-lookup`, {
      method: 'POST',
      body: { field_id: field.id, email, name: name || null },
    })
      .then((res) => {
        if (request !== latest) return // an answer to an older input
        const errors = data.errors
        if (res?.message) {
          errors?.set(field.id, res.message)
          shownMessage = res.message
        } else if (shownMessage && errors?.get(field.id) === shownMessage) {
          errors.clear(field.id)
          shownMessage = null
        }
        Object.entries(res?.fill ?? {}).forEach(([id, value]) => {
          data[id] = value
        })
      })
      .catch(() => {})
  }

  onBeforeUnmount(() => clearTimeout(timer))
}
