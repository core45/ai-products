<?php

declare(strict_types=1);

namespace Core45\AiProducts\Jobs;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiProducts\Services\ProductContentRewriteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TranslateProductToLocale implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public readonly string $operationId;

    public function __construct(
        public Model $product,
        public string $sourceLocale,
        public string $targetLocale,
        public ?int $userId = null,
        public ?string $lockToken = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * NOTE (08-08 deviation, plan-documented): `$client` is resolved via method
     * injection to satisfy SEAM-08's AiClient seam gate, but is not yet called.
     * This job delegates entirely to
     * `ProductContentRewriteService::translateToLocale()`, which is
     * model-bound and has no analog on the flat `AiClient::translate()`
     * contract. Routing the rewrite service's internal call through
     * `AiClient` is owned by plan 08-15. `$this->operationId` is minted here
     * (once, at dispatch, reused across retries) for 08-15 to thread through.
     */
    public function handle(AiClient $client, ProductContentRewriteService $service): void
    {
        $lockClass = app(ModelRegistry::class)->getModelClassOrFail('translation_lock');
        $productId = $this->product->getKey();

        /** @phpstan-ignore staticMethod.notFound */
        if ($this->lockToken && $lockClass::isCancelledByToken($this->lockToken)) {
            return;
        }

        Log::info('TranslateProductToLocale: Starting', [
            'product_id' => $productId,
            'source' => $this->sourceLocale,
            'target' => $this->targetLocale,
            'user_id' => $this->userId,
        ]);

        $service->forTranslation();

        // Thread the job's operationId (minted once at dispatch, stable
        // across retries) so the service derives deterministic per-field
        // UUIDv5 ids — retries replay identical ids and phasea's usage
        // metering does not re-bill already-translated fields (audit 5.4).
        $service->setOperationId($this->operationId);

        if ($this->userId !== null) {
            $service->setUserId($this->userId);
        }

        if ($this->lockToken) {
            /** @phpstan-ignore staticMethod.notFound */
            $service->onCancellation(fn (): bool => $lockClass::isCancelledByToken($this->lockToken));
        }

        try {
            $result = $service->translateToLocale(
                $this->product,
                $this->sourceLocale,
                $this->targetLocale,
                overwrite: true,
            );

            $hasErrors = $result['errors'] !== [];
            $hasProcessed = $result['processed_fields'] !== [];

            Log::info('TranslateProductToLocale: Completed', [
                'product_id' => $productId,
                'processed_fields' => count($result['processed_fields']),
                'errors' => count($result['errors']),
            ]);

            $productName = $this->getProductName();

            if ($hasErrors && !$hasProcessed) {
                $this->notifyUser(
                    __('Translation failed'),
                    __('Failed to translate product ":name" from :source to :target.', [
                        'name' => $productName,
                        'source' => $this->sourceLocale,
                        'target' => $this->targetLocale,
                    ]),
                    'danger',
                );

                return;
            }

            if ($hasErrors) {
                $fields = $this->extractFieldNames($result['errors']);
                $this->notifyUser(
                    __('Translation partially completed'),
                    __('Product ":name" was partially translated to :target. Failed fields: :fields', [
                        'name' => $productName,
                        'target' => $this->targetLocale,
                        'fields' => $fields,
                    ]),
                    'warning',
                );

                return;
            }

            if ($this->lockToken) {
                /** @phpstan-ignore staticMethod.notFound */
                $lockClass::releaseByToken($this->lockToken);
            }

            $this->notifyUser(
                __('Translation completed'),
                __('Product ":name" has been translated from :source to :target.', [
                    'name' => $productName,
                    'source' => $this->sourceLocale,
                    'target' => $this->targetLocale,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('TranslateProductToLocale: Rate limited, releasing back to queue', [
                'product_id' => $productId,
                'target_locale' => $this->targetLocale,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiBudgetExceededException $e) {
            // Budget exhaustion will not clear within this job's backoff
            // window — retrying burns attempts for nothing. Fail immediately
            // so failed() notifies the user right away (audit §6).
            Log::warning('TranslateProductToLocale: AI budget exceeded, failing immediately', [
                'product_id' => $productId,
                'target_locale' => $this->targetLocale,
            ]);

            $this->fail($e);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->lockToken) {
            $lockClass = app(ModelRegistry::class)->getModelClassOrFail('translation_lock');
            /** @phpstan-ignore staticMethod.notFound */
            $lockClass::restoreSnapshot($this->lockToken);
            /** @phpstan-ignore staticMethod.notFound */
            $lockClass::releaseByToken($this->lockToken);
        }

        Log::error('TranslateProductToLocale: Permanently failed', [
            'product_id' => $this->product->getKey(),
            'source' => $this->sourceLocale,
            'target' => $this->targetLocale,
            'error' => $exception?->getMessage(),
        ]);

        $this->notifyUser(
            __('Translation failed'),
            __('Failed to translate product ":name" from :source to :target: :error', [
                'name' => $this->getProductName(),
                'source' => $this->sourceLocale,
                'target' => $this->targetLocale,
                'error' => $exception?->getMessage() ?? 'Unknown error',
            ]),
            'danger',
        );
    }

    private function getProductName(): string
    {
        $productId = $this->product->getKey();

        if (!$this->product instanceof Translatable) {
            return "#{$productId}";
        }

        $translation = $this->product->translate($this->sourceLocale);

        return $translation->name ?? "#{$productId}";
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function extractFieldNames(array $errors): string
    {
        return collect($errors)
            ->map(function (string $error): string {
                $parts = explode(' - ', $error, 2);

                return trim($parts[0]);
            })
            ->unique()
            ->implode(', ');
    }

    private function notifyUser(string $title, string $body, string $status): void
    {
        if ($this->userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($this->userId);
        if ($user === null) {
            return;
        }

        app(NotifiesOperator::class)->notifyOperator($user, $title, $body, NotificationSeverity::fromStatus($status));
    }
}
