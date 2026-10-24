<?php

use App\Actions\GenerateAdCopy;
use App\Enums\Niche;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('generates the same copy for the same random seed', function () {
    $first = new GenerateAdCopy()->handle(new Randomizer(new Mt19937(42)), Niche::Skincare);
    $second = new GenerateAdCopy()->handle(new Randomizer(new Mt19937(42)), Niche::Skincare);

    expect($second)->toBe($first);
});

it('uses every niche keyword in some headline', function (string $slug) {
    $niche = Niche::from($slug);
    $random = new Randomizer(new Mt19937(1));
    $headlines = collect(range(1, 200))
        ->map(fn () => mb_strtolower(new GenerateAdCopy()->handle($random, $niche)['headline']));

    foreach ($niche->keywords() as $keyword) {
        expect($headlines->contains(fn (string $headline) => str_contains($headline, mb_strtolower($keyword))))
            ->toBeTrue("keyword [{$keyword}] never appears in a {$slug} headline");
    }
})->with(array_map(fn (Niche $niche) => [$niche->value], Niche::cases()));

it('keeps headlines within 120 characters', function () {
    $random = new Randomizer(new Mt19937(3));

    foreach (Niche::cases() as $niche) {
        for ($i = 0; $i < 50; $i++) {
            expect(mb_strlen(new GenerateAdCopy()->handle($random, $niche)['headline']))->toBeLessThanOrEqual(120);
        }
    }
});
