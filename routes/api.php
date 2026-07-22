<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\BroadcastController;
use App\Http\Controllers\Api\V1\Admin\BusinessController as AdminBusinessController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\CategoryRequestController as AdminCategoryRequestController;
use App\Http\Controllers\Api\V1\Admin\CmsController;
use App\Http\Controllers\Api\V1\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\FeatureFlagController;
use App\Http\Controllers\Api\V1\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Api\V1\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Business\AnalyticsController;
use App\Http\Controllers\Api\V1\Business\BusinessController;
use App\Http\Controllers\Api\V1\Business\BusinessGalleryController;
use App\Http\Controllers\Api\V1\Business\CategoryRequestController as OwnerCategoryRequestController;
use App\Http\Controllers\Api\V1\Business\ComboController;
use App\Http\Controllers\Api\V1\Business\TokenLookupController;
use App\Http\Controllers\Api\V1\Business\LoyaltyController;
use App\Http\Controllers\Api\V1\Business\OfferController;
use App\Http\Controllers\Api\V1\Business\ProductCategoryController;
use App\Http\Controllers\Api\V1\Business\ProductController;
use App\Http\Controllers\Api\V1\Business\PromotionController;
use App\Http\Controllers\Api\V1\Business\QrController;
use App\Http\Controllers\Api\V1\Business\RedemptionController;
use App\Http\Controllers\Api\V1\Business\ReviewController as OwnerReviewController;
use App\Http\Controllers\Api\V1\Business\SubscriptionController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PublicBusinessController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\WalletTokenController;
use App\Http\Controllers\Api\V1\LoyaltyController as CustomerLoyaltyController;
use App\Http\Controllers\Api\V1\SpinnerController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (versioned)
|--------------------------------------------------------------------------
| All endpoints live under /api/v1 so future versions can coexist
| (document/phase/11 §API Base URL). Feature route groups are added in
| their respective milestones.
*/

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    // Public
    Route::get('health', [HealthController::class, 'index'])->name('health');
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('businesses', [PublicBusinessController::class, 'index'])->name('businesses.index');
    Route::get('businesses/{slug}', [PublicBusinessController::class, 'show'])->name('businesses.show');
    Route::get('businesses/{slug}/reviews', [ReviewController::class, 'index'])->name('businesses.reviews');

    // Auth (document/phase/12 §Firebase Login Flow)
    Route::prefix('auth')->group(function (): void {
        Route::post('google', [AuthController::class, 'google'])
            ->middleware('throttle:10,1')
            ->name('auth.google');

        // Mobile/email + PIN sign-in (owners & admins) — document login change.
        Route::post('pin-login', [AuthController::class, 'pinLogin'])
            ->middleware('throttle:10,1')
            ->name('auth.pin-login');

        // Public business-owner sign-up (mobile + PIN).
        Route::post('register-owner', [AuthController::class, 'registerOwner'])
            ->middleware('throttle:10,1')
            ->name('auth.register-owner');

        // Local-only helper for verifying the app without Firebase creds.
        if (! App::environment('production')) {
            Route::post('dev-login', [AuthController::class, 'devLogin'])
                ->middleware('throttle:20,1')
                ->name('auth.dev-login');
        }

        Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');

            // Customer → owner self-upgrade ("list your business").
            Route::post('become-owner', [AuthController::class, 'becomeOwner'])
                ->middleware('throttle:10,1')
                ->name('auth.become-owner');
        });
    });

    // Customer spin + wallet (document/phase/11 §Spinner / Wallet Endpoints)
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('spinner/spin', [SpinnerController::class, 'spin'])
            ->middleware('throttle:30,1')
            ->name('spinner.spin');

        Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
        Route::get('wallet/rewards', [WalletController::class, 'rewards'])->name('wallet.rewards');

        // Rotating wallet token (Phase 7.3) — literal before any wildcard wallet route.
        Route::get('wallet/token', [WalletTokenController::class, 'show'])->name('wallet.token');
        Route::post('wallet/token/refresh', [WalletTokenController::class, 'refresh'])->name('wallet.token.refresh');

        // Listee Coins — balance, history, and spending on reward tiers (Phase 2)
        Route::get('wallet/coins', [WalletController::class, 'coins'])->name('wallet.coins');
        Route::get('wallet/coins/transactions', [WalletController::class, 'coinTransactions'])->name('wallet.coins.transactions');
        Route::get('businesses/{slug}/loyalty', [CustomerLoyaltyController::class, 'show'])->name('businesses.loyalty');
        Route::post('loyalty/redeem', [CustomerLoyaltyController::class, 'redeem'])
            ->middleware('throttle:30,1')
            ->name('loyalty.redeem');

        // Favorites + reviews (document/phase/11 §Favorites / Reviews)
        Route::get('favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('favorites', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('favorites/{slug}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

        Route::post('reviews', [ReviewController::class, 'store'])->name('reviews.store');
        Route::delete('reviews/{uuid}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

        // Notifications (document/phase/11 §Notifications, phase/13 §Push).
        // Literal paths declared before the {uuid} wildcard so they win.
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
        Route::patch('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('notifications/device-token', [DeviceTokenController::class, 'store'])->name('notifications.device.store');
        Route::delete('notifications/device-token', [DeviceTokenController::class, 'destroy'])->name('notifications.device.destroy');
        Route::delete('notifications/{uuid}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    });

    // Business owner (document/phase/07, phase/11 §Business Owner Endpoints)
    Route::middleware(['auth:sanctum', 'active', 'role:business_owner'])
        ->prefix('business')
        ->name('business.')
        ->group(function (): void {
            Route::post('/', [BusinessController::class, 'store'])->name('store');
            Route::get('profile', [BusinessController::class, 'show'])->name('profile.show');
            Route::put('profile', [BusinessController::class, 'update'])->name('profile.update');
            Route::get('dashboard', [BusinessController::class, 'dashboard'])->name('dashboard');

            // Analytics (document/phase/07 §Analytics, Milestone 12)
            Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');

            // Subscription & billing (document/phase/14, Milestone 13)
            Route::get('subscription', [SubscriptionController::class, 'index'])->name('subscription.show');
            Route::post('subscription', [SubscriptionController::class, 'store'])->name('subscription.store');
            Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
            Route::get('invoices', [SubscriptionController::class, 'invoices'])->name('invoices');

            Route::post('gallery', [BusinessGalleryController::class, 'store'])->name('gallery.store');
            Route::delete('gallery/{uuid}', [BusinessGalleryController::class, 'destroy'])->name('gallery.destroy');

            // Category requests — owner asks admin to add a missing category (Phase 7.1)
            Route::get('category-requests', [OwnerCategoryRequestController::class, 'index'])->name('category-requests.index');
            Route::post('category-requests', [OwnerCategoryRequestController::class, 'store'])->name('category-requests.store');

            // Menu sections (Phase 7.2) — declare literal `reorder` before {uuid}
            Route::get('product-categories', [ProductCategoryController::class, 'index'])->name('product-categories.index');
            Route::post('product-categories', [ProductCategoryController::class, 'store'])->name('product-categories.store');
            Route::patch('product-categories/reorder', [ProductCategoryController::class, 'reorder'])->name('product-categories.reorder');
            Route::put('product-categories/{uuid}', [ProductCategoryController::class, 'update'])->name('product-categories.update');
            Route::delete('product-categories/{uuid}', [ProductCategoryController::class, 'destroy'])->name('product-categories.destroy');

            // Products (Phase 7.2)
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::patch('products/reorder', [ProductController::class, 'reorder'])->name('products.reorder');
            Route::post('products/{uuid}', [ProductController::class, 'update'])->name('products.update'); // POST + _method for multipart
            Route::put('products/{uuid}', [ProductController::class, 'update']);
            Route::delete('products/{uuid}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::patch('products/{uuid}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
            Route::post('products/{uuid}/images', [ProductController::class, 'addImage'])->name('products.images.store');
            Route::delete('products/{uuid}/images/{imageUuid}', [ProductController::class, 'removeImage'])->name('products.images.destroy');

            // Combos (Phase 7.3)
            Route::get('combos', [ComboController::class, 'index'])->name('combos.index');
            Route::post('combos', [ComboController::class, 'store'])->name('combos.store');
            Route::post('combos/{uuid}', [ComboController::class, 'update'])->name('combos.update'); // POST + _method for multipart
            Route::put('combos/{uuid}', [ComboController::class, 'update']);
            Route::delete('combos/{uuid}', [ComboController::class, 'destroy'])->name('combos.destroy');

            // Customer wallet-token lookup at the counter (Phase 7.3)
            Route::post('token/lookup', [TokenLookupController::class, 'lookup'])->name('token.lookup');

            // Promotions — "Grow Sales" engine (Phase 7.2b)
            Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
            Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::put('promotions/{uuid}', [PromotionController::class, 'update'])->name('promotions.update');
            Route::patch('promotions/{uuid}/status', [PromotionController::class, 'status'])->name('promotions.status');
            Route::delete('promotions/{uuid}', [PromotionController::class, 'destroy'])->name('promotions.destroy');

            Route::get('qr', [QrController::class, 'show'])->name('qr.show');
            Route::post('qr/download', [QrController::class, 'download'])->name('qr.download');

            // Offers (document/phase/11 §Offer Endpoints)
            Route::get('offers/suggestions', [OfferController::class, 'suggestions'])->name('offers.suggestions');
            Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
            Route::post('offers', [OfferController::class, 'store'])->name('offers.store');
            Route::put('offers/{uuid}', [OfferController::class, 'update'])->name('offers.update');
            Route::delete('offers/{uuid}', [OfferController::class, 'destroy'])->name('offers.destroy');
            Route::patch('offers/{uuid}/status', [OfferController::class, 'status'])->name('offers.status');

            // Redemption (document/phase/11 §Redemption)
            Route::post('redeem/verify', [RedemptionController::class, 'verify'])->name('redeem.verify');
            Route::post('redeem', [RedemptionController::class, 'redeem'])->name('redeem');
            Route::get('redemptions', [RedemptionController::class, 'history'])->name('redemptions');

            // Reviews — owner reads & replies (Phase 1)
            Route::get('reviews', [OwnerReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews/{uuid}/reply', [OwnerReviewController::class, 'reply'])->name('reviews.reply');

            // Loyalty (Listee Coins) config — earn rates + reward tiers (Phase 2)
            Route::get('loyalty', [LoyaltyController::class, 'show'])->name('loyalty.show');
            Route::put('loyalty', [LoyaltyController::class, 'update'])->name('loyalty.update');
            Route::post('loyalty/rewards', [LoyaltyController::class, 'storeReward'])->name('loyalty.rewards.store');
            Route::put('loyalty/rewards/{uuid}', [LoyaltyController::class, 'updateReward'])->name('loyalty.rewards.update');
            Route::delete('loyalty/rewards/{uuid}', [LoyaltyController::class, 'destroyReward'])->name('loyalty.rewards.destroy');
        });

    // Super Admin panel (document/phase/14, Milestone 14)
    Route::middleware(['auth:sanctum', 'active', 'role:admin'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function (): void {
            Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
            Route::get('fraud', [AdminDashboardController::class, 'fraud'])->name('fraud');

            // Business management
            Route::get('businesses', [AdminBusinessController::class, 'index'])->name('businesses.index');
            Route::get('businesses/{uuid}', [AdminBusinessController::class, 'show'])->name('businesses.show');
            Route::patch('businesses/{uuid}/status', [AdminBusinessController::class, 'updateStatus'])->name('businesses.status');
            Route::patch('businesses/{uuid}/verify', [AdminBusinessController::class, 'verify'])->name('businesses.verify');
            Route::patch('businesses/{uuid}/feature', [AdminBusinessController::class, 'feature'])->name('businesses.feature');

            // Master category management (Phase 7.1)
            Route::get('categories', [AdminCategoryController::class, 'index'])->name('categories.index');
            Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store');
            Route::patch('categories/reorder', [AdminCategoryController::class, 'reorder'])->name('categories.reorder');
            Route::post('categories/{uuid}', [AdminCategoryController::class, 'update'])->name('categories.update'); // POST + _method for multipart
            Route::put('categories/{uuid}', [AdminCategoryController::class, 'update']);
            Route::delete('categories/{uuid}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');

            // Owner category requests moderation (Phase 7.1)
            Route::get('category-requests', [AdminCategoryRequestController::class, 'index'])->name('category-requests.index');
            Route::patch('category-requests/{uuid}/approve', [AdminCategoryRequestController::class, 'approve'])->name('category-requests.approve');
            Route::patch('category-requests/{uuid}/reject', [AdminCategoryRequestController::class, 'reject'])->name('category-requests.reject');

            // Customer management
            Route::get('customers', [AdminCustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{uuid}', [AdminCustomerController::class, 'show'])->name('customers.show');
            Route::patch('customers/{uuid}/status', [AdminCustomerController::class, 'updateStatus'])->name('customers.status');

            // Offer oversight + review moderation
            Route::get('offers', [AdminOfferController::class, 'index'])->name('offers.index');
            Route::patch('offers/{uuid}/status', [AdminOfferController::class, 'updateStatus'])->name('offers.status');
            Route::get('reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
            Route::patch('reviews/{uuid}/status', [AdminReviewController::class, 'updateStatus'])->name('reviews.status');

            // Plans & subscriptions
            Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
            Route::patch('plans/{key}', [AdminPlanController::class, 'update'])->name('plans.update');

            // Broadcast notifications
            Route::post('broadcast', [BroadcastController::class, 'store'])->name('broadcast');

            // Feature flags + platform settings + CMS
            Route::get('feature-flags', [FeatureFlagController::class, 'index'])->name('flags.index');
            Route::patch('feature-flags/{key}', [FeatureFlagController::class, 'update'])->name('flags.update');
            Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
            Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
            Route::get('cms', [CmsController::class, 'index'])->name('cms.index');
            Route::get('cms/{slug}', [CmsController::class, 'show'])->name('cms.show');
            Route::put('cms/{slug}', [CmsController::class, 'update'])->name('cms.update');

            // Audit logs + report exports
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit');
            Route::get('reports/{type}', [ReportController::class, 'export'])->name('reports.export');
        });
});
