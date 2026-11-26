<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Category\Models\Category;
use Webkul\Category\Models\CategoryField;
use Webkul\DAM\Models\Asset;
use Webkul\Product\Models\Product;

function damScopedProductFixture(bool $perLocale, bool $perChannel): array
{
    $assetCode = 'asset_'.Str::random(8);

    Attribute::factory()->create([
        'code'              => $assetCode,
        'type'              => 'asset',
        'value_per_locale'  => (int) $perLocale,
        'value_per_channel' => (int) $perChannel,
    ]);

    $family = AttributeFamily::factory()->create();
    AttributeFamily::factory()->linkAttributeGroupToFamily($family);
    AttributeFamily::factory()->linkAttributesToFamily($family, Attribute::whereIn('code', [$assetCode])->get());

    $product = Product::create([
        'sku'                 => 'SCP-'.Str::random(8),
        'type'                => 'simple',
        'attribute_family_id' => $family->id,
    ]);

    return [$product, $assetCode];
}

function damSaveProductValues(Product $product, array $values, string $locale, string $channel = 'default'): void
{
    DB::table('products')->where('id', $product->id)->update(['values' => json_encode($values)]);

    request()->merge(['locale' => $locale, 'channel' => $channel]);

    Event::dispatch('catalog.product.update.after', $product->fresh());
}

function damMappedAssetIds(string $column, int $id): array
{
    return DB::table('dam_asset_resource_mappings')
        ->where($column, $id)
        ->pluck('dam_asset_id')
        ->map(fn ($assetId) => (int) $assetId)
        ->sort()
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->damResetAssetTables();

    $this->assetA = Asset::factory()->create();
    $this->assetB = Asset::factory()->create();
});

it('keeps the asset links of other locales when a product is saved in one locale', function () {
    [$product, $code] = damScopedProductFixture(perLocale: true, perChannel: false);

    damSaveProductValues($product, ['locale_specific' => ['en_US' => [$code => [$this->assetA->id]]]], 'en_US');

    damSaveProductValues($product, ['locale_specific' => [
        'en_US' => [$code => [$this->assetA->id]],
        'fr_FR' => [$code => [$this->assetB->id]],
    ]], 'fr_FR');

    expect(damMappedAssetIds('product_id', $product->id))
        ->toBe(collect([$this->assetA->id, $this->assetB->id])->sort()->values()->all());
});

it('keeps the asset links of other locales when the saved locale value is empty', function () {
    [$product, $code] = damScopedProductFixture(perLocale: true, perChannel: false);

    damSaveProductValues($product, ['locale_specific' => ['en_US' => [$code => [$this->assetA->id]]]], 'fr_FR');

    expect(damMappedAssetIds('product_id', $product->id))->toBe([$this->assetA->id]);
});

it('keeps the asset links of other channel and locale scopes', function () {
    [$product, $code] = damScopedProductFixture(perLocale: true, perChannel: true);

    damSaveProductValues($product, ['channel_locale_specific' => [
        'default' => ['en_US' => [$code => [$this->assetA->id]]],
        'mobile'  => ['fr_FR' => [$code => [$this->assetB->id]]],
    ]], 'en_US');

    expect(damMappedAssetIds('product_id', $product->id))
        ->toBe(collect([$this->assetA->id, $this->assetB->id])->sort()->values()->all());
});

it('unlinks an asset once no scope of the product holds it anymore', function () {
    [$product, $code] = damScopedProductFixture(perLocale: true, perChannel: false);

    damSaveProductValues($product, ['locale_specific' => [
        'en_US' => [$code => [$this->assetA->id]],
        'fr_FR' => [$code => [$this->assetB->id]],
    ]], 'en_US');

    damSaveProductValues($product, ['locale_specific' => [
        'en_US' => [$code => [$this->assetA->id]],
        'fr_FR' => [$code => []],
    ]], 'fr_FR');

    expect(damMappedAssetIds('product_id', $product->id))->toBe([$this->assetA->id]);
});

it('still syncs a common asset attribute', function () {
    [$product, $code] = damScopedProductFixture(perLocale: false, perChannel: false);

    damSaveProductValues($product, ['common' => [$code => [$this->assetA->id]]], 'fr_FR');
    expect(damMappedAssetIds('product_id', $product->id))->toBe([$this->assetA->id]);

    damSaveProductValues($product, ['common' => [$code => []]], 'fr_FR');
    expect(damMappedAssetIds('product_id', $product->id))->toBe([]);
});

it('keeps the asset links of other locales when a category is saved in one locale', function () {
    $code = 'cat_asset_'.Str::random(6);

    CategoryField::factory()->create([
        'code'             => $code,
        'type'             => 'asset',
        'status'           => 1,
        'value_per_locale' => 1,
    ]);

    $category = Category::factory()->create();

    $category->additional_data = ['locale_specific' => [
        'en_US' => [$code => (string) $this->assetA->id],
        'fr_FR' => [$code => (string) $this->assetB->id],
    ]];
    $category->saveQuietly();

    request()->merge(['locale' => 'fr_FR']);

    Event::dispatch('catalog.category.update.after', $category->fresh());

    expect(damMappedAssetIds('category_id', $category->id))
        ->toBe(collect([$this->assetA->id, $this->assetB->id])->sort()->values()->all());
});
