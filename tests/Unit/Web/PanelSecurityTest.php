<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Web\Security\ClientIp;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\Security\PanelCookies;

final class PanelSecurityTest extends TestCase
{
    public function testCsrfTokenIsBoundToItsContext(): void
    {
        $csrf = new Csrf(new SensitiveValue(random_bytes(32)));
        $token = $csrf->tokenFor('sess|abc');

        self::assertTrue($csrf->isValid('sess|abc', $token));
        self::assertFalse($csrf->isValid('sess|outra-sessao', $token), 'Token de uma sessão não vale em outra');
        self::assertFalse($csrf->isValid('sess|abc', null));
        self::assertFalse($csrf->isValid('sess|abc', $token . 'x'));
        self::assertFalse($csrf->isValid('', $token));
        self::assertFalse((new Csrf(new SensitiveValue(random_bytes(32))))->isValid('sess|abc', $token), 'Outra chave, outro token');
    }

    public function testCookiesAreHttpOnlyLaxAndSecureOutsideLocal(): void
    {
        $response = (new PanelCookies(true))->set((new ResponseFactory())->createResponse(), PanelCookies::SESSION, 'valor', 604800);
        $cookie = $response->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('sbm_session=valor', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringContainsString('Max-Age=604800', $cookie);
        self::assertStringContainsString('Secure', $cookie);

        $local = (new PanelCookies(false))->clear((new ResponseFactory())->createResponse(), PanelCookies::SESSION)->getHeaderLine('Set-Cookie');
        self::assertStringNotContainsString('Secure', $local);
        self::assertStringContainsString('Max-Age=0', $local);
    }

    public function testClientIpUsesLastForwardedEntryOnlyBehindPrivateProxy(): void
    {
        $factory = new ServerRequestFactory();

        $behindProxy = $factory->createServerRequest('POST', '/entrar', ['REMOTE_ADDR' => '10.0.1.5'])
            ->withHeader('X-Forwarded-For', '1.2.3.4, 200.150.10.20');
        self::assertSame('200.150.10.20', ClientIp::from($behindProxy), 'Último item = acrescentado pelo proxy');

        $direct = $factory->createServerRequest('POST', '/entrar', ['REMOTE_ADDR' => '200.150.10.99'])
            ->withHeader('X-Forwarded-For', '9.9.9.9');
        self::assertSame('200.150.10.99', ClientIp::from($direct), 'Cliente direto não escolhe o próprio IP');

        $garbage = $factory->createServerRequest('POST', '/entrar', ['REMOTE_ADDR' => '10.0.1.5'])
            ->withHeader('X-Forwarded-For', 'nao-e-ip');
        self::assertSame('10.0.1.5', ClientIp::from($garbage));
    }

    public function testPasswordHashingAndPolicy(): void
    {
        $hasher = new PasswordHasher();
        $password = new SensitiveValue('senha-forte-de-teste-123');
        $hash = $hasher->hash($password);

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify($password, $hash));
        self::assertFalse($hasher->verify(new SensitiveValue('outra-senha-qualquer'), $hash));
        self::assertNotSame([], PasswordHasher::policyViolations(new SensitiveValue('curta')));
        self::assertSame([], PasswordHasher::policyViolations($password));
        self::assertSame([], PasswordHasher::policyViolations(PasswordHasher::generate()), 'Senha gerada atende à política');
    }
}
