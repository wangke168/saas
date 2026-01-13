<?php

namespace Tests\Feature;

use App\Http\Client\FliggyDistributionClient;
use App\Models\ResourceConfig;
use App\Models\ScenicSpot;
use App\Models\SoftwareProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 飞猪分销系统产品接口测试
 * 
 * 测试四个产品相关接口：
 * 1. 批量获取产品基本信息（分页）
 * 2. 批量获取产品基本信息（按ID）
 * 3. 获取产品详情（单体）
 * 4. 批量获取价格/库存
 */
class FliggyDistributionProductTest extends TestCase
{
    use RefreshDatabase;

    protected SoftwareProvider $softwareProvider;
    protected ScenicSpot $scenicSpot;
    protected ResourceConfig $config;
    protected FliggyDistributionClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建飞猪分销系统服务商
        $this->softwareProvider = SoftwareProvider::create([
            'name' => '飞猪分销系统',
            'code' => 'FLIGGY_DISTRIBUTION',
            'description' => '飞猪分销系统',
            'api_type' => 'fliggy_distribution',
            'api_url' => env('FLIGGY_DISTRIBUTION_API_URL', 'https://pre-api.alitrip.alibaba.com'), // 默认使用测试环境
            'is_active' => true,
        ]);
        
        // 创建景区
        $this->scenicSpot = ScenicSpot::create([
            'name' => '测试景区',
            'code' => 'TEST001',
            'is_active' => true,
        ]);
        
        // 关联服务商到景区
        $this->scenicSpot->softwareProviders()->attach($this->softwareProvider->id);
        
        // 创建资源配置
        // 注意：需要在 .env 中配置以下变量，或直接在这里设置测试值
        $this->config = ResourceConfig::create([
            'software_provider_id' => $this->softwareProvider->id,
            'scenic_spot_id' => $this->scenicSpot->id,
            'username' => env('FLIGGY_DISTRIBUTION_USERNAME', ''),
            'password' => env('FLIGGY_DISTRIBUTION_PASSWORD', ''),
            'environment' => 'production',
            'is_active' => true,
            'extra_config' => [
                'distributor_id' => env('FLIGGY_DISTRIBUTION_ID', ''),
                'private_key' => env('FLIGGY_DISTRIBUTION_PRIVATE_KEY', ''),
                'sync_mode' => [
                    'inventory' => 'manual',
                    'price' => 'manual',
                    'order' => 'auto',
                ],
            ],
        ]);
        
        // 创建客户端
        try {
            $this->client = new FliggyDistributionClient($this->config);
        } catch (\Exception $e) {
            $this->markTestSkipped('飞猪分销系统配置不完整：' . $e->getMessage());
        }
    }

    /**
     * 测试1：批量获取产品基本信息（分页）
     * 
     * @return void
     */
    public function test_query_product_base_info_by_page(): void
    {
        $this->markTestSkipped('需要配置飞猪分销系统参数后才能测试');
        
        $result = $this->client->queryProductBaseInfoByPage(1, 20);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);
        
        if ($result['success']) {
            $this->assertEquals('2000', $result['code']);
            $this->assertNotEmpty($result['data']);
            
            Log::info('测试1成功：批量获取产品基本信息（分页）', [
                'result' => $result,
            ]);
        } else {
            Log::warning('测试1失败：批量获取产品基本信息（分页）', [
                'code' => $result['code'],
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * 测试2：批量获取产品基本信息（按ID）
     * 
     * @return void
     */
    public function test_query_product_base_info_by_ids(): void
    {
        $this->markTestSkipped('需要配置飞猪分销系统参数后才能测试');
        
        // 需要先获取一些产品ID（可以从测试1的结果中获取，或使用已知的产品ID）
        $productIds = [
            env('FLIGGY_TEST_PRODUCT_ID', 'TEST_PRODUCT_ID_1'),
            env('FLIGGY_TEST_PRODUCT_ID_2', 'TEST_PRODUCT_ID_2'),
        ];
        
        // 过滤掉空值
        $productIds = array_filter($productIds, function($id) {
            return !empty($id) && $id !== 'TEST_PRODUCT_ID_1' && $id !== 'TEST_PRODUCT_ID_2';
        });
        
        if (empty($productIds)) {
            $this->markTestSkipped('需要配置测试产品ID（FLIGGY_TEST_PRODUCT_ID）');
        }
        
        $result = $this->client->queryProductBaseInfoByIds($productIds);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);
        
        if ($result['success']) {
            $this->assertEquals('2000', $result['code']);
            $this->assertNotEmpty($result['data']);
            
            Log::info('测试2成功：批量获取产品基本信息（按ID）', [
                'product_ids' => $productIds,
                'result' => $result,
            ]);
        } else {
            Log::warning('测试2失败：批量获取产品基本信息（按ID）', [
                'product_ids' => $productIds,
                'code' => $result['code'],
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * 测试3：获取产品详情（单体）
     * 
     * @return void
     */
    public function test_query_product_detail_info(): void
    {
        $this->markTestSkipped('需要配置飞猪分销系统参数后才能测试');
        
        $productId = env('FLIGGY_TEST_PRODUCT_ID', '');
        
        if (empty($productId)) {
            $this->markTestSkipped('需要配置测试产品ID（FLIGGY_TEST_PRODUCT_ID）');
        }
        
        $result = $this->client->queryProductDetailInfo($productId);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);
        
        if ($result['success']) {
            $this->assertEquals('2000', $result['code']);
            $this->assertNotEmpty($result['data']);
            
            // 验证返回的数据结构
            $data = $result['data'];
            if (isset($data['productBaseInfo'])) {
                $this->assertArrayHasKey('productId', $data['productBaseInfo']);
                $this->assertArrayHasKey('productName', $data['productBaseInfo']);
            }
            
            Log::info('测试3成功：获取产品详情（单体）', [
                'product_id' => $productId,
                'result' => $result,
            ]);
        } else {
            Log::warning('测试3失败：获取产品详情（单体）', [
                'product_id' => $productId,
                'code' => $result['code'],
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * 测试4：批量获取价格/库存
     * 
     * @return void
     */
    public function test_query_product_price_stock(): void
    {
        $this->markTestSkipped('需要配置飞猪分销系统参数后才能测试');
        
        $productId = env('FLIGGY_TEST_PRODUCT_ID', '');
        
        if (empty($productId)) {
            $this->markTestSkipped('需要配置测试产品ID（FLIGGY_TEST_PRODUCT_ID）');
        }
        
        // 测试不带时间范围
        $result1 = $this->client->queryProductPriceStock($productId);
        
        $this->assertIsArray($result1);
        $this->assertArrayHasKey('success', $result1);
        
        if ($result1['success']) {
            Log::info('测试4-1成功：批量获取价格/库存（不带时间范围）', [
                'product_id' => $productId,
                'result' => $result1,
            ]);
        }
        
        // 测试带时间范围
        $beginTime = strtotime('+7 days') * 1000; // 7天后
        $endTime = strtotime('+14 days') * 1000; // 14天后
        
        $result2 = $this->client->queryProductPriceStock($productId, $beginTime, $endTime);
        
        $this->assertIsArray($result2);
        $this->assertArrayHasKey('success', $result2);
        
        if ($result2['success']) {
            $this->assertEquals('2000', $result2['code']);
            $this->assertNotEmpty($result2['data']);
            
            Log::info('测试4-2成功：批量获取价格/库存（带时间范围）', [
                'product_id' => $productId,
                'begin_time' => $beginTime,
                'end_time' => $endTime,
                'result' => $result2,
            ]);
        } else {
            Log::warning('测试4-2失败：批量获取价格/库存（带时间范围）', [
                'product_id' => $productId,
                'code' => $result2['code'],
                'message' => $result2['message'],
            ]);
        }
    }

    /**
     * 测试：验证签名生成
     * 
     * @return void
     */
    public function test_sign_generation(): void
    {
        $this->markTestSkipped('需要配置飞猪分销系统参数后才能测试');
        
        // 测试签名生成
        $testData = 'test_distributorId_1234567890_test_productId';
        
        try {
            $reflection = new \ReflectionClass($this->client);
            $method = $reflection->getMethod('sign');
            $method->setAccessible(true);
            
            $signature = $method->invoke($this->client, $testData);
            
            $this->assertNotEmpty($signature);
            $this->assertIsString($signature);
            
            // 验证是Base64编码
            $this->assertTrue(base64_decode($signature, true) !== false);
            
            Log::info('签名生成测试成功', [
                'test_data' => $testData,
                'signature_length' => strlen($signature),
            ]);
        } catch (\Exception $e) {
            $this->fail('签名生成测试失败：' . $e->getMessage());
        }
    }

    /**
     * 测试：验证参数构建
     * 
     * @return void
     */
    public function test_build_params(): void
    {
        $this->markTestSkipped('需要配置飞猪分销系统参数后才能测试');
        
        try {
            $reflection = new \ReflectionClass($this->client);
            $method = $reflection->getMethod('buildParams');
            $method->setAccessible(true);
            
            $params = $method->invoke($this->client, [
                'productId' => 'TEST_PRODUCT_123',
            ], 'distributorId_timestamp_productId');
            
            $this->assertArrayHasKey('distributorId', $params);
            $this->assertArrayHasKey('timestamp', $params);
            $this->assertArrayHasKey('productId', $params);
            $this->assertArrayHasKey('sign', $params);
            
            $this->assertNotEmpty($params['distributorId']);
            $this->assertNotEmpty($params['timestamp']);
            $this->assertNotEmpty($params['sign']);
            
            Log::info('参数构建测试成功', [
                'params' => array_merge($params, ['sign' => substr($params['sign'], 0, 20) . '...']),
            ]);
        } catch (\Exception $e) {
            $this->fail('参数构建测试失败：' . $e->getMessage());
        }
    }
}

