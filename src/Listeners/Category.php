<?php

namespace Webkul\DAM\Listeners;

use Webkul\Category\Repositories\CategoryFieldRepository;
use Webkul\DAM\Repositories\AssetRepository;
use Webkul\DAM\Repositories\AssetResourceMappingRepository;
use Webkul\DAM\Traits\CollectsAssetIds;

class Category
{
    use CollectsAssetIds;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected CategoryFieldRepository $categoryFieldRepository,
        protected AssetResourceMappingRepository $assetResourceMappingRepository
    ) {}

    /**
     * Reads every locale, not just the requested one: syncing one locale prunes the field's mappings to that value, unlinking assets other locales still hold.
     */
    public function afterUpdateOrCreate($category)
    {
        $activeAssetFields = $this->categoryFieldRepository->findWhere(['status' => 1, 'type' => 'asset']);

        $additionalData = (array) $category->additional_data;

        $categoryId = $category->id;

        foreach ($activeAssetFields as $assetField) {
            $fieldCode = $assetField->code;

            $assetIds = $this->collectAssetIdsAcrossScopes($additionalData, $fieldCode);

            if (empty($assetIds)) {
                $this->assetResourceMappingRepository->deleteCategoryAssetMappings($categoryId, $fieldCode);

                continue;
            }

            $assets = $this->assetRepository->findWhereIn('id', $assetIds);

            if (! $assets) {
                continue;
            }

            $this->assetResourceMappingRepository->createCategoryAssetMappings($assets, $categoryId, $fieldCode);
        }
    }
}
