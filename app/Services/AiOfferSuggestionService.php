<?php

namespace App\Services;

use App\Enums\OfferType;
use App\Models\Business;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI offer suggestions via the Claude Messages API (Phase 2). Best-effort: when
 * no API key is configured, or the call fails for any reason, it returns an
 * empty list so the rule-based suggestions still carry the feature. Uses the
 * default model (claude-opus-4-8) unless overridden in config/services.php.
 */
class AiOfferSuggestionService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Whether an Anthropic API key is present. */
    public function isConfigured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * Ask Claude for tailored offer ideas for this business.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggest(Business $business): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(20)->post(self::ENDPOINT, [
                'model' => (string) config('services.anthropic.model', 'claude-opus-4-8'),
                'max_tokens' => 1024,
                'system' => $this->systemPrompt(),
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
                'messages' => [['role' => 'user', 'content' => $this->userPrompt($business)]],
            ]);

            if (! $response->successful()) {
                return [];
            }

            $text = (string) data_get($response->json(), 'content.0.text', '');
            $parsed = json_decode($text, true);
            $items = is_array($parsed) ? Arr::get($parsed, 'suggestions', []) : [];

            return $this->normalize(is_array($items) ? $items : []);
        } catch (Throwable $e) {
            Log::warning('AI offer suggestions failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function systemPrompt(): string
    {
        return 'You are a marketing advisor for small businesses on Listee, a QR-code rewards app '
            .'where customers spin a wheel to win offers. Suggest 3 practical, specific offers this '
            .'business could run as spinner rewards. Each offer must be realistic for a small local '
            .'business and phrased as a short, appealing title. Return only the structured JSON.';
    }

    private function userPrompt(Business $business): string
    {
        $stats = app(AnalyticsService::class)->forBusiness($business, 30)['summary'];

        return "Business: {$business->name}\n"
            .'Category: '.($business->category?->name ?? 'General')."\n"
            .'Last 30 days — visits: '.$stats['visits']['value']
            .', spins: '.$stats['spins']['value']
            .', rewards won: '.$stats['rewards']['value']
            .', redemption rate: '.$stats['redemptionRate']."%\n"
            .'Suggest 3 offers tailored to this business.';
    }

    /**
     * JSON schema constraining the response to typed offer suggestions.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => array_map(fn (OfferType $t) => $t->value, OfferType::cases())],
                            'rewardValue' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'type', 'rewardValue', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['suggestions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Keep only well-formed suggestions with a valid offer type.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $items): array
    {
        $validTypes = array_map(fn (OfferType $t) => $t->value, OfferType::cases());
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item) || empty($item['title']) || empty($item['type'])) {
                continue;
            }
            if (! in_array($item['type'], $validTypes, true)) {
                continue;
            }
            $out[] = [
                'title' => (string) $item['title'],
                'type' => (string) $item['type'],
                'rewardValue' => (string) ($item['rewardValue'] ?? ''),
                'reason' => (string) ($item['reason'] ?? ''),
                'source' => 'ai',
            ];
        }

        return $out;
    }
}
