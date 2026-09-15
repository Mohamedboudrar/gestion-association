<?php

use App\Models\AssociationSetting;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The settings row is cached forever by SettingsService (array cache store
// in tests, which persists across tests within the same process). Force a
// fresh read against the just-refreshed (RefreshDatabase) schema before
// every test in this file so no test observes another test's cached row.
beforeEach(fn () => SettingsService::refresh());

function validSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'association_name' => 'Updated Association',
        'address' => '123 Main St',
        'phone' => '+212600000000',
        'email' => 'contact@updated.test',
        'annual_subscription_amount' => 250,
        'currency' => 'MAD',
    ], $overrides);
}

it('is reachable with no Authorization header at all', function () {
    $response = $this->getJson('/api/settings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'association_name', 'logo', 'logo_url', 'description',
                'address', 'phone', 'email', 'website', 'annual_subscription_amount', 'currency'],
        ]);
});

it('lets the president update the settings and persists the values', function () {
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->putJson('/api/settings', validSettingsPayload());

    $response->assertOk()
        ->assertJsonPath('data.association_name', 'Updated Association')
        ->assertJsonPath('data.email', 'contact@updated.test');

    // A whole-number float (250.0) JSON-encodes as the bare number `250`
    // (no fractional part) without JSON_PRESERVE_ZERO_FRACTION, which
    // decodes back as a PHP int — cast before comparing instead of relying
    // on assertJsonPath's strict type match.
    expect((float) $response->json('data.annual_subscription_amount'))->toBe(250.0);

    $followUp = $this->getJson('/api/settings');
    $followUp->assertOk()->assertJsonPath('data.association_name', 'Updated Association');

    expect(AssociationSetting::first()->association_name)->toBe('Updated Association');
});

it('forbids every non-president role from updating settings', function () {
    $roles = [
        'tresorier' => fn () => treasurerActor(),
        'vice-tresorier' => fn () => viceTreasurerActor(),
        'vice-president' => fn () => vicePresidentActor(),
        'secretaire-general' => fn () => secretaireGeneralActor(),
        'vice-secretaire-general' => fn () => viceSecretaireGeneralActor(),
        'conseiller' => fn () => conseillerActor(),
        'abonne' => fn () => subscriberActor(),
    ];

    foreach ($roles as $factory) {
        $user = $factory();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings', validSettingsPayload())
            ->assertStatus(403);
    }
});

it('rejects an update missing required fields with a 422', function () {
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->putJson('/api/settings', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['association_name', 'address', 'phone', 'email', 'annual_subscription_amount', 'currency']);
});

it('rejects an invalid email and negative subscription amount with a 422', function () {
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->putJson('/api/settings', validSettingsPayload([
        'email' => 'not-an-email',
        'annual_subscription_amount' => -10,
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['email', 'annual_subscription_amount']);
});

it('uploads a new logo and stores it on the public disk', function () {
    Storage::fake('public');
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->post('/api/settings', array_merge(
        validSettingsPayload(),
        ['logo' => UploadedFile::fake()->image('logo.png'), '_method' => 'PUT']
    ));

    $response->assertOk();
    $logoPath = $response->json('data.logo');

    expect($logoPath)->not->toBeNull();
    Storage::disk('public')->assertExists($logoPath);
    expect($response->json('data.logo_url'))->toContain($logoPath);
});

it('deletes the old logo file when a new one replaces it', function () {
    Storage::fake('public');
    Storage::disk('public')->put('association/old-logo.png', 'fake-old-logo-contents');

    AssociationSetting::query()->delete();
    AssociationSetting::factory()->create(['association_logo' => 'association/old-logo.png']);
    SettingsService::refresh();

    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->post('/api/settings', array_merge(
        validSettingsPayload(),
        ['logo' => UploadedFile::fake()->image('new-logo.png'), '_method' => 'PUT']
    ));

    $response->assertOk();
    $newLogoPath = $response->json('data.logo');

    expect($newLogoPath)->not->toBe('association/old-logo.png');
    Storage::disk('public')->assertMissing('association/old-logo.png');
    Storage::disk('public')->assertExists($newLogoPath);
});

it('rejects an update with an invalid logo file type', function () {
    Storage::fake('public');
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->post('/api/settings', array_merge(
        validSettingsPayload(),
        ['logo' => UploadedFile::fake()->create('not-an-image.pdf', 10), '_method' => 'PUT']
    ));

    $response->assertStatus(422)->assertJsonValidationErrors(['logo']);
});
