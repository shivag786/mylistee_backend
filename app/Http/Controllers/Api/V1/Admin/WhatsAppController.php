<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\AuditService;
use App\Services\WhatsApp\InteraktClient;
use App\Services\WhatsApp\WhatsAppSender;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppTemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Admin → WhatsApp: connect Interakt, make and sync templates, choose which
 * template each automatic message uses, and see every message sent.
 */
class WhatsAppController extends Controller
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppTemplateService $templates,
        private readonly InteraktClient $client,
        private readonly WhatsAppSender $sender,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/whatsapp — settings, templates and the reference lists the screen needs. */
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->state(), 'WhatsApp settings.');
    }

    /** PUT /admin/whatsapp */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'apiKey' => ['nullable', 'string', 'max:500'],
            'clearApiKey' => ['sometimes', 'boolean'],
            'webhookSecret' => ['nullable', 'string', 'max:255'],
            'countryCode' => ['sometimes', 'string', 'regex:/^\+\d{1,4}$/'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['nullable', 'string', Rule::exists('whatsapp_templates', 'uuid')],
        ]);

        if (! empty($data['enabled']) && ! filled($data['apiKey'] ?? null) && ! $this->settings->masked()['apiKeySet']) {
            return ApiResponse::error('Add the Interakt API key before switching WhatsApp on.', status: 422);
        }

        $this->settings->update($data);
        $this->audit->log($request->user(), 'settings.whatsapp', null, 'Updated WhatsApp settings');

        return ApiResponse::success($this->state(), 'WhatsApp settings saved.');
    }

    /** POST /admin/whatsapp/test-connection — does Interakt accept the key? */
    public function testConnection(): JsonResponse
    {
        try {
            $count = count($this->client->templates());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success(['templates' => $count], "Connected to Interakt — {$count} templates on the account.");
    }

    /** POST /admin/whatsapp/sync — pull templates and their approval status from Interakt. */
    public function sync(Request $request): JsonResponse
    {
        try {
            $result = $this->templates->sync();
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }
        $this->audit->log($request->user(), 'whatsapp.sync', null, "Synced WhatsApp templates (+{$result['added']})");

        return ApiResponse::success($this->state() + ['sync' => $result], "Synced: {$result['added']} new, {$result['updated']} updated.");
    }

    /** POST /admin/whatsapp/templates — submit a new template for approval. */
    public function storeTemplate(Request $request): JsonResponse
    {
        // Sent as multipart when there is a header image: nested fields come as JSON.
        foreach (['bodyVars', 'buttons', 'headerVar'] as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => json_decode((string) $request->input($key), true)]);
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'displayName' => ['nullable', 'string', 'max:120'],
            'language' => ['sometimes', Rule::in(array_keys(InteraktClient::LANGUAGES))],
            'category' => ['required', Rule::in(['MARKETING', 'UTILITY'])],
            'headerFormat' => ['nullable', Rule::in(['TEXT', 'IMAGE'])],
            'header' => ['nullable', 'required_if:headerFormat,TEXT', 'string', 'max:60'],
            'headerVar' => ['nullable', 'array'],
            'image' => ['nullable', 'required_if:headerFormat,IMAGE', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'body' => ['required', 'string', 'max:1024'],
            'bodyVars' => ['nullable', 'array', 'max:20'],
            'bodyVars.*.source' => ['required', Rule::in(array_keys(WhatsAppTemplateService::SOURCES))],
            'bodyVars.*.label' => ['nullable', 'string', 'max:40'],
            'bodyVars.*.value' => ['nullable', 'string', 'max:500'],
            'bodyVars.*.sample' => ['nullable', 'string', 'max:120'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:3'],
            'buttons.*.type' => ['required', Rule::in(['URL', 'PHONE_NUMBER', 'QUICK_REPLY'])],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            // A full https link; may end in {{1}} for the part that changes (the shop's page).
            'buttons.*.url' => ['nullable', 'required_if:buttons.*.type,URL', 'string', 'max:2000', 'regex:#^https://[^\s{}]+(\{\{1\}\})?$#'],
            'buttons.*.phone_number' => ['nullable', 'required_if:buttons.*.type,PHONE_NUMBER', 'regex:/^\+\d{8,15}$/'],
            'buttons.*.var' => ['nullable', 'array'],
            'forShops' => ['sometimes', 'boolean'],
        ]);

        try {
            $template = $this->templates->create($data, $request->file('image'));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }
        $this->audit->log($request->user(), 'whatsapp.template.create', null, "Submitted WhatsApp template {$template->name}");

        return ApiResponse::success($this->templates->present($template), 'Sent to WhatsApp for approval. It usually takes a few minutes to a day.', 201);
    }

    /** PATCH /admin/whatsapp/templates/{uuid} — who may use it and how blanks are filled. */
    public function updateTemplate(Request $request, string $uuid): JsonResponse
    {
        $template = WhatsAppTemplate::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'displayName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'forShops' => ['sometimes', 'boolean'],
            'variables' => ['sometimes', 'array'],
            'variables.header' => ['nullable', 'array'],
            'variables.body' => ['present_with:variables', 'array'],
            'variables.body.*.source' => ['required', Rule::in(array_keys(WhatsAppTemplateService::SOURCES))],
            'variables.buttons' => ['nullable', 'array'],
        ]);

        $template = $this->templates->updateUsage($template, $data);
        $this->audit->log($request->user(), 'whatsapp.template.update', null, "Updated WhatsApp template {$template->name}");

        return ApiResponse::success($this->templates->present($template), 'Template saved.');
    }

    /** DELETE /admin/whatsapp/templates/{uuid} — remove from Listee (it stays in Interakt). */
    public function destroyTemplate(Request $request, string $uuid): JsonResponse
    {
        $template = WhatsAppTemplate::query()->where('uuid', $uuid)->firstOrFail();
        $events = array_filter($this->settings->all()['events'], fn ($id) => $id !== $template->uuid);
        $this->settings->update(['events' => $events]);
        $template->delete();
        $this->audit->log($request->user(), 'whatsapp.template.delete', null, "Removed WhatsApp template {$template->name}");

        return ApiResponse::success($this->state(), 'Template removed from Listee.');
    }

    /** POST /admin/whatsapp/templates/{uuid}/test — send it to a number with sample values. */
    public function testTemplate(Request $request, string $uuid): JsonResponse
    {
        $template = WhatsAppTemplate::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'values' => ['nullable', 'array'],
        ]);

        $context = collect(WhatsAppTemplateService::SOURCES)->map(fn ($s) => $s['sample'])->all();
        $typed = array_map('strval', array_filter((array) ($data['values'] ?? []), 'is_scalar'));
        foreach ($this->templates->ownerFields($template) as $field) {
            $typed[$field['key']] ??= $field['sample'] ?: $field['label'];
        }

        $message = $this->sender->send($template, $data['phone'], 'test', $context, $typed);
        if ($message->status === 'failed') {
            return ApiResponse::error($message->error ?: 'Could not send.', status: 422);
        }

        return ApiResponse::success(['id' => $message->uuid], 'Test message sent. Check WhatsApp on that phone.');
    }

    /** GET /admin/whatsapp/messages?status=&purpose=&search=&page= */
    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['queued', 'sent', 'delivered', 'read', 'failed'])],
            'purpose' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:20'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = WhatsAppMessage::query()
            ->with(['template:id,name,display_name', 'business:id,name'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['purpose'] ?? null, fn ($q, $p) => $q->where('purpose', $p))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where('phone', 'like', '%'.preg_replace('/\D/', '', $s).'%'))
            ->latest('id')
            ->paginate(30, page: (int) ($data['page'] ?? 1));

        return ApiResponse::success([
            'messages' => collect($page->items())->map(fn (WhatsAppMessage $m) => [
                'id' => $m->uuid,
                'phone' => $m->phone,
                'purpose' => $m->purpose,
                'status' => $m->status,
                'error' => $m->error,
                'template' => $m->template?->display_name ?: $m->template?->name,
                'business' => $m->business?->name,
                'createdAt' => $m->created_at?->toIso8601String(),
                'updatedAt' => $m->updated_at?->toIso8601String(),
            ])->all(),
            'page' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
        ], 'WhatsApp messages.');
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $monthStart = now(config('app.business_timezone'))->startOfMonth()->utc();
        $counts = WhatsAppMessage::query()
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return [
            'settings' => $this->settings->masked(),
            'webhookUrl' => url('/api/v1/webhooks/interakt'),
            'templates' => WhatsAppTemplate::query()->orderByRaw("status = 'APPROVED' desc")->latest('id')->get()
                ->map(fn (WhatsAppTemplate $t) => $this->templates->present($t))->all(),
            'events' => collect(WhatsAppSettings::EVENTS)->map(fn ($e, $key) => ['key' => $key] + $e)->values()->all(),
            'sources' => collect(WhatsAppTemplateService::SOURCES)->map(fn ($s, $key) => ['key' => $key] + $s)->values()->all(),
            'presets' => WhatsAppTemplateService::PRESETS,
            'languages' => InteraktClient::LANGUAGES,
            'month' => [
                'sent' => (int) $counts->only(['sent', 'delivered', 'read'])->sum(),
                'delivered' => (int) $counts->only(['delivered', 'read'])->sum(),
                'read' => (int) ($counts['read'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
            ],
        ];
    }
}
