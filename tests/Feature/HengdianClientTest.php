<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Client\HengdianClient;
use App\Models\ResourceConfig;
use App\Models\SoftwareProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 横店 HengdianClient：使用 Http::fake 校验请求 XML 与响应解析，不访问数据库与外网。
 *
 * 与 FliggyDistributionProductTest 类似：自动化测试覆盖客户端行为；真实联调请用：
 *   php artisan test:hengdian-api ...
 *   php artisan test:hengdian-book ...
 */
class HengdianClientTest extends TestCase
{
    protected const FAKE_URL = 'http://hengdian.test.local/Interface/hotel_order.aspx';

    protected ResourceConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = new SoftwareProvider([
            'api_url' => self::FAKE_URL,
            'api_type' => 'hengdian',
        ]);

        $this->config = new ResourceConfig([
            'username' => 'test_user',
            'password' => 'test_pass',
            'environment' => 'production',
            'is_active' => true,
            'extra_config' => [],
        ]);
        $this->config->setRelation('softwareProvider', $provider);
    }

    public function test_validate_sends_validate_rq_and_parses_result(): void
    {
        Http::fake([
            self::FAKE_URL => Http::response(
                '<Result><Message>ok</Message><ResultCode>0</ResultCode><InventoryPrice>[]</InventoryPrice></Result>',
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        $client = new HengdianClient($this->config);
        $result = $client->validate([
            'HotelId' => '001',
            'RoomType' => '标准间',
            'CheckIn' => '2026-06-01',
            'CheckOut' => '2026-06-03',
            'RoomNum' => 1,
            'CustomerNumber' => 2,
            'PaymentType' => 1,
            'PackageId' => '',
            'Extensions' => '{}',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $body = html_entity_decode($request->body(), ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return str_contains($body, '<ValidateRQ>')
                && str_contains($body, '<Username>test_user</Username>')
                && str_contains($body, '<HotelId>001</HotelId>')
                && str_contains($body, '<RoomType>标准间</RoomType>');
        });
    }

    public function test_validate_supports_order_guests_with_id_type(): void
    {
        Http::fake([
            self::FAKE_URL => Http::response(
                '<Result><Message>ok</Message><ResultCode>0</ResultCode><InventoryPrice>[]</InventoryPrice></Result>',
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        $client = new HengdianClient($this->config);
        $result = $client->validate([
            'HotelId' => '001',
            'RoomType' => '标准间',
            'CheckIn' => '2026-06-01',
            'CheckOut' => '2026-06-03',
            'RoomNum' => 1,
            'CustomerNumber' => 2,
            'PaymentType' => 1,
            'OrderGuests' => [
                'OrderGuest' => [
                    ['Name' => 'Alice', 'IdType' => '1', 'IdCode' => 'P12345678'],
                ],
            ],
            'Extensions' => '{}',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $body = html_entity_decode($request->body(), ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return str_contains($body, '<ValidateRQ>')
                && str_contains($body, '<OrderGuests>')
                && str_contains($body, '<OrderGuest>')
                && str_contains($body, '<IdType>1</IdType>')
                && str_contains($body, '<IdCode>P12345678</IdCode>');
        });
    }

    public function test_book_sends_order_guests(): void
    {
        Http::fake([
            self::FAKE_URL => Http::response(
                '<Result><Message>创建订单成功</Message><ResultCode>0</ResultCode><OrderId>HD123</OrderId><OtaOrderId>OTA1</OtaOrderId></Result>',
                200
            ),
        ]);

        $client = new HengdianClient($this->config);
        $result = $client->book([
            'OtaOrderId' => '999',
            'HotelId' => '001',
            'RoomType' => '标准间',
            'CheckIn' => '2026-06-01',
            'CheckOut' => '2026-06-02',
            'Amount' => 100.50,
            'RoomNum' => 1,
            'PaymentType' => 1,
            'ContactName' => '张三',
            'ContactTel' => '13800000000',
            'OrderGuests' => [
                'OrderGuest' => [
                    ['Name' => '张三', 'IdType' => '0', 'IdCode' => '110101199001011234'],
                ],
            ],
            'Comment' => '',
            'Extensions' => '{}',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $body = html_entity_decode($request->body(), ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return str_contains($body, '<BookRQ>')
                && str_contains($body, '<OrderGuests>')
                && str_contains($body, '<OrderGuest>')
                && str_contains($body, '<Amount>100.5</Amount>')
                && str_contains($body, '<Name>张三</Name>')
                && str_contains($body, '<IdType>0</IdType>')
                && str_contains($body, '<IdCode>110101199001011234</IdCode>');
        });
    }

    public function test_query_parses_status(): void
    {
        Http::fake([
            self::FAKE_URL => Http::response(
                '<Result><Message/><ResultCode>0</ResultCode><Status>1</Status><OrderId>V1</OrderId><OtaOrderId>OTA1</OtaOrderId><Amount>0</Amount></Result>',
                200
            ),
        ]);

        $client = new HengdianClient($this->config);
        $result = $client->query(['OtaOrderId' => 'OTA1']);

        $this->assertTrue($result['success']);
        $this->assertInstanceOf(\SimpleXMLElement::class, $result['data']);
        $this->assertSame('1', (string) $result['data']->Status);
    }

    public function test_cancel_parses_hotel_cancel_response(): void
    {
        Http::fake([
            self::FAKE_URL => Http::response(
                '<HotelCancelResponse><Message>成功</Message><ResultCode>0</ResultCode></HotelCancelResponse>',
                200
            ),
        ]);

        $client = new HengdianClient($this->config);
        $result = $client->cancel([
            'OtaOrderId' => 'OTA1',
            'Reason' => '用户取消',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => str_contains($r->body(), '<CancelRQ>'));
    }

    public function test_subscribe_room_status_xml_structure_matches_document(): void
    {
        Http::fake([
            self::FAKE_URL => Http::response(
                '<Result><Message>成功</Message><ResultCode>0</ResultCode></Result>',
                200
            ),
        ]);

        $client = new HengdianClient($this->config);
        $data = [
            'NotifyUrl' => 'http://example.com/hook',
            'IsUnsubscribe' => '0',
            'Hotels' => [
                'Hotel' => [
                    [
                        'HotelId' => '001',
                        'Rooms' => [
                            'RoomType' => ['标准间', '单人间'],
                        ],
                    ],
                ],
            ],
            'Extensions' => '{}',
        ];

        $result = $client->subscribeRoomStatus($data);

        $this->assertTrue($result['success']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $body = html_entity_decode($request->body(), ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return str_contains($body, '<SubscribeRoomStatusRQ>')
                && str_contains($body, '<Hotels>')
                && str_contains($body, '<HotelId>001</HotelId>')
                && str_contains($body, '<RoomType>标准间</RoomType>')
                && str_contains($body, '<RoomType>单人间</RoomType>');
        });
    }
}
