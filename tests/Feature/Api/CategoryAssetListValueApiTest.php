<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Category\Models\Category;
use Webkul\Category\Models\CategoryField;
use Webkul\DAM\Models\Asset;

beforeEach(function () {
    $this->damResetAssetTables();

    $this->headers = $this->getAuthenticationHeaders();

    $this->fieldCode = 'qa_asset_'.Str::lower(Str::random(6));

    CategoryField::factory()->create([
        'code'   => $this->fieldCode,
        'type'   => 'asset',
        'status' => 1,
    ]);

    $this->assetA = Asset::factory()->create();
    $this->assetB = Asset::factory()->create();
});

it('saves a category whose asset value is sent as a list and links every asset', function () {
    $category = Category::factory()->create();

    $this->withHeaders($this->headers)
        ->putJson(route('admin.api.categories.update', $category->code), [
            'code'            => $category->code,
            'parent'          => null,
            'additional_data' => [
                'common'          => [$this->fieldCode => [$this->assetA->id, $this->assetB->id]],
                'locale_specific' => ['en_US' => ['name' => 'QA']],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $linked = DB::table('dam_asset_resource_mappings')
        ->where('category_id', $category->id)
        ->where('related_field', $this->fieldCode)
        ->pluck('dam_asset_id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();

    expect($linked)->toBe(collect([$this->assetA->id, $this->assetB->id])->sort()->values()->all());
});
