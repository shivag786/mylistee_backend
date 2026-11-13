<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts for products that have one (`stock_qty` not null). Every sale
 * path — a product line or a combo containing it — goes through deduct(), and
 * every cancellation path through restore(), so the count stays true.
 * Both must run inside the caller's transaction (they lock the rows).
 */
class StockService
{
    /**
     * Take what an order needs from stock, refusing the order if any counted
     * product is short. Products hitting zero go out of stock.
     *
     * @param  array<int, int>  $needs  product id => units
     * @return array<int, int> what was actually taken (counted products only)
     *
     * @throws ValidationException
     */
    public function deduct(array $needs): array
    {
        $needs = array_filter($needs, fn (int $qty): bool => $qty > 0);
        if ($needs === []) {
            return [];
        }

        $products = Product::whereIn('id', array_keys($needs))
            ->whereNotNull('stock_qty')
            ->lockForUpdate()
            ->get();

        $taken = [];
        foreach ($products as $product) {
            $need = $needs[$product->id];
            if ($product->stock_qty < $need) {
                throw ValidationException::withMessages(['items' => [
                    $product->stock_qty > 0
                        ? "Only {$product->stock_qty} left of {$product->name}."
                        : "{$product->name} is sold out.",
                ]]);
            }

            $left = $product->stock_qty - $need;
            $product->stock_qty = $left;
            if ($left === 0) {
                $product->in_stock = false;
                // The owner hears once a day per dish that it sold out.
                $business = $product->business;
                if ($business !== null) {
                    app(OwnerAlertService::class)->raise(
                        $business,
                        'low_stock',
                        "{$product->name} is sold out",
                        'Its stock reached 0, so customers can\'t order it. Add stock in Menu when it\'s back.',
                        'info',
                        ['productId' => $product->uuid],
                        dedupeKey: 'stock:'.$product->id.':'.now()->toDateString(),
                    );
                }
            }
            $product->save();
            $taken[$product->id] = $need;
        }

        return $taken;
    }

    /**
     * Put back units an edited order no longer needs -- only what the order
     * had actually taken from a counted product.
     *
     * @param  array<int, int>  $units  product id => units no longer needed
     * @param  array<int|string, int>  $taken  what the order holds now
     * @return array<int, int> what went back
     */
    public function giveBack(array $units, array $taken): array
    {
        $back = [];
        foreach ($units as $productId => $qty) {
            $qty = min((int) $qty, (int) ($taken[$productId] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $product = Product::whereKey((int) $productId)->whereNotNull('stock_qty')->lockForUpdate()->first();
            if ($product === null) {
                continue;
            }
            if ($product->stock_qty === 0) {
                $product->in_stock = true;
            }
            $product->stock_qty += $qty;
            $product->save();
            $back[(int) $productId] = $qty;
        }

        return $back;
    }

    /**
     * Put back what a cancelled order took. Safe to call twice: the order's
     * record is cleared after the first time.
     */
    public function restore(Order $order): void
    {
        $taken = $order->stock_deducted ?? [];
        if ($taken === []) {
            return;
        }

        foreach ($taken as $productId => $qty) {
            $product = Product::whereKey((int) $productId)->whereNotNull('stock_qty')->lockForUpdate()->first();
            if ($product === null) {
                continue; // deleted, or the owner stopped counting stock
            }
            // Sold out only because it ran to zero → it's back now.
            if ($product->stock_qty === 0 && $qty > 0) {
                $product->in_stock = true;
            }
            $product->stock_qty += (int) $qty;
            $product->save();
        }

        $order->forceFill(['stock_deducted' => null])->save();
    }
}
