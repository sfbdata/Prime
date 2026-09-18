<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * O que o storage sabe sobre um arquivo que já está lá.
 *
 * Substitui os `filesize()` soltos que hoje vivem espalhados fora da abstração
 * (`CompressorArquivo.php:32`, `GoogleDriveClient.php:169`, `CopiarArquivosAcervoCommand.php:359`).
 *
 * `checksum` é **nullable de propósito** (D6): o campo existe para a E3 poder preencher sem mudar
 * o contrato, e o backend local **não** calcula SHA-256 na E2 — seria I/O completo a cada leitura
 * de metadado, sem nenhum consumidor hoje. Quem receber null deve entender "ainda não calculado",
 * nunca "arquivo sem integridade".
 */
final readonly class MetadadosDeArquivo
{
    public function __construct(
        public int $tamanhoBytes,
        public string $mimeType,
        public \DateTimeImmutable $atualizadoEm,
        public ?string $checksum = null,
    ) {
    }
}
