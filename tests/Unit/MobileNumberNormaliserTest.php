<?php

namespace Tests\Unit;

use App\Support\MobileNumberNormaliser;
use PHPUnit\Framework\TestCase;

class MobileNumberNormaliserTest extends TestCase
{
    public function test_it_strips_spaces_dashes_dots_and_parentheses(): void
    {
        $this->assertSame('09170000001', MobileNumberNormaliser::normalise(' 0917 000-0001 '));
        $this->assertSame('09170000001', MobileNumberNormaliser::normalise('(0917).000.0001'));
    }

    public function test_it_replaces_a_leading_plus_63_with_zero(): void
    {
        $this->assertSame('09170000001', MobileNumberNormaliser::normalise('+639170000001'));
        $this->assertSame('09170000001', MobileNumberNormaliser::normalise('+63 917 000 0001'));
    }

    public function test_it_leaves_an_lrn_unchanged(): void
    {
        $this->assertSame('100000000001', MobileNumberNormaliser::normalise('100000000001'));
    }

    public function test_pattern_accepts_only_09_and_nine_digits(): void
    {
        $this->assertTrue(MobileNumberNormaliser::isMobileNumber('09170000001'));
        $this->assertFalse(MobileNumberNormaliser::isMobileNumber('0917000000'));
        $this->assertFalse(MobileNumberNormaliser::isMobileNumber('091700000011'));
        $this->assertFalse(MobileNumberNormaliser::isMobileNumber('08170000001'));
        $this->assertFalse(MobileNumberNormaliser::isMobileNumber('091234567890'));
    }
}
