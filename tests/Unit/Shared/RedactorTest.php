<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Shared;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Shared\Security\Redactor;

final class RedactorTest extends TestCase
{
    public function testRedactsKnownTokenShapes(): void
    {
        $text = 'Authorization: Bearer APP_USR-123-456-abc-789 refresh=TG-5b9032b4e2 url?access_token=xyz&code=TG-abc&state=s1&error_code=keep';
        $out = Redactor::redactText($text);

        self::assertStringNotContainsString('APP_USR-123', $out);
        self::assertStringNotContainsString('TG-5b9032b4e2', $out);
        self::assertStringNotContainsString('access_token=xyz', $out);
        self::assertStringNotContainsString('state=s1', $out);
        self::assertStringContainsString('error_code=keep', $out, 'error_code não é segredo');
    }

    public function testRedactsSensitiveKeysRecursively(): void
    {
        $out = Redactor::redactArray([
            'client_secret' => 'abc',
            'nested' => ['refresh_token' => 'def', 'status_code' => 200, 'code' => 'TG-x'],
            'value' => new SensitiveValue('ghi'),
            'Authorization' => 'Bearer zzz',
            'error_code' => 'not_found',
        ]);

        self::assertSame(Redactor::MASK, $out['client_secret']);
        self::assertSame(Redactor::MASK, $out['nested']['refresh_token']);
        self::assertSame(Redactor::MASK, $out['nested']['code']);
        self::assertSame(200, $out['nested']['status_code']);
        self::assertSame(Redactor::MASK, $out['value']);
        self::assertSame(Redactor::MASK, $out['Authorization']);
        self::assertSame('not_found', $out['error_code']);
    }

    public function testLoggerAppliesRedaction(): void
    {
        $handler = new TestHandler();
        $logger = LoggerFactory::create('local', $handler);
        $logger->info('token APP_USR-999-888-secret', ['access_token' => 'APP_USR-1', 'password' => 'x', 'endpoint' => '/categories/MLB1']);

        $record = $handler->getRecords()[0];
        self::assertStringNotContainsString('APP_USR-999', $record->message);
        self::assertSame(Redactor::MASK, $record->context['access_token']);
        self::assertSame(Redactor::MASK, $record->context['password']);
        self::assertSame('/categories/MLB1', $record->context['endpoint']);
    }
}
