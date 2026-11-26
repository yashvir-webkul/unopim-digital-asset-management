<?php

declare(strict_types=1);

namespace Webkul\DAM\Jobs\Concerns;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\DAM\Exceptions\WebhookDeliveryFailedException;
use Webkul\DAM\Helpers\AssetHelper;
use Webkul\DAM\Models\Asset;
use Webkul\User\Models\AdminProxy;
use Webkul\Webhook\Models\Webhook;
use Webkul\Webhook\Repositories\LogsRepository;
use Webkul\Webhook\Repositories\WebhookRepository;
use Webkul\Webhook\Validators\SafeWebhookUrl;

trait InteractsWithAssetWebhook
{
    /**
     * Active webhooks for this job's event, narrowed to the targeted one when set.
     *
     * @return Collection<int, Webhook>
     */
    protected function activeWebhooks(WebhookRepository $webhookRepository): Collection
    {
        $webhooks = $webhookRepository->getActiveForEvent($this->event);

        if ($this->webhookId !== null) {
            return $webhooks->where('id', $this->webhookId)->values();
        }

        return $webhooks;
    }

    /**
     * Split a multi-subscriber delivery into one job per webhook, so each
     * endpoint retries on its own and a retry never re-sends to endpoints
     * that already succeeded.
     *
     * @param  Collection<int, Webhook>  $webhooks
     */
    protected function fanOutPerWebhook(Collection $webhooks): bool
    {
        if ($this->webhookId !== null || $webhooks->count() < 2) {
            return false;
        }

        $dispatcher = resolve(Dispatcher::class);

        foreach ($webhooks as $webhook) {
            $job = clone $this;
            $job->webhookId = (int) $webhook->id;
            $job->job = null;

            $dispatcher->dispatch($job->onQueue($this->queue ?? 'webhooks'));
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeAsset(Asset $asset): array
    {
        return [
            'id'          => $asset->id,
            'file_name'   => $asset->file_name,
            'path'        => $asset->path,
            'file_type'   => $asset->file_type,
            'mime_type'   => $asset->mime_type,
            'extension'   => $asset->extension,
            'file_size'   => $asset->file_size,
            'preview_url' => AssetHelper::getPreviewUrl($asset->path),
            'directories' => $asset->directories->map(fn ($directory) => [
                'id'   => $directory->id,
                'name' => $directory->name,
            ])->values()->all(),
            'tags'        => $asset->tags->pluck('name')->values()->all(),
            'created_at'  => $asset->created_at?->toIso8601String(),
            'updated_at'  => $asset->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Deliver and log one webhook call. Throws on a failed delivery so the
     * queue retries the job up to $tries and then moves it to failed_jobs.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookDeliveryFailedException
     */
    protected function deliverWebhook(Webhook $webhook, array $payload, string $sku, LogsRepository $logsRepository): void
    {
        $safety = SafeWebhookUrl::validate($webhook->url);

        if (! $safety['valid']) {
            Log::warning('Asset webhook dispatch blocked — unsafe URL', [
                'webhook_id' => $webhook->id,
                'reason'     => $safety['reason'],
                'ip'         => $safety['ip'] ?? null,
            ]);

            return;
        }

        $body = json_encode($payload);
        $exception = null;
        $response = null;

        try {
            $response = Http::withOptions(SafeWebhookUrl::httpOptions($webhook->url))
                ->withHeaders($this->buildHeaders($webhook, $body))
                ->withBody($body, 'application/json')
                ->post($webhook->url);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $status = $response?->successful() ? 1 : 0;
        $httpCode = $response?->status() ?? ($exception?->getCode() ?: null);

        $logsRepository->create([
            'webhook_id' => $webhook->id,
            'user'       => $this->actingName(),
            'sku'        => $sku,
            'event'      => $this->event,
            'status'     => $status,
            'http_code'  => is_int($httpCode) ? $httpCode : null,
            'extra'      => [
                'payload'  => $payload,
                'response' => $response?->body() ?? $exception?->getMessage(),
            ],
        ]);

        // The sync driver cannot retry; throwing there would only break the asset save.
        if ($status === 0 && ! $this->job instanceof SyncJob) {
            throw new WebhookDeliveryFailedException(
                sprintf('Asset webhook #%d delivery failed for event "%s" (HTTP %s).', $webhook->id, $this->event, $httpCode ?? 'n/a'),
                0,
                $exception,
            );
        }
    }

    /**
     * @return array<string, string>
     */
    protected function buildHeaders(Webhook $webhook, string $body): array
    {
        $headers = [
            'X-Unopim-Event'      => $this->event,
            'X-Unopim-Webhook-Id' => (string) $webhook->id,
        ];

        foreach ((array) $webhook->headers as $key => $value) {
            if (is_string($key) && $key !== '') {
                $headers[$key] = (string) $value;
            }
        }

        if (! empty($webhook->secret)) {
            $headers['X-Unopim-Signature'] = 'sha256='.hash_hmac('sha256', $body, (string) $webhook->secret);
        }

        return $headers;
    }

    protected function actingName(): ?string
    {
        if ($this->userId === null || ! class_exists(AdminProxy::class)) {
            return null;
        }

        return AdminProxy::find($this->userId)?->name;
    }
}
