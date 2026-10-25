<?php

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

function v1Ad(int|string $id, string $query = ''): TestResponse
{
    return test()->getJson('/api/v1/ads/'.$id.($query === '' ? '' : '?'.$query), ['Authorization' => 'Bearer '.v1Key()]);
}

it('returns the detail fields and body page metadata', function () {
    $ad = Ad::factory()->create([
        'brand_id' => 7,
        'brand_name' => 'Acme',
        'headline' => 'Dog gear',
        'body_text' => 'Short body',
        'niche' => Niche::PetSupplies,
        'format' => AdFormat::Video,
        'status' => AdStatus::Active,
        'start_date' => '2026-08-01',
        'end_date' => null,
        'impression_rank' => 3,
        'countries' => ['US', 'CA'],
        'discovered_at' => '2026-08-02 10:30:00',
    ]);

    $response = v1Ad($ad->id)->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'body_offset', 'body_has_more', 'body_next_offset', 'body_total_characters'])
        ->and(array_keys($response->json('data')))->toBe([
            'id', 'brand_id', 'brand', 'headline', 'body_text', 'niche', 'format', 'status',
            'start_date', 'end_date', 'days_active', 'impression_rank', 'countries', 'discovered_at',
        ])
        ->and($response->json('data'))->toMatchArray([
            'id' => $ad->id,
            'brand_id' => 7,
            'brand' => 'Acme',
            'headline' => 'Dog gear',
            'body_text' => 'Short body',
            'niche' => 'pet-supplies',
            'format' => 'video',
            'status' => 'active',
            'start_date' => '2026-08-01',
            'end_date' => null,
            'days_active' => 85,
            'impression_rank' => 3,
            'countries' => ['CA', 'US'],
        ])
        ->and($response->json('data.discovered_at'))->toStartWith('2026-08-02T10:30:00')
        ->and($response->json())->toMatchArray([
            'body_offset' => 0,
            'body_has_more' => false,
            'body_next_offset' => null,
            'body_total_characters' => 10,
        ]);
});

it('counts days_active to the end date for inactive ads and keeps a null rank', function () {
    $ad = Ad::factory()->withoutImpressions()->create([
        'status' => AdStatus::Inactive,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    v1Ad($ad->id)->assertOk()
        ->assertJsonPath('data.days_active', 30)
        ->assertJsonPath('data.end_date', '2026-08-31')
        ->assertJsonPath('data.impression_rank', null);
});

it('returns countries sorted, unique and upper-case', function () {
    $ad = Ad::factory()->create(['countries' => ['us', 'GB', 'US', 'ca']]);

    v1Ad($ad->id)->assertOk()->assertJsonPath('data.countries', ['CA', 'GB', 'US']);
});

it('pages the body by characters, not bytes', function () {
    $ad = Ad::factory()->create(['body_text' => 'aé😀bc😀d']);

    $first = v1Ad($ad->id, 'body_limit=3')->assertOk();
    expect($first->json('data.body_text'))->toBe('aé😀')
        ->and($first->json())->toMatchArray([
            'body_offset' => 0,
            'body_has_more' => true,
            'body_next_offset' => 3,
            'body_total_characters' => 7,
        ]);

    $second = v1Ad($ad->id, 'body_offset=3&body_limit=3')->assertOk();
    expect($second->json('data.body_text'))->toBe('bc😀')
        ->and($second->json('body_next_offset'))->toBe(6);

    $last = v1Ad($ad->id, 'body_offset=6&body_limit=3')->assertOk();
    expect($last->json('data.body_text'))->toBe('d')
        ->and($last->json('body_has_more'))->toBeFalse()
        ->and($last->json('body_next_offset'))->toBeNull();
});

it('reconstructs a body longer than the default page exactly', function () {
    $body = str_repeat('Zażółć 😀 gęślą jaźń. ', 120);
    $ad = Ad::factory()->create(['body_text' => $body]);

    $collected = '';
    $offset = 0;

    do {
        $response = v1Ad($ad->id, 'body_offset='.$offset)->assertOk();
        $collected .= $response->json('data.body_text');
        $offset = $response->json('body_next_offset');
    } while ($response->json('body_has_more') === true);

    expect(mb_strlen($body))->toBeGreaterThan(1000)
        ->and($collected)->toBe($body);
});

it('returns an empty page without continuation at or past the end', function () {
    $ad = Ad::factory()->create(['body_text' => 'abc']);

    foreach (['body_offset=3', 'body_offset=50'] as $query) {
        v1Ad($ad->id, $query)->assertOk()
            ->assertJsonPath('data.body_text', '')
            ->assertJsonPath('body_has_more', false)
            ->assertJsonPath('body_next_offset', null)
            ->assertJsonPath('body_total_characters', 3);
    }
});

it('handles an empty body', function () {
    $ad = Ad::factory()->create(['body_text' => '']);

    v1Ad($ad->id)->assertOk()
        ->assertJsonPath('data.body_text', '')
        ->assertJsonPath('body_has_more', false)
        ->assertJsonPath('body_total_characters', 0);
});

it('keeps a maximum page of control characters and long fields inside the 14 KiB budget', function () {
    $ad = Ad::factory()->create([
        'brand_name' => str_repeat('"', 255),
        'headline' => str_repeat('\\', 255),
        'body_text' => str_repeat("\x01", 1500),
        'countries' => array_map(fn (int $i): string => chr(65 + intdiv($i, 26)).chr(65 + $i % 26), range(0, 249)),
    ]);

    $content = (string) v1Ad($ad->id)->assertOk()->getContent();

    expect(strlen($content))->toBeLessThanOrEqual(14 * 1024)
        ->and(json_decode($content, true)['data']['brand'])->toBe(str_repeat('"', 255));
});

it('returns not_found for an ad that does not exist', function () {
    v1Ad(999999)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('rejects ids that are not positive integers', function (string $id) {
    v1Ad($id)->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'id');
})->with(['abc', '0', '-4', '1.5', '99999999999999999999']);

it('rejects bad body_offset and body_limit instead of clamping', function (string $query, string $parameter) {
    $ad = Ad::factory()->create();

    v1Ad($ad->id, $query)->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', $parameter);
})->with([
    ['body_offset=-1', 'body_offset'],
    ['body_offset=abc', 'body_offset'],
    ['body_limit=0', 'body_limit'],
    ['body_limit=1001', 'body_limit'],
    ['body_limit=x', 'body_limit'],
]);

it('rejects unknown and repeated parameters', function () {
    $ad = Ad::factory()->create();

    v1Ad($ad->id, 'body_ofset=2')->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_parameter')
        ->assertJsonPath('error.details.did_you_mean', 'body_offset');

    v1Ad($ad->id, 'body_limit=5&body_limit=6')->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter');
});

it('requires a Bearer key', function () {
    $ad = Ad::factory()->create();

    $this->getJson('/api/v1/ads/'.$ad->id)->assertStatus(401)->assertJsonPath('error.code', 'api_key_missing');
});
