<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Sinergia\Infrastructure\Crypto\CryptoException;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Shared\Config\SensitiveValue;

final class SecretBoxTest extends TestCase
{
    public function testRoundTripAndCiphertextDoesNotContainPlaintext(): void
    {
        $box = $this->box();
        $encrypted = $box->encrypt(new SensitiveValue('APP_USR-plain-token'));

        self::assertStringNotContainsString('APP_USR-plain-token', $encrypted);
        self::assertStringNotContainsString('APP_USR-plain-token', (string) base64_decode($encrypted, true));
        self::assertSame('APP_USR-plain-token', $box->decrypt($encrypted)->reveal());
    }

    public function testNonceIsRandom(): void
    {
        $box = $this->box();

        self::assertNotSame($box->encrypt(new SensitiveValue('x')), $box->encrypt(new SensitiveValue('x')));
    }

    public function testTamperedCiphertextFails(): void
    {
        $box = $this->box();
        $raw = (string) base64_decode($box->encrypt(new SensitiveValue('secret')), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

        $this->expectException(CryptoException::class);
        $box->decrypt(base64_encode($raw));
    }

    public function testWrongKeyFails(): void
    {
        $encrypted = $this->box()->encrypt(new SensitiveValue('secret'));

        $this->expectException(CryptoException::class);
        $this->box()->decrypt($encrypted);
    }

    public function testRejectsBadKeyLength(): void
    {
        $this->expectException(CryptoException::class);
        new SecretBox(new SensitiveValue('short'));
    }

    private function box(): SecretBox
    {
        return new SecretBox(new SensitiveValue((string) base64_decode(SecretBox::generateKeyBase64(), true)));
    }
}
