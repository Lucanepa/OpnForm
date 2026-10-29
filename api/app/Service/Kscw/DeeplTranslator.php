<?php

namespace App\Service\Kscw;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * KSCW fork: DeepL for form translations (FormTranslationController). English is always the
 * source. A key ending in ":fx" is a DeepL API Free key and goes to api-free.deepl.com.
 * Informal register where DeepL supports it — the club says "du".
 */
class DeeplTranslator
{
    /** DeepL accepts at most 50 texts per request. */
    private const BATCH = 50;

    public static function isConfigured(): bool
    {
        return filled(config('kscw.deepl.api_key'));
    }

    /**
     * @param  string[]  $texts
     * @return string[] same order and count as $texts
     */
    public function translate(array $texts, string $target, bool $html): array
    {
        $key = (string) config('kscw.deepl.api_key');
        $base = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $out = [];
        foreach (array_chunk(array_values($texts), self::BATCH) as $chunk) {
            $body = [
                'text' => $chunk,
                'source_lang' => 'EN',
                'target_lang' => strtoupper($target),
                'formality' => 'prefer_less',
                'preserve_formatting' => true,
            ];
            if ($html) {
                $body['tag_handling'] = 'html';
            }
            $response = Http::withHeaders(['Authorization' => 'DeepL-Auth-Key ' . $key])
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($base . '/v2/translate', $body)
                ->throw();
            foreach ($response->json('translations', []) as $translation) {
                $out[] = (string) ($translation['text'] ?? '');
            }
        }
        if (count($out) !== count($texts)) {
            throw new RuntimeException('DeepL returned ' . count($out) . ' texts for ' . count($texts));
        }

        return $out;
    }
}
