<template>
  <!-- KSCW fork: "check in Wiedisync" for email fields (api: App\Service\Kscw\WiedisyncMemberLookup) -->
  <div class="px-4">
    <EditorSectionHeader
      icon="i-heroicons-identification"
      title="Wiedisync"
    />
    <toggle-switch-input
      :form="field"
      name="wiedisync_lookup"
      label="Check email in Wiedisync"
      help="Only current KSCW members can submit, with the email address Wiedisync or ClubDesk knows. If the form is linked to a private Wiedisync event, they are signed in to it."
    />
    <template v-if="field.wiedisync_lookup">
      <select-input
        :form="field"
        name="wiedisync_name_field"
        :options="fieldOptions(['text'])"
        label="Name field"
        clearable
        help="Picks the right member when siblings share one email address. Left empty, it gets the member's name from Wiedisync."
      />
      <select-input
        :form="field"
        name="wiedisync_section_field"
        :options="fieldOptions(TARGET_TYPES)"
        label="Fill section into"
        clearable
        help="Volleyball / Basketball, written from Wiedisync on submit."
      />
      <select-input
        :form="field"
        name="wiedisync_teams_field"
        :options="fieldOptions(TARGET_TYPES)"
        label="Fill team(s) into"
        clearable
        help="The member's current teams, written from Wiedisync on submit. Don't make this field or the section field required."
      />
      <toggle-switch-input
        :form="field"
        name="wiedisync_show_values"
        label="Show section and teams while filling in"
        help="Also fills an empty name field. Off (recommended for public links): name, section and teams are only saved with the submission, so the form never shows a stranger who an email belongs to. Hide the section and teams fields then."
      />
    </template>
  </div>
</template>

<script setup>
import EditorSectionHeader from '~/components/open/forms/components/form-components/EditorSectionHeader.vue'

const props = defineProps({
  field: { type: Object, required: true },
  form: { type: Object, required: true },
})

const TARGET_TYPES = ['text', 'select', 'multi_select']

/** Other fields of this form of the given types, as select options. */
const fieldOptions = (types) => (props.form?.properties ?? [])
  .filter((f) => f.id !== props.field.id && types.includes(f.type))
  .map((f) => ({ name: f.name, value: f.id }))
</script>
