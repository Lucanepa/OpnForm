<template>
  <!-- KSCW fork: language picker of a multilingual public form -->
  <div class="w-full max-w-2xl mx-auto px-4 pt-4 flex justify-end">
    <div
      role="group"
      aria-label="Language"
      class="inline-flex rounded-lg border border-neutral-200 dark:border-neutral-700 p-0.5 bg-white/70 dark:bg-neutral-900/70"
    >
      <button
        v-for="code in languages"
        :key="code"
        type="button"
        :lang="code"
        :aria-pressed="code === modelValue"
        :title="labelOf(code)"
        class="min-w-10 px-2.5 py-1 text-sm font-medium rounded-md transition-colors"
        :class="code === modelValue
          ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900'
          : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white'"
        @click="emit('update:modelValue', code)"
      >
        {{ shortOf(code) }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { LANGUAGES, SOURCE } from '~/lib/kscw/form-translations.js'

defineProps({
  languages: { type: Array, required: true },
  modelValue: { type: String, required: true },
})
const emit = defineEmits(['update:modelValue'])

const meta = (code) => [SOURCE, ...LANGUAGES].find((l) => l.code === code)
const labelOf = (code) => meta(code)?.label ?? code
const shortOf = (code) => meta(code)?.short ?? code.toUpperCase()
</script>
