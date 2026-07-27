<?php

declare(strict_types=1);

// Anchors the Pest "Unit" test suite declared in phpunit.xml so the bare
// `./vendor/bin/pest` gate resolves and passes. Real unit tests land here as
// domain logic (posting engine, permission resolver, etc.) arrives.
test('the unit test suite is wired', function (): void {
    expect(true)->toBeTrue();
});
