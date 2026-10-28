<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin REST client for Interakt's public API (WhatsApp Business through Meta).
 * Protocol only — which template to send and to whom is decided elsewhere.
 *
 * Auth is `Authorization: Basic <API key>`: the key from Interakt → Settings →
 * Developer Setting is already encoded, so it goes in as it is.
 */
class InteraktClient
{
    public const BASE_URL = 'https://api.interakt.ai/v1/public';

    /** Interakt's create-template call names languages in words. */
    public const LANGUAGES = ['en' => 'English', 'hi' => 'Hindi'];

    public function __construct(private readonly WhatsAppSettings $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->apiKey());
    }

    /**
     * Send an approved template. Returns Interakt's message id.
     *
     * @param  list<string>  $headerValues  header variable, or the media URL for an image header
     * @param  list<string>  $bodyValues
     * @param  array<string, list<string>>  $buttonValues  button index => values
     */
    public function sendTemplate(
        string $phone,
        string $name,
        string $language,
        array $headerValues = [],
        array $bodyValues = [],
        array $buttonValues = [],
        ?string $callbackData = null,
    ): string {
        $template = array_filter([
            'name' => $name,
            'languageCode' => $language,
            'headerValues' => $headerValues ?: null,
            'bodyValues' => $bodyValues ?: null,
            'buttonValues' => $buttonValues ?: null,
        ], fn ($v) => $v !== null);

        $payload = array_filter([
            'countryCode' => $this->settings->countryCode(),
            'phoneNumber' => $phone,
            'callbackData' => $callbackData,
            'type' => 'Template',
            'template' => $template,
        ], fn ($v) => $v !== null);

        $data = $this->ok($this->http()->post('/message/', $payload), 'send the message');

        return (string) ($data['id'] ?? '');
    }

    /**
     * Submit a new template for Meta's approval (Marketing or Utility only;
     * Authentication templates are made in Interakt itself).
     *
     * @param  array<string, mixed>  $payload  Interakt's create-template body
     * @return array<string, mixed> the created template (approval_status WAITING)
     */
    public function createTemplate(array $payload): array
    {
        $data = $this->ok($this->http()->post('/track/templates/', $payload), 'create the template');

        return (array) ($data['data'] ?? []);
    }

    /**
     * Upload a header image so a template can be created with it.
     *
     * @return array{file_url: string, file_handle: string, file_name: string}
     */
    public function uploadMedia(UploadedFile $file): array
    {
        $response = $this->http()
            ->attach('uploadFile', (string) $file->get(), $file->getClientOriginalName())
            ->post('/track/files/upload_to_fb/?fileCategory=message_template_media');

        $data = (array) ($this->ok($response, 'upload the image')['data'] ?? []);

        return [
            'file_url' => (string) ($data['file_url'] ?? ''),
            'file_handle' => (string) ($data['file_handle'] ?? ''),
            'file_name' => (string) ($data['file_name'] ?? $file->getClientOriginalName()),
        ];
    }

    /**
     * Every template on the account, page by page.
     *
     * @return list<array<string, mixed>>
     */
    public function templates(): array
    {
        $all = [];
        $offset = 0;
        for ($page = 0; $page < 50; $page++) {
            $data = $this->ok($this->http()->get('/track/organization/templates', [
                'offset' => $offset,
                'autosubmitted_for' => 'all',
                'approval_status' => 'ALL',
                'language' => 'all',
            ]), 'load the templates');

            $batch = array_values((array) ($data['results']['templates'] ?? []));
            array_push($all, ...$batch);
            if (empty($data['has_next']) || $batch === []) {
                break;
            }
            $offset += count($batch);
        }

        return $all;
    }

    private function http(): PendingRequest
    {
        $key = $this->settings->apiKey();
        if ($key === '') {
            throw new RuntimeException('WhatsApp is not connected. Add the Interakt API key first.');
        }

        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['Authorization' => 'Basic '.$key])
            ->acceptJson()
            ->timeout(20);
    }

    /** @return array<string, mixed> */
    private function ok(Response $response, string $what): array
    {
        $data = (array) $response->json();
        if ($response->successful() && ($data['result'] ?? true) !== false) {
            return $data;
        }

        $message = $data['message'] ?? $data['error'] ?? $data['detail'] ?? null;
        if (is_array($message)) {
            $message = json_encode($message);
        }
        if ($response->status() === 401 || $response->status() === 403) {
            $message = 'Interakt refused the API key. Check it in Interakt → Settings → Developer Setting.';
        }

        throw new RuntimeException('Could not '.$what.': '.($message ?: 'Interakt answered '.$response->status()));
    }
}
