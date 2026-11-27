<?php

namespace App\Support;

class TemporaryPasswordGenerator
{
    public const LENGTH = 10;

    // no 0/O/1/l/I so a password read out loud or off paper is not misread
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    public static function generate(): string
    {
        $password = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $password;
    }
}
