<?php

use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Jobs\DeleteDirectory;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Repositories\DirectoryRepository;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->disk = Directory::getAssetDisk();
    Storage::fake($this->disk);
    Storage::disk($this->disk)->makeDirectory('assets/Root');
});

it('refuses "." and ".." as a new directory name', function (string $name) {
    $this->postJson(route('admin.dam.directory.store'), ['name' => $name, 'parent_id' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => trans('dam::app.admin.dam.index.directory.name-reserved')]);

    expect(Directory::where('name', $name)->exists())->toBeFalse();
})->with(['.', '..']);

it('refuses renaming a directory to "." or ".."', function (string $name) {
    $directory = Directory::factory()->create(['name' => 'Rename Me', 'parent_id' => 1]);

    $this->postJson(route('admin.dam.directory.update'), ['id' => $directory->id, 'name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($directory->fresh()->name)->toBe('Rename Me');
})->with(['.', '..']);

it('still accepts names that only contain dots alongside other characters', function (string $name) {
    $this->postJson(route('admin.dam.directory.store'), ['name' => $name, 'parent_id' => 1])
        ->assertOk();
})->with(['...', '.hidden', 'v1..2']);

it('does not wipe the parent storage when deleting a legacy dot-named directory', function (string $name) {
    $legacy = Directory::factory()->create(['name' => $name, 'parent_id' => 1]);

    Storage::disk($this->disk)->put('assets/Root/Sibling/keep.png', 'x');
    Storage::disk($this->disk)->put('assets/keep-top.png', 'x');

    (new DeleteDirectory($legacy->id, auth()->id()))->deleteDirectoryAndChildren($legacy->id, app(DirectoryRepository::class));

    expect(Directory::find($legacy->id))->toBeNull();
    Storage::disk($this->disk)->assertExists('assets/Root/Sibling/keep.png');
    Storage::disk($this->disk)->assertExists('assets/keep-top.png');
})->with(['.', '..']);

it('does not wipe the parent storage when the repository deletes a legacy dot-named directory', function (string $name) {
    $legacy = Directory::factory()->create(['name' => $name, 'parent_id' => 1]);

    Storage::disk($this->disk)->put('assets/Root/Sibling/keep.png', 'x');
    Storage::disk($this->disk)->put('assets/keep-top.png', 'x');

    app(DirectoryRepository::class)->delete($legacy->id);

    Storage::disk($this->disk)->assertExists('assets/Root/Sibling/keep.png');
    Storage::disk($this->disk)->assertExists('assets/keep-top.png');
})->with(['.', '..']);

it('does not move the parent storage when renaming a legacy dot-named directory', function (string $name) {
    $legacy = Directory::factory()->create(['name' => $name, 'parent_id' => 1]);

    Storage::disk($this->disk)->put('assets/Root/Sibling/keep.png', 'x');

    app(DirectoryRepository::class)->createDirectoryWithStorage('Root/Renamed', 'Root/'.$name);

    Storage::disk($this->disk)->assertExists('assets/Root/Sibling/keep.png');
    Storage::disk($this->disk)->assertExists('assets/Root/Renamed');
})->with(['.', '..']);

it('moves only its own asset files when a legacy ".." directory is renamed', function () {
    $legacy = Directory::factory()->create(['name' => '..', 'parent_id' => 1]);
    $asset = Asset::factory()->create(['path' => 'assets/Root/../legacy.png', 'file_name' => 'legacy.png']);
    $legacy->assets()->attach($asset->id);

    Storage::disk($this->disk)->put('assets/legacy.png', 'img');
    Storage::disk($this->disk)->put('assets/Root/Sibling/keep.png', 'x');

    app(DirectoryRepository::class)->update(['name' => 'Fixed'], $legacy->id);

    expect($asset->fresh()->path)->toBe('assets/Root/Fixed/legacy.png');
    Storage::disk($this->disk)->assertExists('assets/Root/Fixed/legacy.png');
    Storage::disk($this->disk)->assertExists('assets/Root/Sibling/keep.png');
});
