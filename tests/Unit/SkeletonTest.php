<?php

declare(strict_types=1);

// Placeholder of LG-01: proves that the test runner and the autoloader work. Remove it when the first
// real unit test lands.
it('loads the package through the Composer autoloader', function (): void {
    $installed = Composer\InstalledVersions::getRootPackage();

    expect($installed['name'])->toBe('organiseyou/llm-gateway');
});
