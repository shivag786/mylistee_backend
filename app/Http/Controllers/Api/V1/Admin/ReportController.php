<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV report exports (document/phase/14 §Reports). Streamed with a DB cursor so
 * large exports don't exhaust memory. Bypasses the JSON envelope — it's a file.
 */
class ReportController extends Controller
{
    private const TYPES = ['businesses', 'customers', 'offers', 'invoices', 'revenue'];

    public function __construct(private readonly AuditService $audit) {}

    /** GET /admin/reports/{type} */
    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $this->audit->log($request->user(), 'report.export', null, "Exported {$type} report");

        $filename = "listee-{$type}-".now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($type): void {
            $out = fopen('php://output', 'w');
            match ($type) {
                'businesses' => $this->businesses($out),
                'customers' => $this->customers($out),
                'offers' => $this->offers($out),
                'invoices' => $this->invoices($out),
                'revenue' => $this->revenue($out),
            };
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @param  resource  $out */
    private function businesses($out): void
    {
        fputcsv($out, ['Name', 'Owner', 'Email', 'Category', 'Status', 'Verified', 'Visits', 'Spins', 'Rating', 'Created']);
        Business::with(['owner:id,name,email', 'category:id,name'])->cursor()->each(function (Business $b) use ($out): void {
            fputcsv($out, [
                $b->name,
                $b->owner?->name,
                $b->owner?->email,
                $b->category?->name,
                $b->status instanceof \App\Enums\BusinessStatus ? $b->status->value : $b->status,
                $b->verified ? 'yes' : 'no',
                $b->total_visits,
                $b->total_spins,
                $b->average_rating,
                $b->created_at?->toDateString(),
            ]);
        });
    }

    /** @param  resource  $out */
    private function customers($out): void
    {
        fputcsv($out, ['Name', 'Email', 'Status', 'Spins', 'Rewards', 'Joined', 'Last login']);
        User::where('role', UserRole::Customer->value)->withCount(['spins', 'rewards'])->cursor()
            ->each(function (User $u) use ($out): void {
                fputcsv($out, [
                    $u->name,
                    $u->email,
                    $u->status instanceof \App\Enums\UserStatus ? $u->status->value : $u->status,
                    $u->spins_count,
                    $u->rewards_count,
                    $u->created_at?->toDateString(),
                    $u->last_login_at?->toDateString(),
                ]);
            });
    }

    /** @param  resource  $out */
    private function offers($out): void
    {
        fputcsv($out, ['Title', 'Business', 'Type', 'Status', 'Starts', 'Ends', 'Created']);
        Offer::with('business:id,name')->cursor()->each(function (Offer $o) use ($out): void {
            fputcsv($out, [
                $o->title,
                $o->business?->name,
                $o->type instanceof \App\Enums\OfferType ? $o->type->value : $o->type,
                $o->effectiveStatus(),
                $o->starts_at?->toDateString(),
                $o->ends_at?->toDateString(),
                $o->created_at?->toDateString(),
            ]);
        });
    }

    /** @param  resource  $out — one row per subscription (the admin Revenue page). */
    private function revenue($out): void
    {
        fputcsv($out, ['Business', 'Plan', 'Price', 'Currency', 'Cycle', 'Status', 'Auto-renew', 'Total paid', 'Started', 'Renews/Ends']);
        \App\Models\Subscription::with(['business:id,name', 'plan:id,name'])
            ->withSum(
                ['invoices as total_paid' => fn ($q) => $q->where('status', \App\Enums\InvoiceStatus::Paid->value)],
                'amount',
            )
            ->cursor()
            ->each(function (\App\Models\Subscription $s) use ($out): void {
                fputcsv($out, [
                    $s->business?->name,
                    $s->plan?->name ?? $s->plan_name,
                    $s->price,
                    $s->currency,
                    $s->interval,
                    $s->status instanceof \App\Enums\SubscriptionStatus ? $s->status->value : $s->status,
                    $s->auto_renew ? 'yes' : 'no',
                    $s->total_paid ?? 0,
                    $s->starts_at?->toDateString(),
                    $s->ends_at?->toDateString(),
                ]);
            });
    }

    /** @param  resource  $out */
    private function invoices($out): void
    {
        fputcsv($out, ['Number', 'Business', 'Plan', 'Amount', 'Currency', 'Status', 'Issued']);
        Invoice::with('business:id,name')->cursor()->each(function (Invoice $i) use ($out): void {
            fputcsv($out, [
                $i->number,
                $i->business?->name,
                $i->plan_name,
                $i->amount,
                $i->currency,
                $i->status instanceof \App\Enums\InvoiceStatus ? $i->status->value : $i->status,
                $i->issued_at?->toDateString(),
            ]);
        });
    }
}
