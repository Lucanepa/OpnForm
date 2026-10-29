// KSCW fork: live "check in Wiedisync" on email fields (composables/kscw/useWiedisyncLookup.js).
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { reactive, ref, nextTick } from 'vue'

const opnFetch = vi.fn()
vi.mock('~/composables/useOpnApi.js', () => ({ opnFetch: (...args: unknown[]) => opnFetch(...args) }))
vi.mock('vue', async (orig) => ({ ...(await orig<typeof import('vue')>()), onBeforeUnmount: () => {} }))

const { useWiedisyncLookup } = await import('../../composables/kscw/useWiedisyncLookup.js')
const Errors = (await import('../../composables/lib/vForm/Errors.js')).default

const field = { id: 'mail', type: 'email', wiedisync_lookup: true, wiedisync_name_field: 'name' }

function setup(fieldOverrides = {}, preview = false) {
  const data = reactive<Record<string, unknown>>({ mail: '', name: '', errors: new Errors() })
  const f = { ...field, ...fieldOverrides }
  useWiedisyncLookup(() => f, () => ({ slug: 'kurs' }), () => data, ref(preview))
  return data as Record<string, unknown> & { errors: InstanceType<typeof Errors> }
}

async function settle() {
  await nextTick()
  await vi.advanceTimersByTimeAsync(800)
}

describe('useWiedisyncLookup', () => {
  beforeEach(() => { vi.useFakeTimers(); opnFetch.mockReset() })
  afterEach(() => vi.useRealTimers())

  it('checks once the address settles and shows the message on the email field', async () => {
    opnFetch.mockResolvedValue({ status: 'not_found', message: 'Not in Wiedisync' })
    const data = setup()
    data.mail = 'anna@'
    await settle()
    expect(opnFetch).not.toHaveBeenCalled() // not an address yet
    data.mail = 'anna@example.com'
    await settle()
    expect(opnFetch).toHaveBeenCalledTimes(1)
    expect(opnFetch).toHaveBeenCalledWith('/forms/kurs/wiedisync-lookup', {
      method: 'POST', body: { field_id: 'mail', email: 'anna@example.com', name: null },
    })
    expect(data.errors.get('mail')).toBe('Not in Wiedisync')
  })

  it('rechecks when the name changes, clears its own message and fills opted-in values', async () => {
    opnFetch.mockResolvedValueOnce({ status: 'ambiguous', message: 'Enter your name' })
    const data = setup()
    data.mail = 'family@example.com'
    await settle()
    expect(data.errors.get('mail')).toBe('Enter your name')

    opnFetch.mockResolvedValueOnce({ status: 'found', message: null, fill: { section: 'Volleyball', teams: 'H1' } })
    data.name = 'Luca Müller'
    await settle()
    expect(opnFetch).toHaveBeenLastCalledWith('/forms/kurs/wiedisync-lookup', {
      method: 'POST', body: { field_id: 'mail', email: 'family@example.com', name: 'Luca Müller' },
    })
    expect(data.errors.has('mail')).toBe(false)
    expect(data.section).toBe('Volleyball')
    expect(data.teams).toBe('H1')
  })

  it('leaves errors it did not set alone and ignores network failures', async () => {
    opnFetch.mockRejectedValue(new Error('429'))
    const data = setup()
    data.errors.set('mail', 'The email field is required.')
    data.mail = 'anna@example.com'
    await settle()
    expect(data.errors.get('mail')).toBe('The email field is required.')
  })

  it('does nothing for fields without the option or in the editor preview', async () => {
    const plain = setup({ wiedisync_lookup: false })
    plain.mail = 'anna@example.com'
    const preview = setup({}, true)
    preview.mail = 'anna@example.com'
    await settle()
    expect(opnFetch).not.toHaveBeenCalled()
  })
})
