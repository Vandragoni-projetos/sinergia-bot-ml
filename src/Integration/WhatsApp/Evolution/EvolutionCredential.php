<?php

declare(strict_types=1);

namespace Sinergia\Integration\WhatsApp\Evolution;

use Sinergia\Shared\Config\SensitiveValue;

/**
 * Credencial de UMA instância da Evolution API, no formato versionado "evo1:<instanceName>:<instanceToken>".
 *
 * - O conjunto inteiro é segredo: circula só como SensitiveValue e é gravado cifrado (SecretBox) no campo
 *   instance_token_enc que já existe — o resto do BotML continua vendo "um token".
 * - Nome: [a-z0-9-], 3 a 64, começando por letra/dígito (é também o nome usado nas rotas /{instanceName}).
 * - Token: [A-Za-z0-9._-], 8 a 256 (a Evolution 2.3.7 gera UUID em maiúsculas); nunca contém ":".
 * - Mensagens de erro são genéricas: nunca citam o token nem a entrada recebida.
 */
final class EvolutionCredential
{
    public const string PREFIX = 'evo1';
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{2,63}$/D';
    private const string TOKEN_PATTERN = '/^[A-Za-z0-9._-]{8,256}$/D';

    private function __construct(
        public readonly string $instanceName,
        public readonly SensitiveValue $token,
    ) {
    }

    /** @throws \InvalidArgumentException com mensagem genérica */
    public static function compose(string $instanceName, SensitiveValue $token): SensitiveValue
    {
        if (!self::isValidName($instanceName)) {
            throw new \InvalidArgumentException('Nome de instância inválido: use só letras minúsculas, números e hífen (3 a 64).');
        }
        if (preg_match(self::TOKEN_PATTERN, $token->reveal()) !== 1) {
            throw new \InvalidArgumentException('Token de instância em formato inválido.');
        }

        return new SensitiveValue(self::PREFIX . ':' . $instanceName . ':' . $token->reveal());
    }

    /** @throws \InvalidArgumentException com mensagem genérica */
    public static function parse(SensitiveValue $credential): self
    {
        $parts = explode(':', $credential->reveal());
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || !self::isValidName($parts[1]) || preg_match(self::TOKEN_PATTERN, $parts[2]) !== 1) {
            throw new \InvalidArgumentException('Credencial da instância Evolution inválida.');
        }

        return new self($parts[1], new SensitiveValue($parts[2]));
    }

    public static function isValidName(string $instanceName): bool
    {
        return preg_match(self::NAME_PATTERN, $instanceName) === 1;
    }
}
