<?php

namespace Tests\Unit;

use App\Services\PhoneNumberNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    private PhoneNumberNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PhoneNumberNormalizer();
    }

    public function test_normalizes_valid_indonesian_phone_formats(): void
    {
        $this->assertSame('6281234567890', $this->normalizer->normalize('0812-3456-7890'));
        $this->assertSame('6281234567890', $this->normalizer->normalize('+62 812 3456 7890'));
        $this->assertSame('6281234567890', $this->normalizer->normalize('81234567890'));
        $this->assertSame('6281234567890', $this->normalizer->normalize('6281234567890'));

        // Exact inclusive length boundaries: 10 and 15 digits
        $this->assertSame('6281234567', $this->normalizer->normalize('081234567'));
        $this->assertSame('628123456789012', $this->normalizer->normalize('628123456789012'));
    }

    public function test_rejects_invalid_lengths_and_prefixes(): void
    {
        $this->assertNull($this->normalizer->normalize(''));
        $this->assertNull($this->normalizer->normalize('not-a-phone'));
        $this->assertNull($this->normalizer->normalize('08123456')); // 9 digits after 62
        $this->assertNull($this->normalizer->normalize('6281234567890123')); // 16 digits
        $this->assertNull($this->normalizer->normalize('+1 555 123 4567'));
        $this->assertNull($this->normalizer->normalize('71234567890'));
        $this->assertNull($this->normalizer->normalize('98123456789'));
    }

    public function test_builds_whatsapp_jid_from_normalized_phone(): void
    {
        $this->assertSame(
            '6281234567890@s.whatsapp.net',
            $this->normalizer->toWaId('6281234567890')
        );
    }
}
