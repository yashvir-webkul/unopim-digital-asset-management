<?php

use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function createRenameAsset(string $path): Asset
{
    Storage::disk(Directory::getAssetDisk())->put($path, 'dummy content');

    return Asset::factory()->create([
        'file_name' => basename($path),
        'path'      => $path,
    ]);
}

it('refuses a rename whose full path is longer than 255 characters and keeps the file in place', function () {
    $disk = Directory::getAssetDisk();
    $asset = createRenameAsset('assets/Root/long-'.uniqid().'.png');
    $newName = str_repeat('a', 240).'.png';

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'file_name' => $newName,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.index.directory.asset-name-too-long', ['max' => 255]))
        ->assertJsonPath('errors.file_name.0', trans('dam::app.admin.dam.index.directory.asset-name-too-long', ['max' => 255]));

    Storage::disk($disk)->assertExists($asset->path);
    Storage::disk($disk)->assertMissing('assets/Root/'.$newName);

    expect($asset->fresh()->only(['file_name', 'path']))->toBe($asset->only(['file_name', 'path']));
});

it('accepts a rename whose full path is exactly 255 characters', function () {
    $disk = Directory::getAssetDisk();
    $asset = createRenameAsset('assets/Root/exact-'.uniqid().'.png');
    $newName = str_repeat('b', 255 - strlen('assets/Root/') - 4).'.png';

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'file_name' => $newName,
    ])->assertOk();

    Storage::disk($disk)->assertExists('assets/Root/'.$newName);
    expect($asset->fresh()->path)->toBe('assets/Root/'.$newName);
});

it('moves the file back when the database update fails after the rename', function () {
    $disk = Directory::getAssetDisk();
    $asset = createRenameAsset('assets/Root/rollback-'.uniqid().'.png');
    $oldPath = $asset->path;

    Asset::updating(fn () => throw new RuntimeException('db down'));

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'file_name' => 'rollback-new.png',
    ])->assertStatus(500);

    Storage::disk($disk)->assertExists($oldPath);
    Storage::disk($disk)->assertMissing('assets/Root/rollback-new.png');
    expect($asset->fresh()->path)->toBe($oldPath);
});

it('only replaces the file name, not a folder with the same text, when renaming', function () {
    $disk = Directory::getAssetDisk();
    $asset = createRenameAsset('assets/logo.png-files/logo.png');

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'file_name' => 'brand.png',
    ])->assertOk();

    expect($asset->fresh()->path)->toBe('assets/logo.png-files/brand.png');
    Storage::disk($disk)->assertExists('assets/logo.png-files/brand.png');
});
