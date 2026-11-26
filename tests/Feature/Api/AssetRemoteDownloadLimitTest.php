<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Http\Controllers\API\Asset\AssetController;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;

class RemoteDownloadLimitedAssetController extends AssetController
{
    public static int $maxBytes = 1024;

    protected function remoteDownloadMaxBytes(): int
    {
        return self::$maxBytes;
    }
}

function damTempFilesSnapshot(): array
{
    return array_values(array_filter(
        scandir(sys_get_temp_dir()) ?: [],
        fn (string $name) => str_starts_with($name, 'dam_api_url_') || str_contains($name, 'remote-limit-')
    ));
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    RemoteDownloadLimitedAssetController::$maxBytes = 1024;
    app()->bind(AssetController::class, RemoteDownloadLimitedAssetController::class);
    $this->tempBefore = damTempFilesSnapshot();
});

afterEach(function () {
    expect(array_diff(damTempFilesSnapshot(), $this->tempBefore))->toBeEmpty();
});

it('refuses an api upload by url bigger than the max upload size and creates no asset', function () {
    Http::fake([
        '93.184.216.34/*' => Http::response(str_repeat('a', 4096), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/RemoteLimit');
    $directory = Directory::factory()->create(['name' => 'RemoteLimit', 'parent_id' => null]);
    $assetsBefore = Asset::count();

    $this->withHeaders($this->getAuthenticationHeaders())
        ->post(route('admin.api.dam.assets.upload'), [
            'files'        => ['http://93.184.216.34/remote-limit-big.jpg'],
            'directory_id' => $directory->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Asset::count())->toBe($assetsBefore);
});

it('refuses a remote file whose streamed body passes the limit', function () {
    Http::fake([
        '93.184.216.34/*' => Http::response(str_repeat('a', 4096), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $result = app(AssetController::class)->downloadAndConvertFiles(
        new Request(['files' => ['http://93.184.216.34/remote-limit-stream.jpg']])
    );

    expect($result)->toBeInstanceOf(JsonResponse::class)
        ->and($result->getStatusCode())->toBe(422)
        ->and($result->getData(true)['files_processed'])->toBe(0);
});

it('refuses a remote file whose content-length header passes the limit', function () {
    Http::fake([
        '93.184.216.34/*' => Http::response('small', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '999999999']),
    ]);

    $result = app(AssetController::class)->downloadAndConvertFiles(
        new Request(['files' => ['http://93.184.216.34/remote-limit-header.jpg']])
    );

    expect($result)->toBeInstanceOf(JsonResponse::class)
        ->and($result->getStatusCode())->toBe(422);
});

it('accepts a remote file exactly at the limit', function () {
    RemoteDownloadLimitedAssetController::$maxBytes = 4096;

    Http::fake([
        '93.184.216.34/*' => Http::response(str_repeat('a', 4096), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $files = app(AssetController::class)->downloadAndConvertFiles(
        new Request(['files' => ['http://93.184.216.34/remote-limit-exact.jpg']])
    );

    expect($files)->toBeArray()->toHaveCount(1)
        ->and($files[0]->getSize())->toBe(4096);

    @unlink($files[0]->getPathname());
});

it('removes the temp file when the remote download fails or is empty', function (int $status, string $body) {
    Http::fake([
        '93.184.216.34/*' => Http::response($body, $status, ['Content-Type' => 'image/jpeg']),
    ]);

    $result = app(AssetController::class)->downloadAndConvertFiles(
        new Request(['files' => ['http://93.184.216.34/remote-limit-fail.jpg']])
    );

    expect($result)->toBeInstanceOf(JsonResponse::class)
        ->and($result->getStatusCode())->toBe(422);
})->with([
    'server error' => [500, 'boom'],
    'empty body'   => [200, ''],
]);

it('removes already downloaded temp files when another url in the batch fails', function () {
    Http::fake([
        '93.184.216.34/remote-limit-ok.jpg'  => Http::response('ok', 200, ['Content-Type' => 'image/jpeg']),
        '93.184.216.34/remote-limit-bad.jpg' => Http::response('', 404),
    ]);

    $result = app(AssetController::class)->downloadAndConvertFiles(
        new Request(['files' => ['http://93.184.216.34/remote-limit-ok.jpg,http://93.184.216.34/remote-limit-bad.jpg']])
    );

    expect($result)->toBeInstanceOf(JsonResponse::class)
        ->and($result->getData(true)['files_processed'])->toBe(1);
});

it('removes the downloaded temp file after a successful api upload by url', function () {
    RemoteDownloadLimitedAssetController::$maxBytes = 1024 * 1024;
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    Http::fake([
        '93.184.216.34/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);

    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/RemoteLimitOk');
    $directory = Directory::factory()->create(['name' => 'RemoteLimitOk', 'parent_id' => null]);

    $this->withHeaders($this->getAuthenticationHeaders())
        ->post(route('admin.api.dam.assets.upload'), [
            'files'        => ['http://93.184.216.34/remote-limit-ok.png'],
            'directory_id' => $directory->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true);
});
