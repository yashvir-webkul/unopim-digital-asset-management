<?php

declare(strict_types=1);

namespace Webkul\DAM\DataGrids\DataTransfer;

use Webkul\Admin\DataGrids\Settings\DataTransfer\JobTrackerGrid as BaseJobTrackerGrid;
use Webkul\DAM\Services\AiTaggingJobTrackerService;

/**
 * Labels DAM system jobs (AI tagging) in the core Job Tracker, whose type column only knows registered importers.
 */
class JobTrackerGrid extends BaseJobTrackerGrid
{
    public function prepareColumns(): void
    {
        parent::prepareColumns();

        foreach ($this->columns as $column) {
            if ($column->index !== 'entity_type') {
                continue;
            }

            $coreClosure = $column->closure;

            $column->closure = static fn (object $row): mixed => $row->entity_type === AiTaggingJobTrackerService::ENTITY_TYPE
                ? trans('dam::app.data-transfer.job-tracker.types.dam-asset')
                : ($coreClosure ? $coreClosure($row) : $row->entity_type);
        }
    }
}
