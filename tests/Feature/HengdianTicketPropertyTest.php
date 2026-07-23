<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HengdianTicketProperty;
use App\Models\Product;
use App\Services\Resource\HengdianService;
use ReflectionMethod;
use Tests\TestCase;

class HengdianTicketPropertyTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyTicketProperty(array $data, ?Product $product = null): array
    {
        $service = new HengdianService;
        $method = new ReflectionMethod(HengdianService::class, 'applyTicketProperty');
        $method->setAccessible(true);

        return $method->invoke($service, $data, $product);
    }

    public function test_does_not_send_when_product_unconfigured(): void
    {
        $product = new Product(['ticket_property' => null]);
        $result = $this->applyTicketProperty(['HotelId' => '001'], $product);

        $this->assertArrayNotHasKey('TicketProperty', $result);
    }

    public function test_uses_product_ticket_property(): void
    {
        $product = new Product(['ticket_property' => HengdianTicketProperty::Student]);
        $result = $this->applyTicketProperty(['HotelId' => '001'], $product);

        $this->assertSame('student', $result['TicketProperty']);
    }

    public function test_explicit_overrides_product(): void
    {
        $product = new Product(['ticket_property' => HengdianTicketProperty::Adult]);
        $result = $this->applyTicketProperty([
            'HotelId' => '001',
            'TicketProperty' => 'elder',
        ], $product);

        $this->assertSame('elder', $result['TicketProperty']);
    }

    public function test_invalid_value_is_ignored(): void
    {
        $result = $this->applyTicketProperty([
            'HotelId' => '001',
            'TicketProperty' => 'vip',
        ]);

        $this->assertArrayNotHasKey('TicketProperty', $result);
    }

    public function test_enum_values_match_hengdian_docs(): void
    {
        $this->assertSame(
            ['adult', 'child', 'elder', 'teacher', 'student', 'half'],
            HengdianTicketProperty::values()
        );
    }
}
