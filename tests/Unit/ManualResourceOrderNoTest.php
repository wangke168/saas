<?php

namespace Tests\Unit;

use App\Support\ManualResourceOrderNo;
use PHPUnit\Framework\TestCase;

class ManualResourceOrderNoTest extends TestCase
{
    public function test_null_and_empty_are_placeholders(): void
    {
        $this->assertTrue(ManualResourceOrderNo::isPlaceholder(null));
        $this->assertTrue(ManualResourceOrderNo::isPlaceholder(''));
    }

    public function test_auto_manual_prefix_is_placeholder(): void
    {
        $this->assertTrue(ManualResourceOrderNo::isPlaceholder('AUTO_MANUAL_123'));
    }

    public function test_real_resource_order_no_is_not_placeholder(): void
    {
        $this->assertFalse(ManualResourceOrderNo::isPlaceholder('WZ20260613001'));
    }
}
