<?php

declare(strict_types=1);

namespace Core45\AiProducts\Tests\Fixtures;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\DTOs\AiTextResult;
use Core45\AiCore\DTOs\ChatRequest;
use Core45\AiCore\DTOs\ChatResponse;
use Core45\AiCore\DTOs\ContentRequest;
use Core45\AiCore\DTOs\ContentResponse;
use Core45\AiCore\DTOs\DetectionRequest;
use Core45\AiCore\DTOs\DetectionResult;
use Core45\AiCore\DTOs\HumanizationRequest;
use Core45\AiCore\DTOs\HumanizationResult;
use Core45\AiCore\DTOs\SeoResponse;
use Core45\AiCore\DTOs\TranslationRequest;
use Core45\AiCore\DTOs\TranslationResponse;
use Core45\AiCore\Enums\AiSurface;

/**
 * The ONE faked collaborator in the end-to-end product rewrite proof: the remote AI
 * call. Everything else in that test is real.
 *
 * Every method except `runPrompt()` and `isEnabled()` throws, so a rewrite that
 * quietly reaches a different AI surface fails loudly and names the method
 * instead of returning a silent default.
 */
final class FakeAiClient implements AiClient
{
    /** @var list<array{template: string, field: string|null}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> the raw parameter set of every call, in order */
    public array $parameterSets = [];

    public function runPrompt(string $template, array $parameters, string $operationId, array $options = []): AiTextResult
    {
        $field = isset($parameters['field']) && is_string($parameters['field']) ? $parameters['field'] : null;

        $this->calls[] = ['template' => $template, 'field' => $field];
        $this->parameterSets[] = $parameters;

        return new AiTextResult(
            text: self::cannedTextFor($field),
            promptTokens: 10,
            completionTokens: 20,
        );
    }

    /**
     * The canned reply per field. Deliberately free of backticks and markdown
     * fences: `callPromptTemplate()` post-processes rich replies through
     * `stripCodeBlockWrappers()` and the Markdown converter.
     */
    public static function cannedTextFor(?string $field): string
    {
        return match ($field) {
            'summary' => 'AI SUMMARY for the product.',
            'description' => 'AI DESCRIPTION for the product.',
            'seo_title' => 'AI SEO TITLE',
            'seo_description' => 'AI SEO DESCRIPTION',
            'seo_keywords' => 'ai, seo, keywords',
            default => 'AI CONTENT',
        };
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        throw new \LogicException('Unexpected AiClient::chat() call.');
    }

    public function customerChat(ChatRequest $request): ChatResponse
    {
        throw new \LogicException('Unexpected AiClient::customerChat() call.');
    }

    public function streamChat(ChatRequest $request): \Generator
    {
        throw new \LogicException('Unexpected AiClient::streamChat() call.');
    }

    public function generateContent(ContentRequest $request): ContentResponse
    {
        throw new \LogicException('Unexpected AiClient::generateContent() call.');
    }

    public function generateSEO(ContentRequest $request): SeoResponse
    {
        throw new \LogicException('Unexpected AiClient::generateSEO() call.');
    }

    public function query(string $query, string $locale, ?int $limit, string $operationId): array
    {
        throw new \LogicException('Unexpected AiClient::query() call.');
    }

    public function previewAction(string $actionType, string $entityType, array $entityData, string $locale, string $operationId): array
    {
        throw new \LogicException('Unexpected AiClient::previewAction() call.');
    }

    public function executeAction(string $actionType, string $entityType, array $entityData, string $locale, string $operationId, bool $confirmed = false): array
    {
        throw new \LogicException('Unexpected AiClient::executeAction() call.');
    }

    public function getConversations(int $userId): array
    {
        throw new \LogicException('Unexpected AiClient::getConversations() call.');
    }

    public function createConversation(int $userId, ?string $title = null): array
    {
        throw new \LogicException('Unexpected AiClient::createConversation() call.');
    }

    public function closeConversation(string $conversationId): bool
    {
        throw new \LogicException('Unexpected AiClient::closeConversation() call.');
    }

    public function getSchema(): array
    {
        throw new \LogicException('Unexpected AiClient::getSchema() call.');
    }

    public function translate(TranslationRequest $request): TranslationResponse
    {
        throw new \LogicException('Unexpected AiClient::translate() call.');
    }

    public function rewriteContent(string $content, string $style, string $locale, string $operationId): string
    {
        throw new \LogicException('Unexpected AiClient::rewriteContent() call.');
    }

    public function embed(string $text): ?array
    {
        throw new \LogicException('Unexpected AiClient::embed() call.');
    }

    public function embedBatch(array $texts): array
    {
        throw new \LogicException('Unexpected AiClient::embedBatch() call.');
    }

    public function summarize(string $text, string $operationId): string
    {
        throw new \LogicException('Unexpected AiClient::summarize() call.');
    }

    public function keyphraseResearch(string $topic, string $locale, string $operationId, ?string $contentExcerpt = null): array
    {
        throw new \LogicException('Unexpected AiClient::keyphraseResearch() call.');
    }

    public function groundedSearch(string $query, int $maxResults, string $operationId): array
    {
        throw new \LogicException('Unexpected AiClient::groundedSearch() call.');
    }

    public function generateImageFromTemplate(string $template, array $parameters, array $params, string $operationId): array
    {
        throw new \LogicException('Unexpected AiClient::generateImageFromTemplate() call.');
    }

    public function generateAltText(string $mediaId, string $locale, string $operationId): string
    {
        throw new \LogicException('Unexpected AiClient::generateAltText() call.');
    }

    public function completeText(string $systemPrompt, string $userPrompt, array $options, AiSurface $surface, string $operationId): AiTextResult
    {
        throw new \LogicException('Unexpected AiClient::completeText() call.');
    }

    public function listPromptTemplates(): array
    {
        throw new \LogicException('Unexpected AiClient::listPromptTemplates() call.');
    }

    public function listServiceCapabilities(): array
    {
        throw new \LogicException('Unexpected AiClient::listServiceCapabilities() call.');
    }

    public function listPromptOverrideExamples(): array
    {
        throw new \LogicException('Unexpected AiClient::listPromptOverrideExamples() call.');
    }

    public function triggerBlogPipeline(string $topicId, string $locale, array $config, string $operationId): array
    {
        throw new \LogicException('Unexpected AiClient::triggerBlogPipeline() call.');
    }

    public function submitFeedback(array $feedback, string $operationId): bool
    {
        throw new \LogicException('Unexpected AiClient::submitFeedback() call.');
    }

    public function detect(DetectionRequest $request): ?DetectionResult
    {
        throw new \LogicException('Unexpected AiClient::detect() call.');
    }

    public function humanize(HumanizationRequest $request): ?HumanizationResult
    {
        throw new \LogicException('Unexpected AiClient::humanize() call.');
    }
}
