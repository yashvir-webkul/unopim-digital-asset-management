<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Helpers\Importers\Asset\Importer;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\AssetProperty;
use Webkul\DAM\Models\Directory;
use Webkul\DataTransfer\Helpers\Error;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Models\JobInstances;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\DataTransfer\Models\JobTrackBatch;

function propsJsonImporter(): array
{
    $jobInstance = JobInstances::factory()->importJob()->create([
        'entity_type' => 'assets',
        'filters'     => ['conflict_strategy' => 'skip', 'auto_tag' => false],
    ]);

    $jobTrack = JobTrack::factory()->create([
        'action'                => Import::ACTION_APPEND,
        'job_instances_id'      => $jobInstance->id,
        'images_directory_path' => 'imp',
    ]);

    $importer = app(Importer::class);
    $importer->setImport($jobTrack);
    $importer->setErrorHelper($errors = app(Error::class));
    test()->propsErrorHelper = $errors;

    return [$importer, $jobTrack];
}

function propsJsonRow(string $properties, string $fileName = 'props-test.png'): array
{
    return ['path' => 'logo.png', 'directory' => 'Root', 'file_name' => $fileName, 'tags' => '', 'properties' => $properties];
}

function propsJsonErrors(Importer $importer, int $row): array
{
    return test()->propsErrorHelper->getAllErrors()[$row] ?? [];
}

beforeEach(function () {
    $this->damResetAssetTables();
    Storage::fake('local');
    Storage::fake(Directory::getAssetDisk());
    Storage::disk('local')->put('public/imp/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));
    Bus::fake();
});

it('reports a row whose properties column is not valid json', function (string $properties) {
    [$importer] = propsJsonImporter();

    expect($importer->validateRow(propsJsonRow($properties), 2))->toBeFalse();

    $errors = propsJsonErrors($importer, 2);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['code'])->toBe(Importer::ERROR_PROPERTIES_INVALID)
        ->and($errors[0]['column'])->toBe('properties')
        ->and($errors[0]['message'])->toBe(trans('dam::app.data-transfer.importers.assets.validation.errors.properties-invalid'));
})->with([
    'unquoted keys'     => ['[{name: Colour, value: Red}]'],
    'missing bracket'   => ['[{"name":"Colour","value":"Red"}'],
    'object not a list' => ['{"name":"Colour","value":"Red"}'],
    'list of strings'   => ['["Colour","Red"]'],
    'plain text'        => ['Colour=Red'],
]);

it('accepts a row with valid or empty properties', function (string $properties) {
    [$importer] = propsJsonImporter();

    expect($importer->validateRow(propsJsonRow($properties), 2))->toBeTrue()
        ->and(propsJsonErrors($importer, 2))->toBe([]);
})->with([
    'empty'      => [''],
    'empty list' => ['[]'],
    'valid list' => ['[{"name":"Colour","type":"text","value":"Red"}]'],
]);

it('does not import a row with invalid properties json that reaches the import step', function () {
    [$importer, $jobTrack] = propsJsonImporter();

    $importer->importBatch(JobTrackBatch::factory()->create([
        'job_track_id' => $jobTrack->id,
        'data'         => [
            propsJsonRow('[{name: Colour, value: Red}]', 'bad-props.png'),
            propsJsonRow('[{"name":"Colour","type":"text","value":"Red"}]', 'good-props.png'),
        ],
    ]));

    $good = Asset::where('file_name', 'good-props.png')->first();

    expect(Asset::where('file_name', 'bad-props.png')->exists())->toBeFalse()
        ->and($importer->getCreatedItemsCount())->toBe(1)
        ->and(AssetProperty::where('dam_asset_id', $good->id)->where('name', 'Colour')->value('value'))->toBe('Red')
        ->and(collect(propsJsonErrors($importer, 1))->pluck('code')->all())->toBe([Importer::ERROR_PROPERTIES_INVALID]);

    Storage::disk(Directory::getAssetDisk())->assertMissing('assets/Root/bad-props.png');
});
