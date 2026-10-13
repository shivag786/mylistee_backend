<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ShopCustomer;

/**
 * A shop's customer book. The owner adds and edits rows by hand; a review left
 * through the shop's review link adds the reviewer automatically. Phones are
 * already normalized (ShopCustomer::normalizePhone) by the time they get here.
 */
class ShopCustomerService
{
    /** @param  array{phone: string, name?: ?string, email?: ?string, city?: ?string}  $data */
    public function create(Business $business, array $data): ShopCustomer
    {
        return $business->shopCustomers()->create([
            'phone' => $data['phone'],
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'source' => 'manual',
        ]);
    }

    /** @param  array{phone: string, name?: ?string, email?: ?string, city?: ?string}  $data */
    public function update(ShopCustomer $customer, array $data): ShopCustomer
    {
        $customer->update([
            'phone' => $data['phone'],
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
        ]);

        return $customer;
    }

    /**
     * The customer for a mobile number, created on first sight. A name typed on
     * the review form fills a blank name but never overwrites one the owner set.
     */
    public function findOrCreateFromReview(Business $business, string $phone, ?string $name): ShopCustomer
    {
        $customer = $business->shopCustomers()->firstOrCreate(
            ['phone' => $phone],
            ['name' => $name, 'source' => 'review'],
        );

        if ($name !== null && $customer->name === null) {
            $customer->update(['name' => $name]);
        }

        return $customer;
    }

    /**
     * Removes the customer from the book. Their reviews stay on the shop's page
     * (the link to this row is nulled), keeping the name they were posted under.
     */
    public function delete(ShopCustomer $customer): void
    {
        $customer->delete();
    }
}
