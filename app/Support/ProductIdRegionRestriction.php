<?php

namespace App\Support;

use App\Enums\GuestIdType;
use App\Models\Product;

final class ProductIdRegionRestriction
{
    public const REJECTION_MESSAGE = '您的信息不符合产品规则';

    public const MP_REJECTION_MESSAGE = '预定信息不符合产品规则';

    public static function isEnabled(Product $product): bool
    {
        return (bool) $product->id_region_restriction_enabled
            && self::resolvedPrefixes($product) !== [];
    }

    /**
     * @return list<string>
     */
    public static function resolvedPrefixes(Product $product): array
    {
        $raw = $product->id_region_prefixes;
        if (! is_array($raw)) {
            return [];
        }

        $prefixes = [];
        foreach ($raw as $prefix) {
            $digits = preg_replace('/\D/', '', (string) $prefix);
            if ($digits !== '') {
                $prefixes[] = $digits;
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param list<string> $prefixes
     * @return list<string>
     */
    public static function sanitizePrefixesForStorage(array $prefixes): array
    {
        $clean = [];
        foreach ($prefixes as $prefix) {
            $digits = preg_replace('/\D/', '', (string) $prefix);
            if ($digits !== '' && strlen($digits) >= 2 && strlen($digits) <= 6) {
                $clean[] = $digits;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param list<string> $idCards
     */
    public static function validateOrMessage(Product $product, array $idCards): ?string
    {
        if (! self::isEnabled($product)) {
            return null;
        }

        $idCards = self::normalizeIdCards($idCards);
        if ($idCards === []) {
            return self::REJECTION_MESSAGE;
        }

        $prefixes = self::resolvedPrefixes($product);
        foreach ($idCards as $idCard) {
            if (! self::idCardMatchesAnyPrefix($idCard, $prefixes)) {
                return self::REJECTION_MESSAGE;
            }
        }

        return null;
    }

    /**
     * 小程序预约：启用地区限制时仅接受身份证，且号码前缀须命中配置。
     */
    public static function validateMpGuest(GuestIdType $guestIdType, string $idCard, ?Product $product): ?string
    {
        if ($product === null || ! self::isEnabled($product)) {
            return null;
        }

        if ($guestIdType !== GuestIdType::IdCard) {
            return self::MP_REJECTION_MESSAGE;
        }

        return self::validateOrMessage($product, [$idCard]) !== null
            ? self::MP_REJECTION_MESSAGE
            : null;
    }

    /**
     * @return array{id_region_restriction_enabled: bool, id_region_prefixes: list<string>}
     */
    public static function payloadForMp(Product $product): array
    {
        $enabled = self::isEnabled($product);

        return [
            'id_region_restriction_enabled' => $enabled,
            'id_region_prefixes' => $enabled ? self::resolvedPrefixes($product) : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $passengers
     * @return list<string>
     */
    public static function extractIdCardsFromCtripPassengers(array $passengers): array
    {
        $result = [];

        foreach ($passengers as $passenger) {
            if (! is_array($passenger)) {
                continue;
            }

            if (! self::isCtripIdCardCredentialType($passenger['cardType'] ?? $passenger['card_type'] ?? null)) {
                continue;
            }

            $cardNo = strtoupper(trim((string) ($passenger['cardNo'] ?? $passenger['card_no'] ?? '')));
            if ($cardNo !== '') {
                $result[] = $cardNo;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<int, array<string, mixed>> $contacts
     * @param array<int, array<string, mixed>> $credentialList
     * @return list<string>
     */
    public static function extractIdCardsFromMeituan(array $contacts, array $credentialList): array
    {
        $result = [];

        foreach ($credentialList as $credential) {
            if (! is_array($credential)) {
                continue;
            }

            if (intval($credential['credentialType'] ?? 0) !== 0) {
                continue;
            }

            $no = strtoupper(trim((string) ($credential['credentialNo'] ?? '')));
            if ($no !== '') {
                $result[] = $no;
            }
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $credentials = $contact['credentials'] ?? [];
            if (! is_array($credentials)) {
                continue;
            }

            foreach ($credentials as $type => $no) {
                if (! self::isMeituanIdCardCredentialType($type)) {
                    continue;
                }

                $no = strtoupper(trim((string) $no));
                if ($no !== '') {
                    $result[] = $no;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param list<string> $idCards
     * @return list<string>
     */
    private static function normalizeIdCards(array $idCards): array
    {
        $normalized = [];
        foreach ($idCards as $idCard) {
            $value = strtoupper(trim((string) $idCard));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $prefixes
     */
    private static function idCardMatchesAnyPrefix(string $idCard, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($idCard, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 携程 cardType：1=身份证, 2=护照, 0/null=无需证件（与美团 credentialType 不同）
     */
    private static function isCtripIdCardCredentialType(mixed $type): bool
    {
        if ($type === null || $type === '') {
            return true;
        }

        return (string) $type === '1';
    }

    /**
     * 美团 credentialType：0=身份证, 1=护照
     */
    private static function isMeituanIdCardCredentialType(mixed $type): bool
    {
        if ($type === null || $type === '') {
            return true;
        }

        return (string) $type === '0' || intval($type) === 0;
    }
}
