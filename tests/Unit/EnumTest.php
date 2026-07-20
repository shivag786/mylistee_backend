<?php

namespace Tests\Unit;

use App\Enums\BusinessStatus;
use App\Enums\OfferType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    public function test_only_active_user_status_is_active(): void
    {
        $this->assertTrue(UserStatus::Active->isActive());
        $this->assertFalse(UserStatus::Suspended->isActive());
        $this->assertFalse(UserStatus::Blocked->isActive());
        $this->assertFalse(UserStatus::Pending->isActive());
    }

    public function test_business_status_active_helper(): void
    {
        $this->assertTrue(BusinessStatus::Active->isActive());
        $this->assertFalse(BusinessStatus::Rejected->isActive());
        $this->assertFalse(BusinessStatus::Suspended->isActive());
    }

    public function test_subscription_status_active_helper(): void
    {
        $this->assertTrue(SubscriptionStatus::Active->isActive());
        $this->assertFalse(SubscriptionStatus::Cancelled->isActive());
        $this->assertFalse(SubscriptionStatus::Expired->isActive());
    }

    public function test_every_offer_type_has_a_human_label(): void
    {
        foreach (OfferType::cases() as $type) {
            $this->assertNotEmpty($type->label());
        }
        $this->assertSame('Free item', OfferType::FreeItem->label());
        $this->assertSame('Buy one get one', OfferType::Bogo->label());
    }
}
