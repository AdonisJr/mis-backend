<?php

namespace Tests\Unit;

use App\Support\TemporaryPasswordGenerator;
use PHPUnit\Framework\TestCase;

class TemporaryPasswordGeneratorTest extends TestCase
{
    private const SAMPLE_SIZE = 50;

    public function test_password_has_the_fixed_length_and_readable_alphabet(): void
    {
        for ($sample = 0; $sample < self::SAMPLE_SIZE; $sample++) {
            $this->assertMatchesRegularExpression('/^['.TemporaryPasswordGenerator::ALPHABET.']{'.TemporaryPasswordGenerator::LENGTH.'}$/', TemporaryPasswordGenerator::generate());
        }
    }

    public function test_passwords_are_distinct(): void
    {
        $passwords = [];

        for ($sample = 0; $sample < self::SAMPLE_SIZE; $sample++) {
            $passwords[] = TemporaryPasswordGenerator::generate();
        }

        $this->assertCount(self::SAMPLE_SIZE, array_unique($passwords));
    }
}
