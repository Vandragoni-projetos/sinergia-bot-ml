<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre;

use Sinergia\Integration\MercadoLivre\Exception\InvalidArgumentException;

/** Validação de identificadores ANTES de qualquer chamada de rede. */
final class MlIds
{
    public static function assertSite(string $siteId): void
    {
        if (preg_match('/^[A-Z]{3}$/', $siteId) !== 1) {
            throw new InvalidArgumentException('Site inválido (esperado três letras maiúsculas, ex.: MLB).', 'invalid_site');
        }
    }

    public static function assertCategory(string $siteId, string $categoryId): void
    {
        self::assertSite($siteId);
        if (preg_match('/^[A-Z]{3}[0-9]{1,15}$/', $categoryId) !== 1) {
            throw new InvalidArgumentException('ID de categoria inválido (esperado ex.: MLB1234).', 'invalid_category_id');
        }
        if (!str_starts_with($categoryId, $siteId)) {
            throw new InvalidArgumentException('ID de categoria não pertence ao site informado.', 'category_site_mismatch');
        }
    }

    public static function assertAttributeFilter(?string $attribute, ?string $value): void
    {
        if (($attribute === null) !== ($value === null)) {
            throw new InvalidArgumentException('Filtro por atributo exige atributo e valor juntos.', 'invalid_attribute_filter');
        }
        if ($attribute !== null && preg_match('/^[A-Z0-9_]{1,64}$/', $attribute) !== 1) {
            throw new InvalidArgumentException('Atributo inválido (ex.: BRAND).', 'invalid_attribute');
        }
        if ($value !== null && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Valor de atributo inválido.', 'invalid_attribute_value');
        }
    }
}
