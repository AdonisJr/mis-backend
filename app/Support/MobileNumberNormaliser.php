<?php

namespace App\Support;

class MobileNumberNormaliser
{
    public const PATTERN = '/\A09\d{9}\z/';

    public const FORMAT_RULE = 'regex:'.self::PATTERN;

    public const FORMAT_MESSAGE = 'The mobile number must be 11 digits starting with 09.';

    private const SEPARATORS = [' ', '-', '.', '(', ')'];

    private const COUNTRY_PREFIX = '+63';

    public static function normalise(string $rawNumber): string
    {
        $strippedNumber = str_replace(self::SEPARATORS, '', trim($rawNumber));

        if (! str_starts_with($strippedNumber, self::COUNTRY_PREFIX)) {
            return $strippedNumber;
        }

        return '0'.substr($strippedNumber, strlen(self::COUNTRY_PREFIX));
    }

    public static function isMobileNumber(string $candidate): bool
    {
        return preg_match(self::PATTERN, $candidate) === 1;
    }
}
