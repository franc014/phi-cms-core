<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('does not reference the legacy content blocks config key', function () {
    $source = file_get_contents(__DIR__ . '/../src/PhiCmsCorePlugin.php');

    expect($source)->not->toContain("config('phi-cms-core.content_blocks')")
        ->and($source)->not->toContain('config("phi-cms-core.content_blocks")')
        ->and($source)->not->toContain("config('phi-cms-core.content_blocks',");
});

it('uses the schema registry in register content blocks', function () {
    $source = file_get_contents(__DIR__ . '/../src/PhiCmsCorePlugin.php');

    expect($source)->toContain(SchemaRegistry::class)
        ->and($source)->toContain('->all()');
});
