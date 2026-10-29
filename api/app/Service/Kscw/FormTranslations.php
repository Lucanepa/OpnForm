<?php

namespace App\Service\Kscw;

use App\Models\Forms\Form;
use Illuminate\Http\Request;

/**
 * KSCW fork: multilingual forms. English is always the form's own language; translations
 * live in `forms.translations.kscw` (no migration — `translations` is an existing json
 * column):
 *
 *   { "languages": ["de", "fr", "it"],
 *     "texts": { "de": { "form.title": { "t": "…", "s": "<English it was made from>" },
 *                        "field.<id>.name": {…}, "field.<id>.option.<English option>": {…} } } }
 *
 * The respondent picks the language in the browser (client lib/kscw/form-translations.js);
 * answers are stored with the English option values, so exports, logic and integrations see
 * one language. The chosen language reaches the API as the `X-Form-Locale` header, which
 * only switches the language of the messages.
 */
class FormTranslations
{
    public const LANGUAGES = ['de', 'fr', 'it'];

    public static function enabledLanguages(Form $form): array
    {
        $languages = $form->translations['kscw']['languages'] ?? [];

        return array_values(array_intersect(self::LANGUAGES, is_array($languages) ? $languages : []));
    }

    /** Messages in the respondent's language when the form offers it. */
    public static function applyRequestLocale(Request $request, Form $form): void
    {
        $locale = strtolower((string) $request->header('X-Form-Locale'));
        if ($locale === 'en' || in_array($locale, self::enabledLanguages($form), true)) {
            app()->setLocale($locale);
        }
    }
}
