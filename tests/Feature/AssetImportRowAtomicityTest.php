<?php

use Illuminate\Bus\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Helpers\Importers\Asset\Importer;
use Webkul\DAM\Jobs\ProcessAssetUpload;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\AssetProperty;
use Webkul\DAM\Models\Directory;
use Webkul\DataTransfer\Helpers\Error;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Models\JobInstances;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\DataTransfer\Models\JobTrackBatch;

function atomicPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
}

function atomicImport(array $rows, string $conflictStrategy = 'skip'): Importer
{
    $jobInstance = JobInstances::factory()->importJob()->create([
        'entity_type' => 'assets',
        'filters'     => ['conflict_strategy' => $conflictStrategy, 'auto_tag' => false],
    ]);

    $jobTrack = JobTrack::factory()->create([
        'action'                => Import::ACTION_APPEND,
        'job_instances_id'      => $jobInstance->id,
        'images_directory_path' => 'imp',
    ]);

    $importer = app(Importer::class);
    $importer->setImport($jobTrack);
    $importer->setErrorHelper(app(Error::class));

    $importer->importBatch(JobTrackBatch::factory()->create([
        'job_track_id' => $jobTrack->id,
        'data'         => $rows,
    ]));

    return $importer;
}

function atomicRow(string $fileName, string $directory = 'Root', string $properties = '', string $tags = ''): array
{
    return ['path' => 'logo.png', 'file_name' => $fileName, 'directory' => $directory, 'tags' => $tags, 'properties' => $properties];
}

beforeEach(function () {
    $this->damResetAssetTables();
    Storage::fake('local');
    Storage::fake(Directory::getAssetDisk());
    Storage::disk('local')->put('public/imp/logo.png', atomicPng());
    Bus::fake();
});

it('removes the stored file when the asset insert fails', function () {
    Asset::creating(function (Asset $asset) {
        if ($asset->file_name === 'insert-fails.png') {
            throw new RuntimeException('insert failed');
        }
    });

    $importer = atomicImport([atomicRow('insert-fails.png'), atomicRow('ok.png')]);

    expect(Asset::where('file_name', 'insert-fails.png')->exists())->toBeFalse()
        ->and(Asset::where('file_name', 'ok.png')->exists())->toBeTrue()
        ->and($importer->getCreatedItemsCount())->toBe(1);

    Storage::disk(Directory::getAssetDisk())->assertMissing('assets/Root/insert-fails.png');
    Storage::disk(Directory::getAssetDisk())->assertExists('assets/Root/ok.png');
});

it('rolls back the asset, its tags and its file when a property fails to save', function () {
    AssetProperty::creating(fn () => throw new RuntimeException('property failed'));

    $properties = json_encode([['name' => 'colour', 'value' => 'x']]);
    $importer = atomicImport([atomicRow('partial.png', 'Root', $properties, 'brand')]);

    expect(Asset::where('file_name', 'partial.png')->exists())->toBeFalse()
        ->and($importer->getCreatedItemsCount())->toBe(0)
        ->and(DB::table('dam_asset_tag')->count())->toBe(0);

    Storage::disk(Directory::getAssetDisk())->assertMissing('assets/Root/partial.png');
});

it('does not queue the upload processing job for a row that was rolled back', function () {
    Bus::swap(app(Dispatcher::class));
    config(['queue.default' => 'sync']);

    $processed = [];
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$processed) {
        $processed[] = $event->job->resolveName();
    });

    AssetProperty::creating(fn () => throw new RuntimeException('property failed'));

    atomicImport([atomicRow('queued.png', 'Root', json_encode([['name' => 'colour', 'type' => 'text', 'value' => 'x']]))]);

    expect($processed)->not->toContain(ProcessAssetUpload::class)
        ->and(Asset::where('file_name', 'queued.png')->exists())->toBeFalse();
});

it('imports the row on a second run after a failed first run instead of skipping it', function () {
    $properties = json_encode([['name' => 'colour', 'type' => 'text', 'value' => 'red']]);

    AssetProperty::creating(fn () => throw new RuntimeException('property failed'));
    atomicImport([atomicRow('retry.png', 'Root', $properties)]);

    AssetProperty::flushEventListeners();

    $importer = atomicImport([atomicRow('retry.png', 'Root', $properties)]);

    $asset = Asset::where('file_name', 'retry.png')->first();

    expect($importer->getCreatedItemsCount())->toBe(1)
        ->and($asset)->not->toBeNull()
        ->and(AssetProperty::where('dam_asset_id', $asset->id)->where('name', 'colour')->exists())->toBeTrue();
});

it('still imports later rows into a new folder after an earlier row in that folder was rolled back', function () {
    Asset::creating(function (Asset $asset) {
        if ($asset->file_name === 'first.png') {
            throw new RuntimeException('insert failed');
        }
    });

    $importer = atomicImport([atomicRow('first.png', 'Root/Fresh'), atomicRow('second.png', 'Root/Fresh')]);

    $asset = Asset::where('file_name', 'second.png')->first();

    expect($importer->getCreatedItemsCount())->toBe(1)
        ->and($asset)->not->toBeNull()
        ->and($asset->directories()->where('name', 'Fresh')->exists())->toBeTrue();
});

it('keeps the existing asset unchanged when an update_metadata row fails on a property', function () {
    Storage::disk(Directory::getAssetDisk())->put('assets/Root/kept.png', atomicPng());
    $asset = Asset::factory()->create(['path' => 'assets/Root/kept.png', 'file_name' => 'kept.png']);

    AssetProperty::creating(fn () => throw new RuntimeException('property failed'));

    atomicImport([atomicRow('kept.png', 'Root', json_encode([['name' => 'colour', 'value' => 'x']]), 'newtag')], 'update_metadata');

    expect(Asset::find($asset->id))->not->toBeNull()
        ->and(DB::table('dam_asset_tag')->where('asset_id', $asset->id)->count())->toBe(0);

    Storage::disk(Directory::getAssetDisk())->assertExists('assets/Root/kept.png');
});
