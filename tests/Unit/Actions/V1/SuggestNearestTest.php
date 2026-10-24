<?php

use App\Actions\V1\SuggestNearest;

it('suggests the nearest allowed value within its length threshold', function (string $value, array $allowed, ?string $expected) {
    expect((new SuggestNearest)->handle($value, $allowed))->toBe($expected);
})->with([
    'space and case' => ['Pet Supplies', ['skincare', 'haircare', 'pet-supplies'], 'pet-supplies'],
    'underscore' => ['pet_supplies', ['pet-supplies'], 'pet-supplies'],
    'capitalized' => ['Skincare', ['skincare'], 'skincare'],
    'one edit' => ['skincar', ['skincare'], 'skincare'],
    'no close value' => ['quantum', ['skincare', 'haircare'], null],
    'exact wins over earlier near value' => ['cats', ['bats', 'cats'], 'cats'],
    'nearest wins over earlier eligible value' => ['abcde', ['abxxe', 'abcdf'], 'abcdf'],
    'tie follows allowed order' => ['cat', ['bat', 'car'], 'bat'],
    'tie follows reversed allowed order' => ['cat', ['car', 'bat'], 'car'],
    'five character candidate permits two deletions' => ['abc', ['abcde'], 'abcde'],
    'short candidate rejects two edits despite long input' => ['abcdef', ['abcd'], null],
    'short candidate permits one edit' => ['abce', ['abcd'], 'abcd'],
    'five character candidate rejects three edits' => ['ab', ['abcde'], null],
    'empty allowed list' => ['anything', [], null],
]);
