<?php

namespace Database\Seeders;

use App\Models\BusinessCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Default business categories (document/phase/02 §Business Categories, phase/07).
 * Idempotent — safe to re-run. New categories can be added by the admin panel
 * (Milestone 14) without a schema change.
 */
class BusinessCategorySeeder extends Seeder
{
    /** @var list<array{name: string, icon: string}> */
    private const CATEGORIES = [
        ['name' => 'Restaurant', 'icon' => 'utensils'],
        ['name' => 'Cafe', 'icon' => 'coffee'],
        ['name' => 'Bakery', 'icon' => 'cake'],
        ['name' => 'Tea Stall', 'icon' => 'cup-soda'],
        ['name' => 'Fast Food', 'icon' => 'sandwich'],
        ['name' => 'Juice Shop', 'icon' => 'citrus'],
        ['name' => 'Salon', 'icon' => 'scissors'],
        ['name' => 'Spa', 'icon' => 'flower'],
        ['name' => 'Gym', 'icon' => 'dumbbell'],
        ['name' => 'Medical Store', 'icon' => 'pill'],
        ['name' => 'Stationery', 'icon' => 'pencil'],
        ['name' => 'Clothing', 'icon' => 'shirt'],
        ['name' => 'Electronics', 'icon' => 'smartphone'],
        ['name' => 'Hotel', 'icon' => 'bed-double'],
        ['name' => 'Coaching Institute', 'icon' => 'graduation-cap'],
        ['name' => 'Service Center', 'icon' => 'wrench'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => $category) {
            BusinessCategory::updateOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'sort_order' => $index,
                    'status' => 'active',
                ],
            );
        }
    }
}
