<?php

namespace Tests\Unit;

use App\Models\Plan;
use Tests\TestCase;

class PlanTest extends TestCase
{
    public function test_is_free_reflects_price(): void
    {
        $this->assertTrue((new Plan(['price' => 0]))->isFree());
        $this->assertFalse((new Plan(['price' => 499]))->isFree());
    }

    public function test_has_feature_checks_the_features_array(): void
    {
        $plan = new Plan(['features' => ['analytics', 'push_notifications']]);

        $this->assertTrue($plan->hasFeature('analytics'));
        $this->assertTrue($plan->hasFeature('push_notifications'));
        $this->assertFalse($plan->hasFeature('loyalty'));
    }

    public function test_has_feature_is_safe_when_features_is_null(): void
    {
        $this->assertFalse((new Plan)->hasFeature('analytics'));
    }
}
