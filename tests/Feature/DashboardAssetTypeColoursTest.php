<?php

use Webkul\DAM\Models\Asset;

beforeEach(function () {
    $this->damResetAssetTables();
    $this->loginAsAdmin();
});

it('colours every asset type bar and recent-upload dot with a theme colour variable', function () {
    foreach (['image', 'video', 'audio', 'document'] as $type) {
        Asset::factory()->create(['file_type' => $type, 'file_name' => "dash-$type.bin", 'path' => "assets/Root/dash-$type.bin"]);
    }

    $html = view('dam::dashboard.stats')->render();

    foreach ([600, 500, 400, 300] as $shade) {
        expect($html)->toContain("background-color: rgb(var(--c-primary-$shade))");
    }

    expect(substr_count($html, 'background-color: rgb(var(--c-primary-300))'))->toBe(2)
        ->and($html)->not->toContain('bg-primary-300');
});
