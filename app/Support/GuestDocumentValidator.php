<?php

namespace App\Support;

use App\Enums\GuestIdType;

final class GuestDocumentValidator
{
    /**
     * @return array{guest_phone: string, guest_id_card: string, guest_id_type: GuestIdType}|null
     */
    public static function normalize(
        string $phone,
        string $idType,
        string $idNumber,
    ): ?array {
        $phone = trim($phone);
        $idNumber = strtoupper(trim($idNumber));
        $type = GuestIdType::tryFrom($idType) ?? GuestIdType::IdCard;

        if (! self::isValidMobile($phone)) {
            return null;
        }

        if ($type === GuestIdType::IdCard) {
            if (! self::isValidIdCard($idNumber)) {
                return null;
            }
        } elseif (! self::isValidPassport($idNumber)) {
            return null;
        }

        return [
            'guest_phone' => $phone,
            'guest_id_card' => $idNumber,
            'guest_id_type' => $type,
        ];
    }

    public static function validationMessage(string $phone, string $idType, string $idNumber): ?string
    {
        $phone = trim($phone);
        $idNumber = strtoupper(trim($idNumber));
        $type = GuestIdType::tryFrom($idType) ?? GuestIdType::IdCard;

        if (! self::isValidMobile($phone)) {
            return '请填写正确的中国大陆手机号';
        }

        if ($type === GuestIdType::IdCard) {
            if (! self::isValidIdCard($idNumber)) {
                return '请填写正确的18位身份证号码';
            }

            return null;
        }

        if (! self::isValidPassport($idNumber)) {
            return '请填写正确的护照号码';
        }

        return null;
    }

    public static function isValidMobile(string $phone): bool
    {
        return (bool) preg_match('/^1[3-9]\d{9}$/', trim($phone));
    }

    public static function isValidIdCard(string $idCard): bool
    {
        $idCard = strtoupper(trim($idCard));
        if (! preg_match('/^\d{17}[\dX]$/', $idCard)) {
            return false;
        }

        $birth = substr($idCard, 6, 8);
        $birthDate = \DateTimeImmutable::createFromFormat('Ymd', $birth);
        if ($birthDate === false || $birthDate->format('Ymd') !== $birth) {
            return false;
        }

        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $codes = '10X98765432';
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int) $idCard[$i] * $weights[$i];
        }

        return $codes[$sum % 11] === $idCard[17];
    }

    public static function isValidPassport(string $passport): bool
    {
        $passport = strtoupper(trim($passport));

        return (bool) preg_match('/^[A-Z][A-Z0-9]{5,17}$/', $passport);
    }
}
