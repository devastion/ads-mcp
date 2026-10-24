<?php

use App\Enums\Niche;

it('resolves a niche from its slug or display name in any case', function (string $case, string $input) {
    expect(Niche::fromSlugOrName($input))->toBe(constant(Niche::class.'::'.$case));
})->with([
    ['Skincare', 'skincare'], ['Skincare', 'Skincare'], ['Skincare', 'SKINCARE'],
    ['Haircare', 'haircare'], ['Haircare', 'HAIRCARE'],
    ['Supplements', 'supplements'], ['Supplements', 'SUPPLEMENTS'],
    ['Fitness', 'fitness'], ['Fitness', 'FITNESS'],
    ['PetSupplies', 'pet-supplies'], ['PetSupplies', 'Pet Supplies'], ['PetSupplies', 'PET SUPPLIES'],
    ['HomeDecor', 'home-decor'], ['HomeDecor', 'Home Decor'], ['HomeDecor', 'HOME DECOR'],
    ['Kitchen', 'kitchen'], ['Kitchen', 'KITCHEN'],
    ['Apparel', 'apparel'], ['Apparel', 'APPAREL'],
    ['Jewelry', 'jewelry'], ['Jewelry', 'JEWELRY'],
    ['BabyKids', 'baby-kids'], ['BabyKids', 'Baby & Kids'], ['BabyKids', 'BABY & KIDS'],
    ['Outdoor', 'outdoor'], ['Outdoor', 'OUTDOOR'],
    ['TechAccessories', 'tech-accessories'], ['TechAccessories', 'Tech Accessories'], ['TechAccessories', 'TECH ACCESSORIES'],
]);

it('returns null for an unknown or partial niche', function (string $input) {
    expect(Niche::fromSlugOrName($input))->toBeNull();
})->with(['nope', 'pet', 'pet supplies ', 'pet_supplies', '']);

it('exposes the display names and keywords from the spec', function () {
    expect(collect(Niche::cases())->mapWithKeys(fn (Niche $n) => [$n->value => $n->displayName()])->all())->toBe([
        'skincare' => 'Skincare',
        'haircare' => 'Haircare',
        'supplements' => 'Supplements',
        'fitness' => 'Fitness',
        'pet-supplies' => 'Pet Supplies',
        'home-decor' => 'Home Decor',
        'kitchen' => 'Kitchen',
        'apparel' => 'Apparel',
        'jewelry' => 'Jewelry',
        'baby-kids' => 'Baby & Kids',
        'outdoor' => 'Outdoor',
        'tech-accessories' => 'Tech Accessories',
    ]);
    expect(collect(Niche::cases())->mapWithKeys(fn (Niche $n) => [$n->value => $n->keywords()])->all())->toBe([
        'skincare' => ['serum', 'glow', 'SPF'],
        'haircare' => ['shampoo', 'curls'],
        'supplements' => ['protein', 'gummies'],
        'fitness' => ['leggings', 'dumbbell'],
        'pet-supplies' => ['collar', 'kibble'],
        'home-decor' => ['sofa', 'candle'],
        'kitchen' => ['blender', 'knife'],
        'apparel' => ['hoodie', 'denim'],
        'jewelry' => ['necklace', 'ring'],
        'baby-kids' => ['stroller', 'onesie'],
        'outdoor' => ['tent', 'backpack'],
        'tech-accessories' => ['charger', 'phone case'],
    ]);
});
