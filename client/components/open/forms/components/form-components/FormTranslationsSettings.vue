<template>
  <!-- KSCW fork: multilingual forms (lib/kscw/form-translations.js) -->
  <div class="space-y-6">
    <div>
      <h3 class="text-lg font-medium text-neutral-900 dark:text-white">
        Translations
      </h3>
      <p class="mt-1 text-sm text-neutral-500">
        Write the form in English. Respondents can switch to the languages you enable here
        (their browser language is picked first). Answers are always saved in English.
        Changes are saved with the form.
      </p>
    </div>

    <div class="space-y-2">
      <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
        Languages
      </p>
      <div class="flex flex-wrap gap-4">
        <UCheckbox
          v-for="l in LANGUAGES"
          :key="l.code"
          :model-value="languages.includes(l.code)"
          :label="l.label"
          @update:model-value="(on) => toggleLanguage(l.code, on)"
        />
      </div>
    </div>

    <div
      v-if="languages.length"
      class="flex flex-wrap items-center gap-2"
    >
      <UButton
        icon="i-heroicons-language"
        :loading="busy"
        :disabled="busy || !hasPending"
        label="Translate missing with DeepL"
        @click="translate(false)"
      />
      <UButton
        variant="outline"
        color="neutral"
        :disabled="busy"
        label="Retranslate everything"
        @click="translate(true)"
      />
      <span
        v-if="!hasPending"
        class="text-sm text-neutral-500"
      >All texts are translated.</span>
    </div>

    <div v-if="languages.length">
      <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-700">
        <button
          v-for="code in languages"
          :key="code"
          type="button"
          class="px-3 py-1.5 text-sm font-medium -mb-px border-b-2"
          :class="code === activeLang ? 'border-blue-500 text-blue-600' : 'border-transparent text-neutral-500 hover:text-neutral-800'"
          @click="activeLang = code"
        >
          {{ labelOf(code) }}
          <span
            v-if="pendingCount(code)"
            class="ml-1 text-xs text-amber-600"
          >({{ pendingCount(code) }} to do)</span>
        </button>
      </div>

      <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
        <div
          v-for="s in sources"
          :key="s.key"
          class="py-3 space-y-1"
        >
          <div class="flex items-center gap-2 text-xs text-neutral-500">
            <span class="font-medium">{{ s.label }}</span>
            <UBadge
              v-if="isOutdated(activeLang, s)"
              size="sm"
              color="warning"
              variant="subtle"
              label="English changed"
            />
          </div>
          <p class="text-xs text-neutral-400 line-clamp-2">
            {{ plain(s.text) }}
          </p>
          <UTextarea
            v-if="s.html || s.text.length > 80"
            :model-value="textOf(activeLang, s.key)"
            :rows="s.html ? 3 : 2"
            autoresize
            class="w-full"
            :placeholder="s.html ? 'HTML, as in the English text' : ''"
            @update:model-value="(v) => setText(activeLang, s, v)"
          />
          <UInput
            v-else
            :model-value="textOf(activeLang, s.key)"
            class="w-full"
            @update:model-value="(v) => setText(activeLang, s, v)"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { LANGUAGES, kscwTranslations, pendingTexts, sourceTexts } from '~/lib/kscw/form-translations.js'

const workingFormStore = useWorkingFormStore()
const { content: form } = storeToRefs(workingFormStore)
const alert = useAlert()

const busy = ref(false)
const state = computed(() => kscwTranslations(form.value))
const languages = computed(() => state.value.languages)
const sources = computed(() => sourceTexts(form.value))
const activeLang = ref(null)
watchEffect(() => {
  if (!languages.value.includes(activeLang.value)) activeLang.value = languages.value[0] ?? null
})

const labelOf = (code) => LANGUAGES.find((l) => l.code === code)?.label ?? code
const plain = (html) => String(html ?? '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
const textOf = (lang, key) => state.value.texts[lang]?.[key]?.t ?? ''
const isOutdated = (lang, s) => {
  const entry = state.value.texts[lang]?.[s.key]
  return !!entry?.t && entry.s !== s.text
}
const pendingCount = (lang) => pendingTexts(form.value, lang).length
const hasPending = computed(() => languages.value.some((l) => pendingCount(l) > 0))

/** Replace translations.kscw as a whole (the store hands out readonly proxies). */
function write(next) {
  const current = form.value.translations && typeof form.value.translations === 'object' ? form.value.translations : {}
  form.value.translations = { ...current, kscw: next }
}

function toggleLanguage(code, on) {
  const set = new Set(languages.value)
  if (on) set.add(code)
  else set.delete(code)
  // Texts of a switched-off language are kept, so switching it on again costs nothing.
  write({ ...state.value, languages: LANGUAGES.map((l) => l.code).filter((c) => set.has(c)) })
}

function setText(lang, source, value) {
  const texts = { ...state.value.texts, [lang]: { ...(state.value.texts[lang] ?? {}) } }
  if (value && value.trim() !== '') texts[lang][source.key] = { t: value, s: source.text }
  else delete texts[lang][source.key]
  write({ ...state.value, texts })
}

function translate(all) {
  if (!form.value?.id) {
    alert.error('Save the form once before translating it.')
    return
  }
  const jobs = languages.value
    .map((lang) => ({ lang, items: pendingTexts(form.value, lang, { all }) }))
    .filter((j) => j.items.length > 0)
  if (!jobs.length) return
  busy.value = true
  jobs.reduce((chain, job) => chain.then(() => opnFetch(`/open/forms/${form.value.id}/kscw-translate`, {
    method: 'POST',
    body: {
      languages: [job.lang],
      texts: job.items.map(({ key, text, html }) => ({ key, text, html })),
    },
  }).then((res) => {
    const translated = res?.translations?.[job.lang] ?? {}
    const texts = { ...state.value.texts, [job.lang]: { ...(state.value.texts[job.lang] ?? {}) } }
    job.items.forEach((s) => {
      if (typeof translated[s.key] === 'string') texts[job.lang][s.key] = { t: translated[s.key], s: s.text }
    })
    write({ ...state.value, texts })
  })), Promise.resolve())
    .then(() => alert.success('Translated. Check the texts, then save the form.'))
    .catch((error) => alert.error(error?.data?.message || 'Translation failed.'))
    .finally(() => { busy.value = false })
}
</script>
