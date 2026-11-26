<?php

namespace Webkul\DAM\Exceptions;

/**
 * Thrown when an asset webhook endpoint fails (non-2xx or unreachable) so the
 * queue worker retries the job and, once its tries run out, records it in failed_jobs.
 */
class WebhookDeliveryFailedException extends \RuntimeException {}
