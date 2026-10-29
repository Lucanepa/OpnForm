<?php

namespace App\Http\Controllers\Kscw;

use App\Http\Controllers\Controller;
use App\Models\Forms\Form;
use App\Service\Kscw\DeeplTranslator;
use App\Service\Kscw\FormTranslations;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

/**
 * KSCW fork: "Translate with DeepL" in the form editor (POST /open/forms/{form}/kscw-translate).
 * Translates the texts the editor sends — its current, possibly unsaved state — and returns
 * them per language; the editor stores them in `translations.kscw` and the normal save
 * persists them. The server never needs to understand the form's structure.
 */
class FormTranslationController extends Controller
{
    private const MAX_CHARS = 200000;

    public function translate(Request $request, Form $form, DeeplTranslator $deepl)
    {
        $this->authorize('update', $form);

        $validated = $request->validate([
            'languages' => 'required|array|min:1',
            'languages.*' => ['string', Rule::in(FormTranslations::LANGUAGES)],
            'texts' => 'required|array|min:1|max:1000',
            'texts.*.key' => 'required|string|max:300',
            'texts.*.text' => 'required|string|max:20000',
            'texts.*.html' => 'sometimes|boolean',
        ]);

        if (!DeeplTranslator::isConfigured()) {
            return response()->json(['message' => 'DeepL is not configured on this server (DEEPL_API_KEY).'], 503);
        }
        $chars = array_sum(array_map(fn ($t) => mb_strlen($t['text']), $validated['texts']));
        if ($chars * count($validated['languages']) > self::MAX_CHARS) {
            return response()->json(['message' => 'Too much text for one translation run.'], 422);
        }

        $result = [];
        try {
            foreach (array_unique($validated['languages']) as $language) {
                $result[$language] = [];
                foreach ([false, true] as $html) {
                    $items = array_values(array_filter($validated['texts'], fn ($t) => (bool) ($t['html'] ?? false) === $html));
                    if ($items === []) {
                        continue;
                    }
                    $translated = $deepl->translate(array_column($items, 'text'), $language, $html);
                    foreach ($items as $i => $item) {
                        $result[$language][$item['key']] = $translated[$i];
                    }
                }
            }
        } catch (RequestException $e) {
            $status = $e->response->status();
            Log::warning('DeepL translation failed', ['status' => $status]);
            $message = match (true) {
                $status === 456 => 'The DeepL quota for this month is used up.',
                in_array($status, [401, 403], true) => 'DeepL refused the API key.',
                default => 'DeepL could not translate right now. Please try again.',
            };

            return response()->json(['message' => $message], 502);
        }

        return response()->json(['translations' => $result]);
    }
}
