<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

// KSCW fork: multilingual forms — "Translate with DeepL" + the respondent's message language.

function fakeDeepl(): void
{
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => function (HttpRequest $r) {
            return Http::response(['translations' => array_map(
                fn ($t) => ['text' => strtoupper($r['target_lang']) . ':' . $t],
                $r['text']
            )]);
        },
    ]);
}

beforeEach(function () {
    Config::set('kscw.deepl.api_key', 'abc-123:fx');
});

describe('translate endpoint', function () {
    it('translates plain and HTML texts separately, per language', function () {
        fakeDeepl();
        $user = $this->actingAsUser();
        $form = $this->createForm($user, $this->createUserWorkspace($user));

        $this->postJson(route('open.forms.kscw-translate', $form->id), [
            'languages' => ['de', 'fr'],
            'texts' => [
                ['key' => 'form.title', 'text' => 'Referee course'],
                ['key' => 'form.description', 'text' => '<p>Hi</p>', 'html' => true],
            ],
        ])->assertSuccessful()->assertExactJson(['translations' => [
            'de' => ['form.title' => 'DE:Referee course', 'form.description' => 'DE:<p>Hi</p>'],
            'fr' => ['form.title' => 'FR:Referee course', 'form.description' => 'FR:<p>Hi</p>'],
        ]]);

        Http::assertSent(fn (HttpRequest $r) => ($r->data()['tag_handling'] ?? null) === 'html' && $r['target_lang'] === 'DE'
            && $r['source_lang'] === 'EN' && $r['formality'] === 'prefer_less'
            && $r->header('Authorization') === ['DeepL-Auth-Key abc-123:fx']);
        Http::assertSentCount(4);
    });

    it('is for editors of the form only', function () {
        fakeDeepl();
        $owner = $this->actingAsUser();
        $form = $this->createForm($owner, $this->createUserWorkspace($owner));
        $this->actingAsGuest();
        $this->actingAsUser(); // someone else

        $this->postJson(route('open.forms.kscw-translate', $form->id), [
            'languages' => ['de'], 'texts' => [['key' => 'form.title', 'text' => 'x']],
        ])->assertForbidden();
        Http::assertNothingSent();
    });

    it('rejects unknown languages and explains a missing key or used-up quota', function () {
        $user = $this->actingAsUser();
        $form = $this->createForm($user, $this->createUserWorkspace($user));
        $body = ['languages' => ['de'], 'texts' => [['key' => 'form.title', 'text' => 'x']]];

        $this->postJson(route('open.forms.kscw-translate', $form->id), ['languages' => ['es']] + $body)
            ->assertStatus(422);

        Http::fake(['https://api-free.deepl.com/*' => Http::response(['message' => 'Quota exceeded'], 456)]);
        $this->postJson(route('open.forms.kscw-translate', $form->id), $body)
            ->assertStatus(502)->assertJson(['message' => 'The DeepL quota for this month is used up.']);

        Config::set('kscw.deepl.api_key', null);
        $this->postJson(route('open.forms.kscw-translate', $form->id), $body)->assertStatus(503);
    });
});

describe('respondent language', function () {
    it('answers validation messages in the language picked on the form', function () {
        Config::set('kscw.wiedisync.url', 'http://directus-test:8055');
        Config::set('kscw.wiedisync.service_token', 't');
        Cache::flush();
        Http::fake(['http://directus-test:8055/*' => Http::response(['status' => 'not_found'])]);

        $user = $this->actingAsUser();
        $form = $this->createForm($user, $this->createUserWorkspace($user));
        $email = collect($form->properties)->firstWhere('type', 'email');
        $form->properties = collect($form->properties)
            ->map(fn ($p) => $p['id'] === $email['id'] ? $p + ['wiedisync_lookup' => true] : $p)->all();
        $form->translations = ['kscw' => ['languages' => ['de'], 'texts' => []]];
        $form->save();

        $this->postJson(route('forms.answer', $form->slug), [$email['id'] => 'x@example.com'], ['X-Form-Locale' => 'de'])
            ->assertStatus(422)->assertJsonValidationErrors([$email['id'] => 'nicht bekannt']);
        // a language the form does not offer → the form's own language (en)
        $this->postJson(route('forms.answer', $form->slug), [$email['id'] => 'x@example.com'], ['X-Form-Locale' => 'fr'])
            ->assertStatus(422)->assertJsonValidationErrors([$email['id'] => 'couldn']);
    });
});
