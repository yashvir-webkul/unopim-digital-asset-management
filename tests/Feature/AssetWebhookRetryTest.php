<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\DAM\Exceptions\WebhookDeliveryFailedException;
use Webkul\DAM\Jobs\SendAssetWebhook;
use Webkul\DAM\Jobs\SendBulkAssetWebhook;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Services\AssetWebhookDispatcher;
use Webkul\Webhook\Models\Webhook;
use Webkul\Webhook\Models\WebhookLog;
use Webkul\Webhook\Repositories\LogsRepository;
use Webkul\Webhook\Repositories\WebhookRepository;

uses(DatabaseTransactions::class);

beforeEach(function () {
    AssetWebhookDispatcher::flushAvailability();
    Webhook::query()->delete();
    WebhookLog::query()->delete();
});

function makeRetryWebhook(string $url = 'https://8.8.8.8/hook', array $events = ['asset.created']): Webhook
{
    return Webhook::create([
        'name'      => 'Retry hook',
        'url'       => $url,
        'is_active' => true,
        'events'    => $events,
    ]);
}

function runAssetJob(object $job): void
{
    $job->handle(app(WebhookRepository::class), app(LogsRepository::class));
}

it('throws so the queue retries when the endpoint returns HTTP 500', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $asset = Asset::factory()->create();

    $webhook = makeRetryWebhook();

    expect(fn () => runAssetJob(new SendAssetWebhook($asset->id, 'asset.created')))
        ->toThrow(WebhookDeliveryFailedException::class);

    $log = WebhookLog::where('webhook_id', $webhook->id)->first();

    expect($log)->not->toBeNull()
        ->and((int) $log->status)->toBe(0)
        ->and((int) $log->http_code)->toBe(500);
});

it('throws so the queue retries when the endpoint is down', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $asset = Asset::factory()->create();

    $webhook = makeRetryWebhook();

    expect(fn () => runAssetJob(new SendAssetWebhook($asset->id, 'asset.created')))
        ->toThrow(WebhookDeliveryFailedException::class);

    expect(WebhookLog::where('webhook_id', $webhook->id)->where('status', 0)->exists())->toBeTrue();
});

it('throws for a failed bulk delivery too', function () {
    Http::fake(['*' => Http::response('boom', 503)]);

    $assets = Asset::factory()->count(2)->create();

    makeRetryWebhook();

    expect(fn () => runAssetJob(new SendBulkAssetWebhook($assets->pluck('id')->all(), 'asset.created')))
        ->toThrow(WebhookDeliveryFailedException::class);
});

it('does not throw when delivery succeeds', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $asset = Asset::factory()->create();

    makeRetryWebhook();

    runAssetJob(new SendAssetWebhook($asset->id, 'asset.created'));

    expect(WebhookLog::where('status', 1)->count())->toBe(1);
});

it('does not retry a webhook whose URL is blocked as unsafe', function () {
    Http::fake();

    $asset = Asset::factory()->create();

    makeRetryWebhook('http://127.0.0.1/hook');

    runAssetJob(new SendAssetWebhook($asset->id, 'asset.created'));

    Http::assertNothingSent();
});

it('fans out one job per webhook so a retry never re-sends to endpoints that succeeded', function () {
    Queue::fake();

    $asset = Asset::factory()->create();

    $first = makeRetryWebhook('https://8.8.8.8/one');
    $second = makeRetryWebhook('https://8.8.4.4/two');

    runAssetJob(new SendAssetWebhook($asset->id, 'asset.created'));

    $targets = collect(Queue::pushed(SendAssetWebhook::class))
        ->map(fn (SendAssetWebhook $job) => (new ReflectionProperty($job, 'webhookId'))->getValue($job))
        ->filter()
        ->sort()
        ->values()
        ->all();

    expect($targets)->toBe(collect([$first->id, $second->id])->sort()->values()->all());

    Queue::assertPushedOn('webhooks', SendAssetWebhook::class);
});

it('a targeted job only delivers to its own webhook', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $asset = Asset::factory()->create();

    makeRetryWebhook('https://8.8.8.8/one');
    $second = makeRetryWebhook('https://8.8.4.4/two');

    runAssetJob(new SendAssetWebhook($asset->id, 'asset.created', null, $second->id));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://8.8.4.4/two');
});

it('keeps the configured retry budget of 3 attempts', function () {
    expect((new SendAssetWebhook(1, 'asset.created'))->tries)->toBe(3)
        ->and((new SendBulkAssetWebhook([1], 'asset.created'))->tries)->toBe(3);
});

it('does not break the asset save when the sync queue driver delivers a failing webhook', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $webhook = makeRetryWebhook();

    $asset = Asset::factory()->create();

    expect($asset->exists)->toBeTrue()
        ->and(WebhookLog::where('webhook_id', $webhook->id)->where('status', 0)->exists())->toBeTrue();
});
