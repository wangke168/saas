<?php

namespace App\Services\ExternalOrder;

use App\Enums\OtaPlatform;
use App\Models\Order;
use App\Models\Pkg\PkgOrder;
use App\Support\ExternalOrder\ExternalOrderRoute;
use Illuminate\Support\Collection;

class ExternalOrderPayloadBuilder
{
    /**
     * @return array<string, mixed>|null
     */
    public function buildCreatePayload(Order $order): ?array
    {
        $context = $this->resolveOrderContext($order);
        if ($context === null) {
            return null;
        }

        return array_merge($context, [
            'routeOrderStatus' => ExternalOrderRoute::STATUS_PENDING,
            'arriveDate' => $order->check_in_date?->format('Y-m-d') ?? '',
            'departDate' => $order->check_out_date?->format('Y-m-d') ?? '',
            'roomCount' => max(1, (int) $order->room_count),
            'contactName' => (string) ($order->contact_name ?? ''),
            'contactTel' => (string) ($order->contact_phone ?? ''),
            'guestName' => $this->buildGuestNameFromOrder($order),
            'guestTel' => (string) ($order->contact_phone ?? ''),
            'totalPrice' => (float) $order->total_amount,
            'purchasePrice' => (float) $order->settlement_amount,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildCreatePayloadForPkgOrder(PkgOrder $pkgOrder): ?array
    {
        $context = $this->resolvePkgOrderContext($pkgOrder);
        if ($context === null) {
            return null;
        }

        return array_merge($context, [
            'routeOrderStatus' => ExternalOrderRoute::STATUS_PENDING,
            'arriveDate' => $pkgOrder->check_in_date?->format('Y-m-d') ?? '',
            'departDate' => $pkgOrder->check_out_date?->format('Y-m-d') ?? '',
            'roomCount' => 1,
            'contactName' => (string) ($pkgOrder->contact_name ?? ''),
            'contactTel' => (string) ($pkgOrder->contact_phone ?? ''),
            'guestName' => (string) ($pkgOrder->contact_name ?? ''),
            'guestTel' => (string) ($pkgOrder->contact_phone ?? ''),
            'totalPrice' => (float) $pkgOrder->total_amount,
            'purchasePrice' => (float) $pkgOrder->settlement_amount,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildStatusUpdatePayload(Order $order, int $routeOrderStatus): ?array
    {
        $context = $this->resolveOrderContext($order, includeHotelFields: false);
        if ($context === null) {
            return null;
        }

        return array_merge($context, [
            'routeOrderStatus' => $routeOrderStatus,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildStatusUpdatePayloadForPkgOrder(PkgOrder $pkgOrder, int $routeOrderStatus): ?array
    {
        $context = $this->resolvePkgOrderContext($pkgOrder, includeHotelFields: false);
        if ($context === null) {
            return null;
        }

        return array_merge($context, [
            'routeOrderStatus' => $routeOrderStatus,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveOrderContext(Order $order, bool $includeHotelFields = true): ?array
    {
        $order->loadMissing(['otaPlatform', 'hotel', 'roomType', 'product']);

        $platformCode = $this->resolveSupportedPlatformCode($order->otaPlatform?->code);
        if ($platformCode === null) {
            return null;
        }

        $payload = [
            'routeId' => $this->routeIdForPlatform($platformCode),
            'routeOrderId' => (string) ($order->ota_order_no ?? ''),
            'sourceOrderId' => (string) $order->order_no,
            'routeCode' => $this->routeCodeForPlatform($platformCode),
        ];

        if ($includeHotelFields) {
            $payload['routeHotelName'] = (string) ($order->hotel?->name ?? '');
            $payload['routeHotelTel'] = (string) ($order->hotel?->contact_phone ?? '');
            $payload['routeRoomTypeName'] = (string) ($order->roomType?->name ?? '');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePkgOrderContext(PkgOrder $pkgOrder, bool $includeHotelFields = true): ?array
    {
        $pkgOrder->loadMissing(['otaPlatform', 'hotel', 'roomType']);

        $platformCode = $this->resolveSupportedPlatformCode($pkgOrder->otaPlatform?->code);
        if ($platformCode === null) {
            return null;
        }

        $payload = [
            'routeId' => $this->routeIdForPlatform($platformCode),
            'routeOrderId' => (string) ($pkgOrder->ota_order_no ?? ''),
            'sourceOrderId' => (string) $pkgOrder->order_no,
            'routeCode' => $this->routeCodeForPlatform($platformCode),
        ];

        if ($includeHotelFields) {
            $payload['routeHotelName'] = (string) ($pkgOrder->hotel?->name ?? '');
            $payload['routeHotelTel'] = (string) ($pkgOrder->hotel?->contact_phone ?? '');
            $payload['routeRoomTypeName'] = (string) ($pkgOrder->roomType?->name ?? '');
        }

        return $payload;
    }

    private function resolveSupportedPlatformCode(mixed $platformCode): ?OtaPlatform
    {
        if ($platformCode instanceof OtaPlatform) {
            return in_array($platformCode, [OtaPlatform::CTRIP, OtaPlatform::MEITUAN], true)
                ? $platformCode
                : null;
        }

        if (is_string($platformCode)) {
            $enum = OtaPlatform::tryFrom($platformCode);

            return $enum !== null && in_array($enum, [OtaPlatform::CTRIP, OtaPlatform::MEITUAN], true)
                ? $enum
                : null;
        }

        return null;
    }

    private function routeIdForPlatform(OtaPlatform $platform): string
    {
        return match ($platform) {
            OtaPlatform::MEITUAN => ExternalOrderRoute::ROUTE_ID_MEITUAN,
            OtaPlatform::CTRIP => ExternalOrderRoute::ROUTE_ID_CTRIP,
            default => '',
        };
    }

    private function routeCodeForPlatform(OtaPlatform $platform): string
    {
        return match ($platform) {
            OtaPlatform::MEITUAN => ExternalOrderRoute::ROUTE_CODE_MEITUAN,
            OtaPlatform::CTRIP => ExternalOrderRoute::ROUTE_CODE_CTRIP,
            default => '',
        };
    }

    private function buildGuestNameFromOrder(Order $order): string
    {
        $guestInfo = $order->guest_info;
        if (! is_array($guestInfo) || $guestInfo === []) {
            return (string) ($order->contact_name ?? '');
        }

        /** @var Collection<int, string> $names */
        $names = collect($guestInfo)
            ->map(function (mixed $guest): ?string {
                if (! is_array($guest)) {
                    return null;
                }

                $name = trim((string) ($guest['name'] ?? ''));

                return $name !== '' ? $name : null;
            })
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return (string) ($order->contact_name ?? '');
        }

        return $names->implode(',');
    }
}
