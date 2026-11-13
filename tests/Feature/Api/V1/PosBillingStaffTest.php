<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStaff;
use App\Models\ModuleUsage;
use App\Models\Order;
use App\Models\OwnerAlert;
use App\Models\Product;
use App\Models\StaffAttendance;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Restaurant POS layer: GST bills with invoice numbers, staff sign-in and
 * permissions, counter discounts, owner alerts, the day-end report with the
 * cash count, attendance, and the module switch / per-use fee.
 */
class PosBillingStaffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        // 2 pm on a Thursday, shop time.
        Carbon::setTestNow(Carbon::parse('2026-11-12 14:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business, 2: Product} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create(['name' => 'Owner']);
        $business = Business::factory()->create(['owner_id' => $owner->id, 'latitude' => 26.9124, 'longitude' => 75.7873]);
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Paneer Tikka', 'selling_price' => 100, 'stock_qty' => null]);

        return [$owner, $business, $product];
    }

    private function gst(User $owner, array $extra = []): void
    {
        $this->as($owner)->putJson('/api/v1/business/billing', [
            'gstEnabled' => true,
            'gstin' => '08ABCDE1234F1Z5',
            'defaultTaxRate' => 5,
        ] + $extra)->assertOk();
    }

    /** Add staff as the owner; returns [staff user, staff row]. */
    private function staff(User $owner, string $role, array $extra = []): array
    {
        $phone = '98'.random_int(10000000, 99999999);
        $this->as($owner)->postJson('/api/v1/business/staff', [
            'name' => ucfirst($role).' Ravi',
            'phone' => $phone,
            'pin' => '4826',
            'role' => $role,
        ] + $extra)->assertCreated();
        $row = BusinessStaff::where('phone', $phone)->firstOrFail();

        return [$row->user, $row];
    }

    private function counterOrder(User $by, Product $product, int $qty = 2, array $extra = [])
    {
        return $this->as($by)->postJson('/api/v1/business/orders', [
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => $qty]],
        ] + $extra);
    }

    // ---- GST -----------------------------------------------------------

    public function test_gst_is_added_on_the_bill_and_paid_bills_get_invoice_numbers(): void
    {
        [$owner, $business, $product] = $this->shop();
        $this->gst($owner, ['invoicePrefix' => 'pt']);

        $res = $this->counterOrder($owner, $product)->assertCreated()
            ->assertJsonPath('data.subtotal', 200)
            ->assertJsonPath('data.tax.mode', 'exclusive')
            ->assertJsonPath('data.tax.cgst', 5)
            ->assertJsonPath('data.tax.sgst', 5)
            ->assertJsonPath('data.total', 210)
            ->assertJsonPath('data.items.0.taxRate', 5)
            ->assertJsonPath('data.invoiceNo', null);

        $this->as($owner)->patchJson('/api/v1/business/orders/'.$res->json('data.id').'/status', ['status' => 'paid', 'payment_method' => 'upi'])
            ->assertOk()
            ->assertJsonPath('data.invoiceNo', 'PT/2627/0001')
            ->assertJsonPath('data.paymentLabel', 'UPI');

        $second = $this->counterOrder($owner, $product, 1, ['paid' => 'cod'])->assertCreated();
        $this->assertSame('PT/2627/0002', $second->json('data.invoiceNo'));
    }

    public function test_tax_inclusive_prices_only_show_the_gst_inside(): void
    {
        [$owner, , $product] = $this->shop();
        $product->update(['selling_price' => 105]);
        $this->gst($owner, ['pricesIncludeTax' => true]);

        $this->counterOrder($owner, $product, 1)->assertCreated()
            ->assertJsonPath('data.total', 105)
            ->assertJsonPath('data.tax.taxable', 100)
            ->assertJsonPath('data.tax.cgst', 2.5);
    }

    public function test_service_charge_and_a_dish_rate_are_taxed(): void
    {
        [$owner, $business, $product] = $this->shop();
        $this->gst($owner, ['serviceChargePercent' => 10, 'roundOff' => false]);
        $drink = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100, 'tax_rate' => 18, 'stock_qty' => null]);

        // Food 100 @5% + drink 100 @18%, service charge 10% (20) taxed with each line.
        $res = $this->as($owner)->postJson('/api/v1/business/orders', ['items' => [
            ['type' => 'product', 'id' => $product->uuid, 'quantity' => 1],
            ['type' => 'product', 'id' => $drink->uuid, 'quantity' => 1],
        ]])->assertCreated();

        $this->assertEquals(20, $res->json('data.tax.serviceCharge'));
        // tax = 110 × 5% + 110 × 18% = 5.5 + 19.8 = 25.3
        $this->assertEquals(12.65, $res->json('data.tax.cgst'));
        $this->assertEquals(245.3, $res->json('data.total'));
        $this->assertCount(2, $res->json('data.tax.rates'));
    }

    public function test_no_gst_settings_leave_bills_unchanged(): void
    {
        [$owner, , $product] = $this->shop();
        $this->counterOrder($owner, $product)->assertCreated()
            ->assertJsonPath('data.total', 200)
            ->assertJsonPath('data.tax', null);
    }

    public function test_gst_needs_a_gstin(): void
    {
        [$owner] = $this->shop();
        $this->as($owner)->putJson('/api/v1/business/billing', ['gstEnabled' => true])->assertStatus(422);
        $this->as($owner)->putJson('/api/v1/business/billing', ['gstin' => 'BAD'])->assertStatus(422)->assertJsonValidationErrors('gstin');
    }

    // ---- Staff & permissions --------------------------------------------

    public function test_staff_sign_in_with_the_shop_code_and_see_only_what_they_are_allowed(): void
    {
        [$owner, $business, $product] = $this->shop();
        [, $row] = $this->staff($owner, 'waiter');
        $code = $business->fresh()->staff_code;
        $this->assertNotNull($code);

        $this->postJson('/api/v1/auth/staff-login', ['shopCode' => $code, 'mobile' => $row->phone, 'pin' => '1111'])->assertStatus(422);
        $login = $this->postJson('/api/v1/auth/staff-login', ['shopCode' => strtolower($code), 'mobile' => '+91'.$row->phone, 'pin' => '4826'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'staff')
            ->assertJsonPath('data.user.staff.permissions', ['take_orders']);

        $this->app['auth']->forgetGuards();
        $staff = $this->withToken($login->json('data.token'));
        $staff->getJson('/api/v1/business/dashboard')->assertOk()->assertJsonMissingPath('data.sales');
        $staff->getJson('/api/v1/business/products')->assertOk();

        $order = $staff->postJson('/api/v1/business/orders', ['items' => [['type' => 'product', 'id' => $product->uuid]]])->assertCreated();
        $this->assertSame($row->user_id, Order::first()->created_by);

        // A waiter can't take payment, see analytics, change settings or manage staff.
        $staff->patchJson('/api/v1/business/orders/'.$order->json('data.id').'/status', ['status' => 'paid'])->assertForbidden();
        $staff->getJson('/api/v1/business/analytics')->assertForbidden();
        $staff->putJson('/api/v1/business/billing', ['roundOff' => false])->assertForbidden();
        $staff->postJson('/api/v1/business/staff', [])->assertForbidden();
        $staff->getJson('/api/v1/business/subscription')->assertOk();
        $staff->postJson('/api/v1/business/subscription/checkout', [])->assertForbidden();
    }

    public function test_turning_staff_off_signs_them_out(): void
    {
        [$owner, , ] = $this->shop();
        [$user, $row] = $this->staff($owner, 'cashier');
        $token = $user->createToken('staff')->plainTextToken;

        $this->as($owner)->putJson("/api/v1/business/staff/{$row->uuid}", ['active' => false])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/business/dashboard')->assertUnauthorized();
    }

    public function test_cashier_discount_limit_and_owner_alert(): void
    {
        [$owner, $business, $product] = $this->shop();
        [$cashier] = $this->staff($owner, 'cashier'); // 10% limit

        $this->counterOrder($cashier, $product, 2, ['discount' => ['type' => 'percent', 'value' => 15]])
            ->assertStatus(422)->assertJsonValidationErrors('discount');

        $this->counterOrder($cashier, $product, 2, ['discount' => ['type' => 'amount', 'value' => 20, 'reason' => 'Regular']])
            ->assertCreated()
            ->assertJsonPath('data.manualDiscount', 20)
            ->assertJsonPath('data.total', 180);

        $alert = OwnerAlert::where('business_id', $business->id)->where('type', 'discount')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('10% discount', $alert->title);

        // A waiter can't discount at all.
        [$waiter] = $this->staff($owner, 'waiter');
        $this->counterOrder($waiter, $product, 1, ['discount' => ['type' => 'percent', 'value' => 5]])->assertStatus(422);
    }

    public function test_staff_cancellation_and_removing_items_after_kot_alert_the_owner(): void
    {
        [$owner, $business, $product] = $this->shop();
        [$waiter] = $this->staff($owner, 'waiter');
        [$manager] = $this->staff($owner, 'manager');

        $order = $this->counterOrder($waiter, $product, 3)->assertCreated();
        $id = $order->json('data.id');
        $itemId = $order->json('data.items.0.id');

        // The waiter may add but not take off what the kitchen has.
        $this->as($waiter)->patchJson("/api/v1/business/orders/{$id}", ['quantities' => [$itemId => 1]])->assertStatus(422);
        $this->as($waiter)->patchJson("/api/v1/business/orders/{$id}/status", ['status' => 'cancelled'])->assertForbidden();

        $this->as($manager)->patchJson("/api/v1/business/orders/{$id}", ['quantities' => [$itemId => 1]])->assertOk()->assertJsonPath('data.total', 100);
        $this->assertTrue(OwnerAlert::where('type', 'item_removed')->exists());

        $this->as($manager)->patchJson("/api/v1/business/orders/{$id}/status", ['status' => 'cancelled', 'reason' => 'Customer left'])->assertOk();
        $alert = OwnerAlert::where('type', 'order_cancelled')->firstOrFail();
        $this->assertSame('danger', $alert->severity);
        $this->assertStringContainsString('Customer left', $alert->body);
        $this->assertSame($manager->id, Order::first()->cancelled_by);

        // The owner's own actions don't alert the owner.
        $own = $this->counterOrder($owner, $product, 1)->assertCreated();
        $this->as($owner)->patchJson('/api/v1/business/orders/'.$own->json('data.id').'/status', ['status' => 'cancelled'])->assertOk();
        $this->assertSame(1, OwnerAlert::where('type', 'order_cancelled')->count());
    }

    public function test_owner_can_switch_an_alert_kind_off(): void
    {
        [$owner, $business, $product] = $this->shop();
        [$manager] = $this->staff($owner, 'manager');
        $this->as($owner)->putJson('/api/v1/business/alerts/settings', ['cancel' => false])->assertOk()->assertJsonPath('data.cancel', false);

        $order = $this->counterOrder($manager, $product)->assertCreated();
        $this->as($manager)->patchJson('/api/v1/business/orders/'.$order->json('data.id').'/status', ['status' => 'cancelled'])->assertOk();
        $this->assertSame(0, OwnerAlert::where('type', 'order_cancelled')->count());
    }

    // ---- Day-end report & cash -----------------------------------------

    public function test_day_report_splits_payments_and_the_cash_count_alerts_on_a_shortfall(): void
    {
        [$owner, $business, $product] = $this->shop();
        [$cashier] = $this->staff($owner, 'cashier');

        $this->counterOrder($cashier, $product, 3, ['paid' => 'cod'])->assertCreated();   // 300 cash
        $this->counterOrder($cashier, $product, 1, ['paid' => 'upi'])->assertCreated();   // 100 UPI
        $this->counterOrder($owner, $product, 2, ['paid' => 'card'])->assertCreated();    // 200 card
        $this->as($cashier)->postJson('/api/v1/business/cash-movements', ['type' => 'out', 'amount' => 50, 'reason' => 'Vegetables'])->assertCreated();

        $report = $this->as($owner)->getJson('/api/v1/business/day-report')->assertOk();
        $this->assertSame('2026-11-12', $report->json('data.date'));
        $this->assertEquals(600, $report->json('data.sales.total'));
        $this->assertEquals(3, $report->json('data.sales.orders'));
        $payments = collect($report->json('data.payments'))->keyBy('method');
        $this->assertEquals(300, $payments['cash']['amount']);
        $this->assertEquals(100, $payments['upi']['amount']);
        $this->assertEquals(200, $payments['card']['amount']);
        $this->assertEquals(250, $report->json('data.cash.expected'));
        $this->assertSame('Cashier Ravi', $report->json('data.staff.0.name'));

        // Counted 149 of 250: short by 101, past the default ₹100 alert limit.
        $this->as($cashier)->postJson('/api/v1/business/day-close', ['countedCash' => 149, 'cashLeft' => 100])->assertOk()
            ->assertJsonPath('data.closing.difference', -101)
            ->assertJsonPath('data.closing.cashLeft', 100);
        $this->assertTrue(OwnerAlert::where('type', 'cash_difference')->exists());
        $this->assertTrue(OwnerAlert::where('type', 'daily_summary')->exists());

        // Tomorrow opens with the cash left in the drawer.
        Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00', 'Asia/Kolkata'));
        $this->as($owner)->getJson('/api/v1/business/day-report')->assertOk()->assertJsonPath('data.cash.opening', 100);
    }

    public function test_a_late_night_bill_stays_on_the_evening_it_belongs_to(): void
    {
        [$owner, , $product] = $this->shop();
        Carbon::setTestNow(Carbon::parse('2026-11-13 01:30:00', 'Asia/Kolkata'));
        $this->counterOrder($owner, $product, 1, ['paid' => 'cod'])->assertCreated();

        $this->as($owner)->getJson('/api/v1/business/day-report?date=2026-11-12')->assertOk()->assertJsonPath('data.sales.orders', 1);
        $this->as($owner)->getJson('/api/v1/business/day-report?date=2026-11-13')->assertOk()->assertJsonPath('data.sales.orders', 0);
    }

    public function test_a_cashier_without_reports_sees_only_the_cash_part(): void
    {
        [$owner, , ] = $this->shop();
        [$cashier] = $this->staff($owner, 'cashier');
        $this->as($cashier)->getJson('/api/v1/business/day-report')->assertOk()
            ->assertJsonMissingPath('data.sales')
            ->assertJsonStructure(['data' => ['cash' => ['expected']]]);
    }

    // ---- Attendance -----------------------------------------------------

    public function test_staff_check_in_with_selfie_and_gps_then_out(): void
    {
        Storage::fake('local');
        [$owner, $business, ] = $this->shop();
        [$waiter, $row] = $this->staff($owner, 'waiter', ['shiftStart' => '13:30']);

        // 5 km away: refused.
        $this->as($waiter)->post('/api/v1/business/attendance/punch', [
            'photo' => UploadedFile::fake()->image('me.jpg'), 'lat' => 26.95, 'lng' => 75.80, 'accuracy' => 20,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('location');

        // No selfie: refused.
        $this->as($waiter)->postJson('/api/v1/business/attendance/punch', ['lat' => 26.9124, 'lng' => 75.7873])
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        // At the shop, 30 min after a 13:30 shift (grace 10): late.
        $in = $this->as($waiter)->post('/api/v1/business/attendance/punch', [
            'photo' => UploadedFile::fake()->image('me.jpg'), 'lat' => 26.9125, 'lng' => 75.7874, 'accuracy' => 15,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.action', 'in')
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.lateMinutes', 30);
        Storage::disk('local')->assertExists(StaffAttendance::first()->check_in_photo);

        // The owner can see the selfie; another staff member can't.
        $photo = $in->json('data.checkInPhoto');
        $this->assertStringStartsWith('/api/v1/business/attendance/photo/', $photo);
        $this->as($owner)->get($photo)->assertOk();
        [$other] = $this->staff($owner, 'waiter');
        $this->as($other)->getJson($photo)->assertForbidden();

        Carbon::setTestNow(Carbon::parse('2026-11-12 22:00:00', 'Asia/Kolkata'));
        $this->as($waiter)->post('/api/v1/business/attendance/punch', [
            'photo' => UploadedFile::fake()->image('me.jpg'), 'lat' => 26.9124, 'lng' => 75.7873,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.action', 'out')
            ->assertJsonPath('data.workedMinutes', 480);
    }

    public function test_kiosk_punch_with_pin_and_month_summary_with_salary(): void
    {
        Storage::fake('local');
        [$owner, $business, ] = $this->shop();
        [, $row] = $this->staff($owner, 'kitchen', ['monthlySalary' => 15000, 'weekOff' => [1], 'joinedOn' => '2026-11-01']);

        $this->as($owner)->post('/api/v1/business/attendance/kiosk', [
            'staff' => $row->uuid, 'pin' => '0000', 'photo' => UploadedFile::fake()->image('k.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->as($owner)->post('/api/v1/business/attendance/kiosk', [
            'staff' => $row->uuid, 'pin' => '4826', 'photo' => UploadedFile::fake()->image('k.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.action', 'in')->assertJsonPath('data.status', 'present');

        // Mark one day leave by hand.
        $this->as($owner)->putJson("/api/v1/business/attendance/{$row->uuid}/2026-11-04", ['status' => 'leave'])->assertOk();

        $month = $this->as($owner)->getJson('/api/v1/business/attendance/month?month=2026-11')->assertOk();
        $s = $month->json('data.staff.0');
        // Nov 1–11 are past: Mondays 2 & 9 off, the 4th leave, the other 8 absent. Today present.
        $this->assertSame(2, $s['counts']['week_off']);
        $this->assertSame(1, $s['counts']['leave']);
        $this->assertSame(8, $s['counts']['absent']);
        $this->assertSame(1, $s['counts']['present']);
        $this->assertEquals(4, $s['payableDays']);
        $this->assertEquals(2000, $s['salaryEstimate']);
    }

    public function test_late_staff_alert_from_the_scheduler(): void
    {
        [$owner, $business, ] = $this->shop();
        $this->staff($owner, 'waiter', ['shiftStart' => '12:00']);

        $this->artisan('pos:alerts')->assertSuccessful();
        $alert = OwnerAlert::where('type', 'attendance')->first();
        $this->assertNotNull($alert);
        $this->artisan('pos:alerts')->assertSuccessful();
        $this->assertSame(1, OwnerAlert::where('type', 'attendance')->count());
    }

    // ---- Module & per-use fee -------------------------------------------

    public function test_billing_module_off_in_the_plan_stops_gst_and_its_screens(): void
    {
        [$owner, , $product] = $this->shop();
        $this->gst($owner);

        $modules = collect(array_keys(config('plan_modules')))->mapWithKeys(fn ($id) => [$id => ['on' => $id !== 'billing', 'days' => null]])->all();
        $this->as(User::factory()->admin()->create())->patchJson('/api/v1/admin/plans/free', ['modules' => $modules])->assertOk();

        $this->as($owner)->putJson('/api/v1/business/billing', ['roundOff' => false])->assertForbidden();
        $this->counterOrder($owner, $product)->assertCreated()->assertJsonPath('data.tax', null)->assertJsonPath('data.total', 200);
    }

    public function test_a_per_bill_fee_counts_each_gst_bill_once(): void
    {
        [$owner, $business, $product] = $this->shop();
        $this->gst($owner);
        $modules = collect(array_keys(config('plan_modules')))->mapWithKeys(fn ($id) => [$id => ['on' => true, 'days' => null]])->all();
        $modules['billing']['fee'] = 0.5;
        $this->as(User::factory()->admin()->create())->patchJson('/api/v1/admin/plans/free', ['modules' => $modules])->assertOk();

        $this->counterOrder($owner, $product, 1, ['paid' => 'cod'])->assertCreated();
        $this->counterOrder($owner, $product, 1, ['paid' => 'upi'])->assertCreated();

        $this->assertSame(2, ModuleUsage::where('business_id', $business->id)->where('module', 'billing')->count());
        $this->as(User::factory()->admin()->create())->getJson('/api/v1/admin/module-usage?month=2026-11')
            ->assertOk()->assertJsonPath('data.total', 1);
    }
}
