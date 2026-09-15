<?php

use App\Services\PasskeyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    // MailerSendService hits a real HTTP endpoint when MAILERSEND_API_KEY is
    // configured (it is, in this repo's .env, and phpunit.xml doesn't
    // override it) — fake it so forgot-passkey tests never make a real
    // network call or send a real email.
    Http::fake();

    // RateLimiter is backed by the "array" cache store in tests, which
    // lives for the whole PHP process (not reset by RefreshDatabase) — so a
    // failed-attempt count left over from one test would otherwise bleed
    // into the next. Every request in this file shares the same fake test
    // client IP (127.0.0.1), so clear both keyed limiters before every test.
    RateLimiter::clear('member-portal-login:127.0.0.1');
    RateLimiter::clear('member-portal-forgot:127.0.0.1');
});

function issuePasskeyFor($user): string
{
    return app(PasskeyService::class)->issueFor($user);
}

it('logs a subscriber in with a correct passkey and returns a usable token', function () {
    $user = subscriberActor();
    $passkey = issuePasskeyFor($user);

    $response = $this->postJson('/api/member/login', ['passkey' => $passkey]);

    $response->assertOk()->assertJsonStructure(['user', 'token']);

    $token = $response->json('token');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

it('rejects a passkey that is not exactly 6 digits', function () {
    $this->postJson('/api/member/login', ['passkey' => '12345'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['passkey']);

    $this->postJson('/api/member/login', ['passkey' => 'abcdef'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['passkey']);
});

it('rejects a well-formed but wrong passkey with a generic message', function () {
    $user = subscriberActor();
    $realPasskey = issuePasskeyFor($user);
    $wrongPasskey = $realPasskey === '111111' ? '222222' : '111111';

    $response = $this->postJson('/api/member/login', ['passkey' => $wrongPasskey]);

    $response->assertStatus(401)->assertJson(['message' => 'Invalid passkey.']);
});

it('rate limits login to 5 failed attempts per IP, then 429s even a correct passkey', function () {
    $user = subscriberActor();
    $realPasskey = issuePasskeyFor($user);
    $wrongPasskey = $realPasskey === '111111' ? '222222' : '111111';

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/member/login', ['passkey' => $wrongPasskey])
            ->assertStatus(401);
    }

    $this->postJson('/api/member/login', ['passkey' => $realPasskey])
        ->assertStatus(429);
});

it('always returns the same generic message for forgot-passkey, existing or not', function () {
    $user = subscriberActor();
    issuePasskeyFor($user);

    $existingResponse = $this->postJson('/api/member/forgot-passkey', ['email' => $user->email]);
    $missingResponse = $this->postJson('/api/member/forgot-passkey', ['email' => 'nobody-at-all@test.local']);

    $existingResponse->assertOk();
    $missingResponse->assertOk();
    expect($existingResponse->getContent())->toBe($missingResponse->getContent());
});

it('issues a new passkey and changes the hash for an already portal-enabled user', function () {
    $user = subscriberActor();
    issuePasskeyFor($user);
    $originalHash = $user->fresh()->passkey_hash;

    $this->postJson('/api/member/forgot-passkey', ['email' => $user->email])->assertOk();

    expect($user->fresh()->passkey_hash)->not->toBeNull();
    expect($user->fresh()->passkey_hash)->not->toBe($originalHash);
});

it('leaves passkey_hash null for a user who was never issued a passkey, while still returning the generic response', function () {
    $user = subscriberActor(); // no verified subscription, never issued a passkey
    expect($user->hasPortalAccess())->toBeFalse();

    $response = $this->postJson('/api/member/forgot-passkey', ['email' => $user->email]);

    $response->assertOk()->assertJson([
        'message' => 'If that email is registered and verified, a new passkey has been sent.',
    ]);
    expect($user->fresh()->passkey_hash)->toBeNull();
});

it('rate limits forgot-passkey to 3 attempts per 15 minutes per IP', function () {
    $user = subscriberActor();
    issuePasskeyFor($user);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/member/forgot-passkey', ['email' => $user->email])->assertOk();
    }

    $this->postJson('/api/member/forgot-passkey', ['email' => $user->email])->assertStatus(429);
});
