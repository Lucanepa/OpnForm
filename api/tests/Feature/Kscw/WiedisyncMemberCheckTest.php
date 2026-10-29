<?php

use App\Listeners\Kscw\NotifyWiedisyncSignup;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// KSCW fork: "check in Wiedisync" on email fields (App\Service\Kscw\WiedisyncMemberLookup).

const WIEDISYNC = 'http://directus-test:8055';

function wiedisyncForm($test, array $emailOptions = [])
{
    $user = $test->actingAsUser();
    $workspace = $test->createUserWorkspace($user);
    $form = $test->createForm($user, $workspace);

    $name = collect($form->properties)->firstWhere('name', 'Name');
    $section = ['id' => 'section_' . Str::random(6), 'name' => 'Sektion', 'type' => 'select',
        'select' => ['options' => [['id' => 'v', 'name' => 'Volleyball'], ['id' => 'b', 'name' => 'Basketball']]]];
    $teams = ['id' => 'teams_' . Str::random(6), 'name' => 'Teams', 'type' => 'text'];

    $properties = collect($form->properties)->map(function ($p) use ($emailOptions, $name, $section, $teams) {
        if ($p['type'] !== 'email') {
            return $p;
        }

        return array_merge($p, [
            'wiedisync_lookup' => true,
            'wiedisync_name_field' => $name['id'],
            'wiedisync_section_field' => $section['id'],
            'wiedisync_teams_field' => $teams['id'],
        ], $emailOptions);
    })->push($section)->push($teams)->all();
    $form->properties = $properties;
    $form->save();

    return [$form, collect($properties)->firstWhere('type', 'email'), $name, $section, $teams];
}

function fakeWiedisync(array $lookup, int $status = 200): void
{
    Http::fake([
        WIEDISYNC . '/kscw/opnform/v1/member-lookup' => Http::response($lookup, $status),
        WIEDISYNC . '/kscw/opnform/v1/signup' => Http::response(['status' => 'found', 'events' => [7]]),
    ]);
}

beforeEach(function () {
    Config::set('kscw.wiedisync.url', WIEDISYNC);
    Config::set('kscw.wiedisync.service_token', 'secret-token');
    Cache::flush();
});

describe('submit', function () {
    it('accepts a member, writes section + teams from Wiedisync and signs them up', function () {
        fakeWiedisync(['status' => 'found', 'sections' => ['volleyball'], 'teams' => ['H1', 'H3']]);
        [$form, $email, $name, $section, $teams] = wiedisyncForm($this);

        $this->postJson(route('forms.answer', $form->slug), [
            $email['id'] => 'Anna@Example.com',
            $name['id'] => 'Anna Müller',
            $teams['id'] => 'typed by the respondent',
        ])->assertSuccessful();

        $data = $form->submissions()->latest()->first()->data;
        expect($data[$section['id']])->toBe('Volleyball'); // the select's own spelling
        expect($data[$teams['id']])->toBe('H1, H3');        // overwrites what was typed

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/member-lookup')
            && $r->header('X-KSCW-Service-Token') === ['secret-token']
            && $r['email'] === 'anna@example.com' && $r['name'] === 'Anna Müller');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/signup')
            && $r['form_slug'] === $form->slug && $r['email'] === 'anna@example.com');
        // rule + controller fill share one cached lookup
        Http::assertSentCount(2);
    });

    it('refuses an unknown email on the email field', function () {
        fakeWiedisync(['status' => 'not_found']);
        [$form, $email, $name] = wiedisyncForm($this);

        $this->postJson(route('forms.answer', $form->slug), [$email['id'] => 'nobody@example.com', $name['id'] => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$email['id'] => 'Wiedisync']);
        expect($form->submissions()->count())->toBe(0);
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/signup'));
    });

    it('asks for the name when a family shares the address, in the form language', function () {
        fakeWiedisync(['status' => 'ambiguous']);
        [$form, $email, $name] = wiedisyncForm($this);
        $form->update(['language' => 'de']);

        $this->postJson(route('forms.answer', $form->slug), [$email['id'] => 'family@example.com', $name['id'] => 'Müller'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$email['id'] => 'Namen']);
    });

    it('refuses rather than accepting unchecked when Wiedisync is down', function () {
        fakeWiedisync(['error' => 'boom'], 500);
        [$form, $email, $name] = wiedisyncForm($this);

        $this->postJson(route('forms.answer', $form->slug), [$email['id'] => 'anna@example.com', $name['id'] => 'Anna'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$email['id'] => 'try again']);
    });

    it('stays out of the way when the fork is not configured', function () {
        Config::set('kscw.wiedisync.url', null);
        Http::fake();
        [$form, $email, $name] = wiedisyncForm($this);

        $this->postJson(route('forms.answer', $form->slug), [$email['id'] => 'anyone@example.com', $name['id'] => 'A'])
            ->assertSuccessful();
        Http::assertNothingSent();
    });

    it('leaves email fields without the option alone', function () {
        Http::fake();
        $user = $this->actingAsUser();
        $form = $this->createForm($user, $this->createUserWorkspace($user));
        $email = collect($form->properties)->firstWhere('type', 'email');

        $this->postJson(route('forms.answer', $form->slug), [$email['id'] => 'anyone@example.com'])->assertSuccessful();
        Http::assertNothingSent();
    });
});

describe('live check', function () {
    it('answers found without revealing section and teams by default', function () {
        fakeWiedisync(['status' => 'found', 'sections' => ['Volleyball'], 'teams' => ['H1']]);
        [$form, $email] = wiedisyncForm($this);

        $this->postJson(route('forms.wiedisync-lookup', $form->slug), ['field_id' => $email['id'], 'email' => 'anna@example.com'])
            ->assertSuccessful()
            ->assertExactJson(['status' => 'found', 'message' => null]);
    });

    it('returns the values to fill when the field opted in', function () {
        fakeWiedisync(['status' => 'found', 'sections' => ['Basketball'], 'teams' => ['Herren 2 H3']]);
        [$form, $email, , $section, $teams] = wiedisyncForm($this, ['wiedisync_show_values' => true]);

        $this->postJson(route('forms.wiedisync-lookup', $form->slug), ['field_id' => $email['id'], 'email' => 'max@example.com'])
            ->assertSuccessful()
            ->assertJson(['status' => 'found', 'fill' => [$section['id'] => 'Basketball', $teams['id'] => 'Herren 2 H3']]);
    });

    it('gives the not-found message and skips malformed addresses', function () {
        fakeWiedisync(['status' => 'not_found']);
        [$form, $email] = wiedisyncForm($this);

        $this->postJson(route('forms.wiedisync-lookup', $form->slug), ['field_id' => $email['id'], 'email' => 'nobody@example.com'])
            ->assertJson(['status' => 'not_found'])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Wiedisync'));
        $this->postJson(route('forms.wiedisync-lookup', $form->slug), ['field_id' => $email['id'], 'email' => 'anna@'])
            ->assertJson(['status' => 'skipped']);
        Http::assertSentCount(1);
    });

    it('404s for a field without the option and refuses closed forms', function () {
        fakeWiedisync(['status' => 'found']);
        [$form, $email, $name] = wiedisyncForm($this);

        $this->postJson(route('forms.wiedisync-lookup', $form->slug), ['field_id' => $name['id'], 'email' => 'a@example.com'])
            ->assertNotFound();
        $form->update(['visibility' => 'draft']);
        $this->postJson(route('forms.wiedisync-lookup', $form->slug), ['field_id' => $email['id'], 'email' => 'a@example.com'])
            ->assertForbidden();
    });
});

describe('sign-up listener', function () {
    it('throws on a Wiedisync error so the queue retries', function () {
        Http::fake([WIEDISYNC . '/*' => Http::response(['error' => 'down'], 503)]);
        [$form, $email] = wiedisyncForm($this);

        $event = new \App\Events\Forms\FormSubmitted($form, [$email['id'] => 'anna@example.com', 'submission_id' => 5]);
        expect(fn () => (new NotifyWiedisyncSignup())->handle($event))->toThrow(\Illuminate\Http\Client\RequestException::class);
    });
});
