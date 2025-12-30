<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ScenicSpot;
use App\Models\SoftwareProvider;
use App\Models\ResourceConfig;
use App\Models\Hotel;
use App\Models\Order;
use App\Models\Product;
use App\Services\Resource\ScenicSpotIdentificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class ScenicSpotIdentificationTest extends TestCase
{
    use RefreshDatabase;

    protected SoftwareProvider $softwareProvider;
    protected ScenicSpot $scenicSpotA;
    protected ScenicSpot $scenicSpotB;
    protected ResourceConfig $configA;
    protected ResourceConfig $configB;
    protected Hotel $hotelA;
    protected Hotel $hotelB;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建软件服务商
        $this->softwareProvider = SoftwareProvider::create([
            'name' => '横店影视城系统',
            'code' => 'HENGDIAN',
            'description' => '横店影视城管理系统',
            'api_type' => 'hengdian',
            'is_active' => true,
        ]);
        
        // 创建景区A
        $this->scenicSpotA = ScenicSpot::create([
            'name' => '横店景区A',
            'code' => 'SS001',
            'software_provider_id' => $this->softwareProvider->id,
            'is_active' => true,
        ]);
        
        // 创建景区B
        $this->scenicSpotB = ScenicSpot::create([
            'name' => '横店景区B',
            'code' => 'SS002',
            'software_provider_id' => $this->softwareProvider->id,
            'is_active' => true,
        ]);
        
        // 为景区A创建配置
        $this->configA = ResourceConfig::create([
            'software_provider_id' => $this->softwareProvider->id,
            'scenic_spot_id' => $this->scenicSpotA->id,
            'username' => 'user_a',
            'password' => 'pass_a',
            'api_url' => 'https://api.hengdian.com',
            'environment' => 'production',
            'is_active' => true,
            'extra_config' => [
                'sync_mode' => [
                    'inventory' => 'push',
                    'price' => 'manual',
                    'order' => 'auto',
                ],
            ],
        ]);
        
        // 为景区B创建配置
        $this->configB = ResourceConfig::create([
            'software_provider_id' => $this->softwareProvider->id,
            'scenic_spot_id' => $this->scenicSpotB->id,
            'username' => 'user_b',
            'password' => 'pass_b',
            'api_url' => 'https://api.hengdian.com',
            'environment' => 'production',
            'is_active' => true,
            'extra_config' => [
                'sync_mode' => [
                    'inventory' => 'push',
                    'price' => 'manual',
                    'order' => 'auto',
                ],
            ],
        ]);
        
        // 更新景区的 resource_config_id
        $this->scenicSpotA->update(['resource_config_id' => $this->configA->id]);
        $this->scenicSpotB->update(['resource_config_id' => $this->configB->id]);
        
        // 创建酒店
        $this->hotelA = Hotel::create([
            'scenic_spot_id' => $this->scenicSpotA->id,
            'name' => '横店酒店A1',
            'code' => 'HOTEL001',
            'external_code' => 'HD001',
            'is_active' => true,
        ]);
        
        $this->hotelB = Hotel::create([
            'scenic_spot_id' => $this->scenicSpotB->id,
            'name' => '横店酒店B1',
            'code' => 'HOTEL003',
            'external_code' => 'HD003',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_identify_scenic_spot_by_hotel_no()
    {
        $request = Request::create('/api/webhooks/resource/hengdian/inventory', 'POST');
        $callbackData = ['hotelNo' => 'HD001'];
        
        $result = ScenicSpotIdentificationService::identify(
            $request,
            $callbackData,
            $this->softwareProvider->id
        );
        
        $this->assertNotNull($result);
        $this->assertEquals($this->scenicSpotA->id, $result['scenic_spot']->id);
        $this->assertEquals($this->configA->id, $result['config']->id);
        $this->assertEquals('hotelNo', $result['method']);
    }

    /** @test */
    public function test_identify_scenic_spot_by_username()
    {
        $request = Request::create('/api/webhooks/resource/hengdian/inventory', 'POST');
        $callbackData = ['username' => 'user_a'];
        
        $result = ScenicSpotIdentificationService::identify(
            $request,
            $callbackData,
            $this->softwareProvider->id
        );
        
        $this->assertNotNull($result);
        $this->assertEquals($this->scenicSpotA->id, $result['scenic_spot']->id);
        $this->assertEquals($this->configA->id, $result['config']->id);
        $this->assertEquals('username', $result['method']);
    }

    /** @test */
    public function test_identify_scenic_spot_by_order_no()
    {
        // 创建订单
        $order = Order::create([
            'order_no' => 'ORD001',
            'resource_order_no' => 'RES001',
            'hotel_id' => $this->hotelA->id,
            'product_id' => 1,
            'status' => 'paid_pending',
            'check_in_date' => now(),
            'check_out_date' => now()->addDay(),
            'contact_name' => 'Test',
            'contact_phone' => '13800138000',
            'total_amount' => 100,
        ]);
        
        $request = Request::create('/api/webhooks/resource/hengdian/inventory', 'POST');
        $callbackData = ['orderNo' => 'RES001'];
        
        $result = ScenicSpotIdentificationService::identify(
            $request,
            $callbackData,
            $this->softwareProvider->id
        );
        
        $this->assertNotNull($result);
        $this->assertEquals($this->scenicSpotA->id, $result['scenic_spot']->id);
        $this->assertEquals('orderNo', $result['method']);
    }

    /** @test */
    public function test_config_uniqueness_validation()
    {
        // 尝试为景区B创建与景区A相同的用户名配置
        $response = $this->actingAs($this->createAdminUser())
            ->postJson("/api/scenic-spots/{$this->scenicSpotB->id}/resource-config", [
                'username' => 'user_a', // 与景区A相同
                'password' => 'pass_b',
                'api_url' => 'https://api.example.com',
                'sync_mode' => [
                    'inventory' => 'push',
                    'price' => 'manual',
                    'order' => 'auto',
                ],
            ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    }

    /** @test */
    public function test_different_scenic_spots_have_different_configs()
    {
        $this->assertNotEquals(
            $this->configA->username,
            $this->configB->username
        );
        
        $this->assertEquals('user_a', $this->configA->username);
        $this->assertEquals('user_b', $this->configB->username);
    }

    /** @test */
    public function test_identify_returns_null_when_no_match()
    {
        $request = Request::create('/api/webhooks/resource/hengdian/inventory', 'POST');
        $callbackData = ['hotelNo' => 'UNKNOWN'];
        
        $result = ScenicSpotIdentificationService::identify(
            $request,
            $callbackData,
            $this->softwareProvider->id
        );
        
        $this->assertNull($result);
    }

    /** @test */
    public function test_resource_config_auth_methods()
    {
        // 测试获取认证类型
        $this->assertEquals('username_password', $this->configA->getAuthType());
        
        // 测试获取认证标识符
        $this->assertEquals('user_a', $this->configA->getAuthIdentifier());
        
        // 测试获取认证配置
        $authConfig = $this->configA->getAuthConfig();
        $this->assertEquals('user_a', $authConfig['username']);
        $this->assertEquals('pass_a', $authConfig['password']);
    }

    protected function createAdminUser()
    {
        // 创建测试管理员用户
        // 这里需要根据你的用户模型实现
        return \App\Models\User::factory()->create([
            'role' => 'admin',
        ]);
    }
}

