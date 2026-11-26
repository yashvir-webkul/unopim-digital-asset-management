<?php

namespace Webkul\DAM\Listeners;

use Webkul\DAM\Repositories\AssetRepository;
use Webkul\DAM\Repositories\AssetResourceMappingRepository;
use Webkul\DAM\Traits\CollectsAssetIds;
use Webkul\Product\Models\ProductProxy;

class Product
{
    use CollectsAssetIds;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetResourceMappingRepository $assetResourceMappingRepository
    ) {}

    /**
     * Maps asset attributes after an import batch, whose bulk upsert fires no product events; accepts ids or a `product_id` wrapper.
     */
    public function afterImportBatch(array $payload): void
    {
        $productIds = array_filter(
            is_array($payload['product_id'] ?? null) ? $payload['product_id'] : $payload
        );

        if (empty($productIds)) {
            return;
        }

        ProductProxy::with('attribute_family')
            ->whereIn('id', $productIds)
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    $this->syncAssetMappings($product);
                }
            });
    }

    protected function syncAssetMappings($product): void
    {
        $family = $product->attribute_family;

        if (! $family) {
            return;
        }

        $assetFields = $family->customAttributes()->where('attributes.type', 'asset')->get();

        foreach ($assetFields as $assetField) {
            $fieldCode = $assetField->code;

            $assetIds = $this->collectAssetIdsAcrossScopes((array) $product->values, $fieldCode);

            if (empty($assetIds)) {
                $this->assetResourceMappingRepository->deleteProductAssetMappings($product->id, $fieldCode);

                continue;
            }

            $assets = $this->assetRepository->findWhereIn('id', $assetIds);

            if (! $assets || $assets->isEmpty()) {
                continue;
            }

            $this->assetResourceMappingRepository->createProductAssetMappings($assets, $product->id, $fieldCode);
        }
    }

    /**
     * Reads every scope, not just the requested one: syncing one locale/channel prunes the field's mappings to that value, unlinking assets other scopes still hold.
     */
    public function afterCreateOrupdate($product): void
    {
        $this->syncAssetMappings($product);
    }
}
