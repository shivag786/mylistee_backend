<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp templates: made here and submitted to Interakt for Meta's approval,
 * or synced from the Interakt account (which is how Authentication / OTP
 * templates arrive — Interakt makes those itself).
 *
 * Each {{n}} in a template is filled from a "source" the admin picks: the
 * customer's name, the shop's name, an order number, the OTP… or `custom`,
 * which a shop owner types in when sending (their offer, discount, date), or
 * `fixed`, a value the admin sets once.
 */
class WhatsAppTemplateService
{
    /** Where a variable's value comes from. `for` = which messages can supply it. */
    public const SOURCES = [
        'customer_name' => ['label' => "Customer's name", 'for' => 'all', 'sample' => 'Priya'],
        'shop_name' => ['label' => 'Shop name', 'for' => 'all', 'sample' => 'Sharma Sweets'],
        'shop_link' => ['label' => 'Shop link', 'for' => 'all', 'sample' => 'https://listee.org/b/sharma-sweets'],
        'shop_slug' => ['label' => 'Shop link ending (for a URL button)', 'for' => 'all', 'sample' => 'sharma-sweets'],
        'shop_phone' => ['label' => 'Shop phone', 'for' => 'all', 'sample' => '9876543210'],
        'order_token' => ['label' => 'Order number', 'for' => 'order', 'sample' => 'A12'],
        'order_total' => ['label' => 'Order total', 'for' => 'order', 'sample' => '₹340'],
        'order_items' => ['label' => 'Items count', 'for' => 'order', 'sample' => '3 items'],
        'order_service' => ['label' => 'Order type', 'for' => 'order', 'sample' => 'Home delivery'],
        'otp_code' => ['label' => 'One-time code', 'for' => 'otp', 'sample' => '482913'],
        'custom' => ['label' => 'Shop owner types it', 'for' => 'shop', 'sample' => ''],
        'fixed' => ['label' => 'Fixed text (set here)', 'for' => 'all', 'sample' => ''],
        'shop_image' => ['label' => "Shop's cover photo", 'for' => 'image', 'sample' => ''],
        'template_image' => ['label' => 'Image used at approval', 'for' => 'image', 'sample' => ''],
    ];

    /**
     * Ready-made templates the admin can submit in one go. Marketing ones are
     * for shops (owner fills the offer); utility ones are Listee's own order
     * messages. Placeholders are numbered as WhatsApp wants: {{1}}, {{2}}…
     */
    public const PRESETS = [
        [
            'key' => 'shop_offer',
            'title' => 'Shop offer',
            'name' => 'listee_shop_offer',
            'category' => 'MARKETING',
            'forShops' => true,
            'header' => ['format' => 'TEXT', 'text' => 'Special offer at {{1}} 🎉', 'var' => ['source' => 'shop_name']],
            'body' => "Hi {{1}},\n\n{{2}} has a special offer for you:\n*{{3}}*\n\nValid till {{4}}. Visit us or order on Listee.",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'shop_name'],
                ['source' => 'custom', 'label' => 'Offer', 'sample' => 'Flat 20% off on all sweets'],
                ['source' => 'custom', 'label' => 'Valid till', 'sample' => '31 Oct'],
            ],
            'footer' => 'Reply STOP to stop offers',
            'buttons' => [['type' => 'URL', 'text' => 'See the offer', 'url' => 'https://listee.org/b/{{1}}', 'var' => ['source' => 'shop_slug']]],
        ],
        [
            'key' => 'shop_discount',
            'title' => 'Discount code',
            'name' => 'listee_shop_discount',
            'category' => 'MARKETING',
            'forShops' => true,
            'header' => null,
            'body' => "Hi {{1}} 👋\n\nGet *{{2}}* at {{3}}.\nUse code *{{4}}* on your next order.\n\nHurry, offer ends soon!",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'custom', 'label' => 'Discount', 'sample' => '15% off'],
                ['source' => 'shop_name'],
                ['source' => 'custom', 'label' => 'Code', 'sample' => 'DIWALI15'],
            ],
            'footer' => 'Reply STOP to stop offers',
            'buttons' => [['type' => 'URL', 'text' => 'Order now', 'url' => 'https://listee.org/b/{{1}}', 'var' => ['source' => 'shop_slug']]],
        ],
        [
            'key' => 'shop_new_arrival',
            'title' => 'New arrival',
            'name' => 'listee_new_arrival',
            'category' => 'MARKETING',
            'forShops' => true,
            'header' => null,
            'body' => "Hi {{1}},\n\nSomething new just arrived at {{2}}: *{{3}}* ✨\n\n{{4}}",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'shop_name'],
                ['source' => 'custom', 'label' => 'What is new', 'sample' => 'Kaju Katli with saffron'],
                ['source' => 'custom', 'label' => 'One line about it', 'sample' => 'Fresh today, limited pieces.'],
            ],
            'footer' => 'Reply STOP to stop offers',
            'buttons' => [['type' => 'URL', 'text' => 'Have a look', 'url' => 'https://listee.org/b/{{1}}', 'var' => ['source' => 'shop_slug']]],
        ],
        [
            'key' => 'shop_festival',
            'title' => 'Festival greeting',
            'name' => 'listee_festival_offer',
            'category' => 'MARKETING',
            'forShops' => true,
            'header' => null,
            'body' => "Happy {{1}}, {{2}}! 🪔\n\nCelebrate with {{3}} — {{4}}.\n\nWishing you and your family joy.",
            'bodyVars' => [
                ['source' => 'custom', 'label' => 'Festival', 'sample' => 'Diwali'],
                ['source' => 'customer_name'],
                ['source' => 'shop_name'],
                ['source' => 'custom', 'label' => 'Festival offer', 'sample' => 'gift boxes from ₹299'],
            ],
            'footer' => 'Reply STOP to stop offers',
            'buttons' => [['type' => 'URL', 'text' => 'Visit shop', 'url' => 'https://listee.org/b/{{1}}', 'var' => ['source' => 'shop_slug']]],
        ],
        [
            'key' => 'shop_miss_you',
            'title' => 'We miss you',
            'name' => 'listee_we_miss_you',
            'category' => 'MARKETING',
            'forShops' => true,
            'header' => null,
            'body' => "Hi {{1}}, we miss you at {{2}}! 💛\n\nCome back and enjoy *{{3}}* on your next visit.",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'shop_name'],
                ['source' => 'custom', 'label' => 'Welcome-back offer', 'sample' => 'a free dessert'],
            ],
            'footer' => 'Reply STOP to stop offers',
            'buttons' => [['type' => 'URL', 'text' => 'Order again', 'url' => 'https://listee.org/b/{{1}}', 'var' => ['source' => 'shop_slug']]],
        ],
        [
            'key' => 'order_new_owner',
            'title' => 'New order (to shop)',
            'name' => 'listee_new_order_shop',
            'category' => 'UTILITY',
            'forShops' => false,
            'event' => 'order_placed_owner',
            'header' => null,
            'body' => "🛎️ New order *{{1}}* at {{2}}\n\n{{3}} · {{4}} · {{5}}\nCustomer: {{6}}\n\nOpen Listee Business to accept it.",
            'bodyVars' => [
                ['source' => 'order_token'],
                ['source' => 'shop_name'],
                ['source' => 'order_service'],
                ['source' => 'order_total'],
                ['source' => 'order_items'],
                ['source' => 'customer_name'],
            ],
            'footer' => null,
            'buttons' => [],
        ],
        [
            'key' => 'order_placed',
            'title' => 'Order placed (to customer)',
            'name' => 'listee_order_placed',
            'category' => 'UTILITY',
            'forShops' => false,
            'event' => 'order_placed',
            'header' => null,
            'body' => "Hi {{1}}, your order *{{2}}* at {{3}} is placed ✅\n\nTotal: {{4}}. We will tell you when the shop accepts it.",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'order_token'],
                ['source' => 'shop_name'],
                ['source' => 'order_total'],
            ],
            'footer' => null,
            'buttons' => [],
        ],
        [
            'key' => 'order_accepted',
            'title' => 'Order accepted',
            'name' => 'listee_order_accepted',
            'category' => 'UTILITY',
            'forShops' => false,
            'event' => 'order_accepted',
            'header' => null,
            'body' => "Hi {{1}}, {{2}} accepted your order *{{3}}* and is preparing it 👨‍🍳",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'shop_name'],
                ['source' => 'order_token'],
            ],
            'footer' => null,
            'buttons' => [],
        ],
        [
            'key' => 'order_ready',
            'title' => 'Order ready',
            'name' => 'listee_order_ready',
            'category' => 'UTILITY',
            'forShops' => false,
            'event' => 'order_ready',
            'header' => null,
            'body' => "Hi {{1}}, your order *{{2}}* at {{3}} is ready 🍽️\n\n{{4}}",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'order_token'],
                ['source' => 'shop_name'],
                ['source' => 'fixed', 'value' => 'Please collect it at the counter, or it is on its way if you chose delivery.', 'sample' => 'Please collect it at the counter.'],
            ],
            'footer' => null,
            'buttons' => [],
        ],
        [
            'key' => 'order_completed',
            'title' => 'Order completed',
            'name' => 'listee_order_completed',
            'category' => 'UTILITY',
            'forShops' => false,
            'event' => 'order_completed',
            'header' => null,
            'body' => "Thank you {{1}}! Your order *{{2}}* at {{3}} is complete ✅",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'order_token'],
                ['source' => 'shop_name'],
            ],
            'footer' => null,
            'buttons' => [],
        ],
        [
            'key' => 'order_cancelled',
            'title' => 'Order cancelled',
            'name' => 'listee_order_cancelled',
            'category' => 'UTILITY',
            'forShops' => false,
            'event' => 'order_cancelled',
            'header' => null,
            'body' => "Hi {{1}}, your order *{{2}}* at {{3}} was cancelled. Any online payment is refunded to you.",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'order_token'],
                ['source' => 'shop_name'],
            ],
            'footer' => null,
            'buttons' => [],
        ],
    ];

    public function __construct(private readonly InteraktClient $client) {}

    /**
     * Submit a template to Interakt for approval and keep it here.
     *
     * @param  array<string, mixed>  $data  validated admin input (see WhatsAppController::storeTemplate)
     */
    public function create(array $data, ?UploadedFile $image = null): WhatsAppTemplate
    {
        $name = Str::of((string) $data['name'])->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->limit(100, '')->value();
        $language = (string) ($data['language'] ?? 'en');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Give the template a name (letters, numbers, underscores).']]);
        }
        if (WhatsAppTemplate::query()->where('name', $name)->where('language', $language)->exists()) {
            throw ValidationException::withMessages(['name' => ["A template called {$name} already exists. Pick another name."]]);
        }

        $category = strtoupper((string) $data['category']);
        $headerFormat = $data['headerFormat'] ?? null;
        $body = trim((string) $data['body']);
        $bodyVars = array_values((array) ($data['bodyVars'] ?? []));
        $buttons = array_values((array) ($data['buttons'] ?? []));

        $this->checkPlaceholders($body, count($bodyVars), 'body');
        if ($headerFormat === 'TEXT') {
            $this->checkPlaceholders((string) ($data['header'] ?? ''), WhatsAppTemplate::countVars($data['header'] ?? '') > 0 ? 1 : 0, 'header');
        }

        $payload = [
            'display_name' => $name,
            'language' => InteraktClient::LANGUAGES[$language] ?? 'English',
            'category' => $category === 'MARKETING' ? 'Marketing' : 'Utility',
            'header_format' => $headerFormat,
            'body' => $body,
            'body_text' => array_map(fn ($v) => $this->sample($v), $bodyVars),
        ];
        if (filled($data['footer'] ?? null)) {
            $payload['footer'] = (string) $data['footer'];
        }

        $headerVar = null;
        $mediaUrl = null;
        if ($headerFormat === 'TEXT') {
            $payload['header'] = (string) $data['header'];
            if (WhatsAppTemplate::countVars($data['header']) > 0) {
                $headerVar = (array) ($data['headerVar'] ?? ['source' => 'custom', 'label' => 'Header']);
                $payload['header_text'] = [$this->sample($headerVar)];
            }
        } elseif ($headerFormat === 'IMAGE') {
            if ($image === null) {
                throw ValidationException::withMessages(['image' => ['Add the header image.']]);
            }
            $file = $this->client->uploadMedia($image);
            $payload['header_handle'] = [$file['file_handle']];
            $payload['header_handle_file_url'] = $file['file_url'];
            $payload['header_handle_file_name'] = $file['file_name'];
            $mediaUrl = $file['file_url'];
            $headerVar = ['source' => (string) ($data['headerVar']['source'] ?? 'template_image')];
        }

        $buttonVars = [];
        if ($buttons !== []) {
            $quick = collect($buttons)->every(fn ($b) => ($b['type'] ?? '') === 'QUICK_REPLY');
            $payload['button_type'] = $quick ? 'Quick Replies' : 'Call To Action';
            $payload['buttons'] = array_map(fn ($b) => array_filter([
                'type' => $b['type'],
                'text' => $b['text'],
                'url' => $b['type'] === 'URL' ? $b['url'] : null,
                'phone_number' => $b['type'] === 'PHONE_NUMBER' ? $b['phone_number'] : null,
            ]), $buttons);
            foreach ($buttons as $i => $b) {
                if (($b['type'] ?? '') === 'URL' && WhatsAppTemplate::countVars($b['url'] ?? '') > 0) {
                    $buttonVars[(string) $i] = (array) ($b['var'] ?? ['source' => 'shop_slug']);
                    // Meta reviews a dynamic URL with one full example.
                    $payload['button_text'] = str_replace('{{1}}', $this->sample($buttonVars[(string) $i]), (string) $b['url']);
                }
            }
        }

        $created = $this->client->createTemplate($payload);

        return WhatsAppTemplate::create([
            'name' => (string) ($created['name'] ?? $name),
            'display_name' => (string) ($data['displayName'] ?? $created['display_name'] ?? $name),
            'language' => (string) ($created['language'] ?? $language),
            'category' => strtoupper((string) ($created['category'] ?? $category)),
            'header_format' => $headerFormat,
            'header' => $headerFormat === 'TEXT' ? (string) $data['header'] : null,
            'header_media_url' => $mediaUrl,
            'body' => $body,
            'footer' => $data['footer'] ?? null,
            'buttons' => array_map(fn ($b) => Arr::except($b, ['var']), $buttons),
            'samples' => $payload['body_text'],
            'variables' => [
                'header' => $headerVar,
                'body' => array_map(fn ($v) => $this->cleanVar($v), $bodyVars),
                'buttons' => (object) array_map(fn ($v) => $this->cleanVar($v), $buttonVars),
            ],
            'for_shops' => $category === 'MARKETING' && ! empty($data['forShops']),
            'status' => strtoupper((string) ($created['approval_status'] ?? 'WAITING')),
            'interakt_id' => $created['id'] ?? null,
            'wa_template_id' => isset($created['wa_template_id']) ? (string) $created['wa_template_id'] : null,
            'source' => 'listee',
            'synced_at' => now(),
        ]);
    }

    /**
     * Pull every template from Interakt: new ones appear here (OTP templates
     * included), and approval status / content stay current. How variables
     * are filled is kept unless the template's placeholders changed.
     *
     * @return array{added: int, updated: int}
     */
    public function sync(): array
    {
        $added = 0;
        $updated = 0;

        foreach ($this->client->templates() as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $language = (string) ($row['language'] ?? 'en');
            $template = WhatsAppTemplate::query()
                ->when(! empty($row['id']), fn ($q) => $q->where('interakt_id', $row['id'])->orWhere(fn ($w) => $w->where('name', $name)->where('language', $language)))
                ->when(empty($row['id']), fn ($q) => $q->where('name', $name)->where('language', $language))
                ->first();

            $buttons = $this->decodeButtons($row['buttons'] ?? null);
            $headerFormat = filled($row['header_format'] ?? null) ? strtoupper((string) $row['header_format']) : null;
            $fields = [
                'name' => $name,
                'language' => $language,
                'category' => strtoupper((string) ($row['category'] ?? 'UTILITY')),
                'header_format' => $headerFormat,
                'header' => $row['header'] ?? null,
                'header_media_url' => $row['header_handle_file_url'] ?? $template?->header_media_url,
                'body' => (string) ($row['body'] ?? ''),
                'footer' => $row['footer'] ?? null,
                'buttons' => $buttons,
                'status' => strtoupper((string) ($row['approval_status'] ?? 'WAITING')),
                'interakt_id' => $row['id'] ?? $template?->interakt_id,
                'wa_template_id' => isset($row['wa_template_id']) ? (string) $row['wa_template_id'] : $template?->wa_template_id,
                'synced_at' => now(),
            ];

            if ($template === null) {
                $fields['display_name'] = $row['display_name'] ?? $name;
                $fields['source'] = 'interakt';
                $fields['variables'] = $this->defaultVariables($fields);
                WhatsAppTemplate::create($fields);
                $added++;

                continue;
            }

            $template->fill($fields);
            if (! $this->variablesFit($template)) {
                $template->variables = $this->defaultVariables($fields);
            }
            if ($template->status === WhatsAppTemplate::APPROVED) {
                $template->rejection_reason = null;
            }
            $template->save();
            $updated++;
        }

        return compact('added', 'updated');
    }

    /**
     * Admin changes how a template is used: who may send it and how each
     * variable is filled. The text itself can't change once submitted — Meta
     * approved those exact words.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateUsage(WhatsAppTemplate $template, array $data): WhatsAppTemplate
    {
        if (array_key_exists('displayName', $data) && filled($data['displayName'])) {
            $template->display_name = (string) $data['displayName'];
        }
        if (array_key_exists('forShops', $data)) {
            $template->for_shops = (bool) $data['forShops'] && $template->category === 'MARKETING';
        }
        if (array_key_exists('variables', $data)) {
            $vars = (array) $data['variables'];
            $next = [
                'header' => isset($vars['header']) && is_array($vars['header']) ? $this->cleanVar($vars['header']) : null,
                'body' => array_map(fn ($v) => $this->cleanVar((array) $v), array_values((array) ($vars['body'] ?? []))),
                'buttons' => (object) array_map(fn ($v) => $this->cleanVar((array) $v), (array) ($vars['buttons'] ?? [])),
            ];
            if (count($next['body']) !== WhatsAppTemplate::countVars($template->body)) {
                throw ValidationException::withMessages(['variables' => ['Set a value for each {{n}} in the message.']]);
            }
            $template->variables = $next;
        }
        $template->save();

        return $template;
    }

    /**
     * The variables a shop owner types when sending this template.
     *
     * @return list<array{key: string, label: string, sample: string}>
     */
    public function ownerFields(WhatsAppTemplate $template): array
    {
        $fields = [];
        $vars = (array) $template->variables;
        if (($vars['header']['source'] ?? null) === 'custom') {
            $fields[] = ['key' => 'header', 'label' => (string) ($vars['header']['label'] ?? 'Heading'), 'sample' => (string) ($vars['header']['sample'] ?? '')];
        }
        foreach (array_values((array) ($vars['body'] ?? [])) as $i => $var) {
            if (($var['source'] ?? null) === 'custom') {
                $fields[] = ['key' => "body.{$i}", 'label' => (string) ($var['label'] ?? 'Value '.($i + 1)), 'sample' => (string) ($var['sample'] ?? '')];
            }
        }
        foreach ((array) ($vars['buttons'] ?? []) as $index => $var) {
            if (($var['source'] ?? null) === 'custom') {
                $fields[] = ['key' => "button.{$index}", 'label' => (string) ($var['label'] ?? 'Link'), 'sample' => (string) ($var['sample'] ?? '')];
            }
        }

        return $fields;
    }

    /**
     * Fill the template's variables: the values Interakt's send call takes.
     *
     * @param  array<string, string|null>  $context  source => value (customer_name, order_token…)
     * @param  array<string, string>  $typed  owner-typed values keyed "header" / "body.0" / "button.1"
     * @return array{header: list<string>, body: list<string>, buttons: array<string, list<string>>}
     */
    public function resolve(WhatsAppTemplate $template, array $context, array $typed = []): array
    {
        $vars = (array) $template->variables;
        $pick = function (?array $var, string $key) use ($context, $typed, $template): string {
            $source = $var['source'] ?? 'fixed';
            $value = match ($source) {
                'custom' => $typed[$key] ?? null,
                'fixed' => $var['value'] ?? null,
                'template_image' => $template->header_media_url,
                default => $context[$source] ?? null,
            };
            $value = trim((string) $value);

            // WhatsApp refuses an empty parameter; never let one through.
            return $value !== '' ? Str::limit($value, 900, '…') : ($source === 'customer_name' ? 'there' : '-');
        };

        $header = [];
        if ($template->header_format === 'TEXT' && WhatsAppTemplate::countVars($template->header) > 0) {
            $header[] = $pick($vars['header'] ?? null, 'header');
        } elseif (in_array($template->header_format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $source = $vars['header']['source'] ?? 'template_image';
            $url = $source === 'shop_image' ? ($context['shop_image'] ?? null) : null;
            $header[] = $url ?: (string) $template->header_media_url;
        }

        $body = [];
        foreach (array_values((array) ($vars['body'] ?? [])) as $i => $var) {
            $body[] = $pick((array) $var, "body.{$i}");
        }

        $buttons = [];
        foreach ((array) ($vars['buttons'] ?? []) as $index => $var) {
            $buttons[(string) $index] = [$pick((array) $var, "button.{$index}")];
        }

        return ['header' => $header, 'body' => $body, 'buttons' => $buttons];
    }

    /** @return array<string, mixed> */
    public function present(WhatsAppTemplate $template): array
    {
        return [
            'id' => $template->uuid,
            'name' => $template->name,
            'displayName' => $template->display_name ?: $template->name,
            'language' => $template->language,
            'category' => $template->category,
            'headerFormat' => $template->header_format,
            'header' => $template->header,
            'headerMediaUrl' => $template->header_media_url,
            'body' => $template->body,
            'footer' => $template->footer,
            'buttons' => $template->buttons ?? [],
            'variables' => [
                'header' => $template->variables['header'] ?? null,
                'body' => array_values((array) ($template->variables['body'] ?? [])),
                'buttons' => (object) ($template->variables['buttons'] ?? []),
            ],
            'forShops' => $template->for_shops,
            'status' => $template->status,
            'rejectionReason' => $template->rejection_reason,
            'source' => $template->source,
            'syncedAt' => $template->synced_at?->toIso8601String(),
            'createdAt' => $template->created_at?->toIso8601String(),
        ];
    }

    /** The placeholders must run {{1}}..{{n}} with a value for each. */
    private function checkPlaceholders(string $text, int $given, string $field): void
    {
        $count = WhatsAppTemplate::countVars($text);
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m);
        $numbers = array_map('intval', array_unique($m[1]));
        sort($numbers);
        if ($numbers !== [] && $numbers !== range(1, $count)) {
            throw ValidationException::withMessages([$field => ['Number the blanks in order: {{1}}, {{2}}, {{3}}…']]);
        }
        if ($count !== $given) {
            throw ValidationException::withMessages([$field => ["The {$field} has {$count} blanks; set how each one is filled."]]);
        }
        if ($field === 'body' && preg_match('/^\s*\{\{|\}\}\s*$/', $text)) {
            throw ValidationException::withMessages([$field => ['WhatsApp does not allow the message to start or end with a {{blank}}. Add a word or full stop.']]);
        }
    }

    /** A sample value Meta can read while reviewing the template. */
    private function sample(array $var): string
    {
        $source = (string) ($var['source'] ?? 'custom');
        $sample = trim((string) ($var['sample'] ?? $var['value'] ?? ''));

        return $sample !== '' ? $sample : ((self::SOURCES[$source]['sample'] ?? '') ?: 'Sample');
    }

    /** @return array{source: string, label?: string, value?: string, sample?: string} */
    private function cleanVar(array $var): array
    {
        $source = array_key_exists((string) ($var['source'] ?? ''), self::SOURCES) ? (string) $var['source'] : 'custom';

        return array_filter([
            'source' => $source,
            'label' => isset($var['label']) ? Str::limit((string) $var['label'], 40, '') : null,
            'value' => $source === 'fixed' ? Str::limit((string) ($var['value'] ?? ''), 500, '') : null,
            'sample' => isset($var['sample']) ? Str::limit((string) $var['sample'], 120, '') : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Interakt returns buttons as JSON inside a JSON string (sometimes twice). */
    private function decodeButtons(mixed $raw): array
    {
        for ($i = 0; $i < 3 && is_string($raw); $i++) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    /** A sensible first mapping for a template that just arrived. */
    private function defaultVariables(array $t): array
    {
        $isOtp = ($t['category'] ?? '') === 'AUTHENTICATION';
        $body = [];
        for ($i = 0, $n = WhatsAppTemplate::countVars($t['body'] ?? ''); $i < $n; $i++) {
            $body[] = $isOtp ? ['source' => 'otp_code'] : ['source' => 'custom', 'label' => 'Value '.($i + 1)];
        }

        $header = null;
        if (($t['header_format'] ?? null) === 'TEXT' && WhatsAppTemplate::countVars($t['header'] ?? '') > 0) {
            $header = ['source' => 'custom', 'label' => 'Heading'];
        } elseif (in_array($t['header_format'] ?? null, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $header = ['source' => 'template_image'];
        }

        $buttons = [];
        foreach ((array) ($t['buttons'] ?? []) as $i => $b) {
            $type = strtoupper((string) ($b['type'] ?? ''));
            if ($type === 'OTP' || ($isOtp && in_array($type, ['COPY_CODE', 'ONE_TAP'], true))) {
                $buttons[(string) $i] = ['source' => 'otp_code'];
            } elseif ($type === 'URL' && WhatsAppTemplate::countVars($b['url'] ?? '') > 0) {
                $buttons[(string) $i] = ['source' => 'shop_slug'];
            }
        }

        return ['header' => $header, 'body' => $body, 'buttons' => (object) $buttons];
    }

    /** Does the stored mapping still match the template's placeholders? */
    private function variablesFit(WhatsAppTemplate $template): bool
    {
        $vars = (array) $template->variables;

        return count((array) ($vars['body'] ?? [])) === WhatsAppTemplate::countVars($template->body);
    }
}
