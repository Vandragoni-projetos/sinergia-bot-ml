<?php

declare(strict_types=1);

namespace Sinergia\Application\Validation;

use Sinergia\Shared\Security\Redactor;

/**
 * Grava a evidência bruta SANITIZADA de uma validação (fora do Git: storage/validation).
 * Serve para comparar DOCUMENTAÇÃO x RESPOSTA REAL sem guardar tokens, cookies ou secrets.
 */
final class EvidenceWriter
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $evidence */
    public function write(string $name, array $evidence): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Não foi possível criar o diretório de evidências.');
        }
        $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? 'evidence';
        $file = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $safeName . '.json';

        $json = json_encode(
            Redactor::redactArray($evidence),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        file_put_contents($file, Redactor::redactText($json) . "\n");

        return $file;
    }
}
