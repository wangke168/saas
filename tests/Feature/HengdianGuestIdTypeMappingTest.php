<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Resource\HengdianService;
use ReflectionMethod;
use Tests\TestCase;

class HengdianGuestIdTypeMappingTest extends TestCase
{
    /**
     * 调用受保护方法 mapGuestIdType（仅用于映射规则测试）
     */
    protected function mapIdType(array $guest): string
    {
        $service = new HengdianService();
        $method = new ReflectionMethod(HengdianService::class, 'mapGuestIdType');
        $method->setAccessible(true);

        return (string)$method->invoke($service, $guest);
    }

    public function test_ctrip_card_type_mapping_to_hengdian_id_type(): void
    {
        $this->assertSame('0', $this->mapIdType(['cardType' => '1']));
        $this->assertSame('1', $this->mapIdType(['cardType' => '2']));
        $this->assertSame('2', $this->mapIdType(['cardType' => '10']));
    }

    public function test_meituan_credential_type_mapping_to_hengdian_id_type(): void
    {
        $this->assertSame('0', $this->mapIdType(['credentialType' => 0]));
        $this->assertSame('1', $this->mapIdType(['credentialType' => 1]));
        $this->assertSame('2', $this->mapIdType(['credentialType' => 4]));
    }

    public function test_id_type_has_highest_priority(): void
    {
        // idType 优先于其他字段，避免不同平台字段混用时误判
        $this->assertSame('0', $this->mapIdType([
            'idType' => '0',
            'cardType' => '2',
            'credentialType' => 1,
        ]));
    }
}

