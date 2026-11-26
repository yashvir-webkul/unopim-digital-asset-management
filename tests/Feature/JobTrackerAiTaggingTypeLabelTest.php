<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Admin\DataGrids\Settings\DataTransfer\JobTrackerGrid as BaseJobTrackerGrid;
use Webkul\DAM\DataGrids\DataTransfer\JobTrackerGrid;
use Webkul\DAM\Services\AiTaggingJobTrackerService;

uses(DatabaseTransactions::class);

function damJobTrackerTypeLabel(string $entityType): mixed
{
    $grid = app(BaseJobTrackerGrid::class);
    $grid->prepareColumns();

    $column = collect($grid->getColumns())->firstWhere('index', 'entity_type');

    return ($column->closure)((object) ['entity_type' => $entityType]);
}

it('resolves the core job tracker grid to the DAM grid', function () {
    expect(app(BaseJobTrackerGrid::class))->toBeInstanceOf(JobTrackerGrid::class);
});

it('shows a readable label for the AI tagging job type', function () {
    expect(damJobTrackerTypeLabel(AiTaggingJobTrackerService::ENTITY_TYPE))
        ->toBe(trans('dam::app.data-transfer.job-tracker.types.dam-asset'))
        ->not->toBe(AiTaggingJobTrackerService::ENTITY_TYPE);
});

it('keeps the core labels for other job types', function () {
    expect(damJobTrackerTypeLabel('products'))->toBe(trans(config('importers.products.title')))
        ->and(damJobTrackerTypeLabel('unregistered_type'))->toBe('unregistered_type');
});

it('returns the readable AI tagging label in the job tracker listing', function () {
    $this->loginAsAdmin();

    app(AiTaggingJobTrackerService::class)->startStandaloneJob(auth()->guard('admin')->id());

    $this->getJson(route('admin.settings.data_transfer.tracker.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJsonFragment(['entity_type' => trans('dam::app.data-transfer.job-tracker.types.dam-asset')])
        ->assertJsonMissing(['entity_type' => AiTaggingJobTrackerService::ENTITY_TYPE]);
});
