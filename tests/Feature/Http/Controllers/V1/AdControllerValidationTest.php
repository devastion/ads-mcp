<?php

it('returns 422 for an unknown parameter with the nearest suggestion', function () {
    v1Search('nich=skincare')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_parameter')
        ->assertJsonPath('error.message', 'Unknown query parameter "nich".')
        ->assertJsonPath('error.details.parameter', 'nich')
        ->assertJsonPath('error.details.did_you_mean', 'niche')
        ->assertJsonPath('error.next_step', 'Use "niche" instead.')
        ->assertJsonPath('error.retryable', false)
        ->assertJsonMissingPath('error.details.errors');
});

it('returns 422 without a suggestion when no parameter is close', function () {
    v1Search('zzzzzz=1')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_parameter')
        ->assertJsonMissingPath('error.details.did_you_mean')
        ->assertJsonPath('error.next_step', 'Remove it. Allowed parameters: q, niche, format, status, started_after, sort, per_page, cursor.');
});

it('returns 422 for empty or whitespace-only raw values', function (string $query) {
    v1Search($query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'q')
        ->assertJsonPath('error.next_step', 'Omit the parameter instead of sending it empty.');
})->with(['q=', 'q', 'q=%20%20', 'q=++', 'q=%09%0A']);

it('returns 422 for repeated plain scalar parameters', function (string $query, string $parameter) {
    v1Search($query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', $parameter)
        ->assertJsonPath('error.next_step', 'Send the parameter once.');
})->with([
    ['format=video&format=image', 'format'],
    ['q=serum&q=serum', 'q'],
    ['for%6Dat=video&format=image', 'format'],
]);

it('returns 422 for bracket syntax instead of accepting arrays', function (string $query, string $parameter) {
    v1Search($query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', $parameter)
        ->assertJsonPath('error.next_step', 'Send the parameter without array syntax.');
})->with([
    ['niche[]=skincare', 'niche'],
    ['format%5B0%5D=video', 'format'],
    ['q[nested][key]=serum', 'q'],
    ['format=video&format[]=image', 'format'],
    ['format[]=image&format=video', 'format'],
]);

it('returns 422 with all issues in first-encounter order', function () {
    v1Search('nich=x&format=video&q=%20&format=image')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_parameter')
        ->assertJsonPath('error.details.parameter', 'nich')
        ->assertJsonCount(3, 'error.details.errors')
        ->assertJsonPath('error.details.errors.0.code', 'unknown_parameter')
        ->assertJsonPath('error.details.errors.0.details.parameter', 'nich')
        ->assertJsonPath('error.details.errors.0.details.did_you_mean', 'niche')
        ->assertJsonPath('error.details.errors.1.code', 'invalid_parameter')
        ->assertJsonPath('error.details.errors.1.details.parameter', 'format')
        ->assertJsonPath('error.details.errors.1.next_step', 'Send the parameter once.')
        ->assertJsonPath('error.details.errors.2.code', 'invalid_parameter')
        ->assertJsonPath('error.details.errors.2.details.parameter', 'q');
});

it('returns 422 with the first invalid parameter at the top level', function () {
    v1Search('q=&zzzzzz=1')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'q')
        ->assertJsonCount(2, 'error.details.errors')
        ->assertJsonPath('error.details.errors.1.code', 'unknown_parameter');
});

it('returns 422 for literal unknown names instead of PHP-normalizing them', function (string $query, string $parameter) {
    v1Search($query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_parameter')
        ->assertJsonPath('error.details.parameter', $parameter);
})->with([
    ['per.page=20', 'per.page'],
    ['per+page=20', 'per page'],
]);

it('recognizes every approved parameter before filter rules are implemented', function () {
    v1Search('q=serum&niche=skincare&format=video&status=active&started_after=2026-08-01&sort=newest&per_page=20&cursor=opaque')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('returns the empty scaffold response without parameters', function () {
    v1Search()->assertOk()->assertExactJson(['data' => []]);
});
