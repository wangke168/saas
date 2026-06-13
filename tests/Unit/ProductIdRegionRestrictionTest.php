<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductIdRegionRestriction;
use PHPUnit\Framework\TestCase;

class ProductIdRegionRestrictionTest extends TestCase
{
    public function test_ctrip_card_type_one_is_treated_as_id_card(): void
    {
        $passengers = [
            [
                'name' => '王书桓',
                'cardType' => '1',
                'cardNo' => '530627200211154118',
            ],
        ];

        $idCards = ProductIdRegionRestriction::extractIdCardsFromCtripPassengers($passengers);

        $this->assertSame(['530627200211154118'], $idCards);
    }

    public function test_ctrip_integer_card_type_one_is_treated_as_id_card(): void
    {
        $passengers = [
            [
                'cardType' => 1,
                'cardNo' => '530627200211154118',
            ],
        ];

        $idCards = ProductIdRegionRestriction::extractIdCardsFromCtripPassengers($passengers);

        $this->assertSame(['530627200211154118'], $idCards);
    }

    public function test_ctrip_passport_is_skipped(): void
    {
        $passengers = [
            [
                'name' => 'Test',
                'cardType' => '2',
                'cardNo' => 'E12345678',
            ],
        ];

        $idCards = ProductIdRegionRestriction::extractIdCardsFromCtripPassengers($passengers);

        $this->assertSame([], $idCards);
    }

    public function test_prefix_validation_passes_for_matching_ctrip_id_card(): void
    {
        $product = new Product([
            'id_region_restriction_enabled' => true,
            'id_region_prefixes' => ['53'],
        ]);

        $message = ProductIdRegionRestriction::validateOrMessage($product, ['530627200211154118']);

        $this->assertNull($message);
    }
}
