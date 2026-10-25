<?php

it('runs against the testing environment and the isolated test database', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.connections.pgsql.database'))->toBe('ads_testing')
        ->and(config('cache.default'))->toBe('array');
});
