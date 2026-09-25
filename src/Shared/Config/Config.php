<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

/**
 * Configuração tipada lida do ambiente.
 *
 * Regras:
 *  - nenhum segredo tem valor padrão no código;
 *  - erros citam só o nome da variável;
 *  - segredos saem como SensitiveValue.
 */
final class Config
{
    public const array ENVIRONMENTS = ['production', 'staging', 'local', 'test'];

    private const string ML_API_BASE_URL = 'https://api.mercadolibre.com';
    private const string ML_AUTH_BASE_URL = 'https://auth.mercadolivre.com.br';

    /** @param array<string, string> $env */
    private function __construct(private readonly array $env)
    {
    }

    /** @param array<string, string> $env */
    public static function fromArray(array $env): self
    {
        $config = new self($env);
        // Validação mínima que vale para qualquer entrypoint (web, CLI, testes).
        $config->appEnv();

        return $config;
    }

    public function appEnv(): string
    {
        $value = $this->requireString('APP_ENV');
        if (!in_array($value, self::ENVIRONMENTS, true)) {
            throw ConfigException::invalid('APP_ENV', implode('|', self::ENVIRONMENTS));
        }

        return $value;
    }

    public function isProduction(): bool
    {
        return $this->appEnv() === 'production';
    }

    public function isTest(): bool
    {
        return $this->appEnv() === 'test';
    }

    public function appDebug(): bool
    {
        // Debug nunca é ligado em produção, mesmo que a variável peça.
        return !$this->isProduction() && $this->bool('APP_DEBUG', false);
    }

    public function appVersion(): string
    {
        $version = $this->optionalString('APP_VERSION') ?? 'dev';

        return preg_match('/^[A-Za-z0-9._+-]{1,40}$/', $version) === 1 ? $version : 'dev';
    }

    public function appKey(): SensitiveValue
    {
        $raw = $this->requireString('APP_KEY');
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw ConfigException::invalid('APP_KEY', 'base64 de 32 bytes');
        }

        return new SensitiveValue($decoded);
    }

    public function installationSlug(): string
    {
        $slug = $this->optionalString('INSTALLATION_SLUG') ?? 'default';
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw ConfigException::invalid('INSTALLATION_SLUG', 'slug minúsculo [a-z0-9-]');
        }

        return $slug;
    }

    public function database(string $prefix = 'DB_'): DatabaseConfig
    {
        $missing = [];
        foreach (['HOST', 'DATABASE', 'USERNAME'] as $suffix) {
            if ($this->optionalString($prefix . $suffix) === null) {
                $missing[] = $prefix . $suffix;
            }
        }
        if (!array_key_exists($prefix . 'PASSWORD', $this->env)) {
            $missing[] = $prefix . 'PASSWORD';
        }
        if ($missing !== []) {
            throw ConfigException::missing($missing);
        }

        return new DatabaseConfig(
            host: (string) $this->optionalString($prefix . 'HOST'),
            port: $this->int($prefix . 'PORT', 3306, 1, 65535),
            database: (string) $this->optionalString($prefix . 'DATABASE'),
            username: (string) $this->optionalString($prefix . 'USERNAME'),
            password: new SensitiveValue($this->env[$prefix . 'PASSWORD'] ?? ''),
        );
    }

    public function hasDatabase(string $prefix = 'DB_'): bool
    {
        return $this->optionalString($prefix . 'HOST') !== null
            && $this->optionalString($prefix . 'DATABASE') !== null;
    }

    public function mercadoLivre(): MercadoLivreConfig
    {
        return new MercadoLivreConfig(
            siteId: $this->siteId(),
            apiBaseUrl: self::ML_API_BASE_URL,
            authBaseUrl: self::ML_AUTH_BASE_URL,
            timeoutSeconds: $this->int('ML_HTTP_TIMEOUT_SECONDS', 15, 1, 120),
            userAgent: 'SinergiaBotML/' . $this->appVersion(),
        );
    }

    public function mercadoLivreOAuth(): MercadoLivreOAuthConfig
    {
        $missing = array_values(array_filter(
            ['ML_CLIENT_ID', 'ML_CLIENT_SECRET', 'ML_REDIRECT_URI'],
            fn (string $k): bool => $this->optionalString($k) === null,
        ));
        if ($missing !== []) {
            throw ConfigException::missing($missing);
        }

        $redirect = (string) $this->optionalString('ML_REDIRECT_URI');
        $parts = parse_url($redirect);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['query'])) {
            throw ConfigException::invalid('ML_REDIRECT_URI', 'URL https sem parâmetros variáveis');
        }

        $clientId = (string) $this->optionalString('ML_CLIENT_ID');
        if (preg_match('/^[0-9]{4,32}$/', $clientId) !== 1) {
            throw ConfigException::invalid('ML_CLIENT_ID', 'identificador numérico da aplicação');
        }

        return new MercadoLivreOAuthConfig(
            clientId: $clientId,
            clientSecret: new SensitiveValue((string) $this->optionalString('ML_CLIENT_SECRET')),
            redirectUri: $redirect,
            pkceEnabled: $this->bool('ML_PKCE_ENABLED', true),
        );
    }

    public function hasUazapi(): bool
    {
        return $this->optionalString('UAZAPI_BASE_URL') !== null && $this->optionalString('UAZAPI_ADMIN_TOKEN') !== null;
    }

    public function uazapi(): UazapiConfig
    {
        $missing = array_values(array_filter(
            ['UAZAPI_BASE_URL', 'UAZAPI_ADMIN_TOKEN'],
            fn (string $k): bool => $this->optionalString($k) === null,
        ));
        if ($missing !== []) {
            throw ConfigException::missing($missing);
        }

        $url = rtrim((string) $this->optionalString('UAZAPI_BASE_URL'), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['query']) || isset($parts['user'])) {
            throw ConfigException::invalid('UAZAPI_BASE_URL', 'URL https do servidor, sem parâmetros');
        }

        return new UazapiConfig(
            baseUrl: $url,
            adminToken: new SensitiveValue((string) $this->optionalString('UAZAPI_ADMIN_TOKEN')),
            timeoutSeconds: $this->int('UAZAPI_HTTP_TIMEOUT_SECONDS', 15, 1, 60),
        );
    }

    public function siteId(): string
    {
        $site = $this->optionalString('ML_SITE_ID') ?? 'MLB';
        if (preg_match('/^[A-Z]{3}$/', $site) !== 1) {
            throw ConfigException::invalid('ML_SITE_ID', 'três letras maiúsculas, ex.: MLB');
        }

        return $site;
    }

    private function requireString(string $key): string
    {
        $value = $this->optionalString($key);
        if ($value === null) {
            throw ConfigException::missing([$key]);
        }

        return $value;
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->env[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->optionalString($key);
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw ConfigException::invalid($key, 'true|false'),
        };
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->optionalString($key);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^-?[0-9]+$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            throw ConfigException::invalid($key, sprintf('inteiro entre %d e %d', $min, $max));
        }

        return (int) $value;
    }
}
