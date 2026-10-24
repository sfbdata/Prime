<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

/**
 * O que o destino remoto diz sobre um objeto. `sha256` é o que a réplica gravou como metadado ao
 * enviar — é ele que torna a idempotência e a reconciliação possíveis sem baixar o conteúdo.
 */
final readonly class MetadadosRemotos
{
    public function __construct(
        public string $chaveRemota,
        public int $tamanhoBytes,
        public ?string $sha256,
        public ?string $mimeType,
        public ?\DateTimeImmutable $atualizadoEm,
    ) {
    }

    /** O objeto remoto é o mesmo conteúdo do local? Só com SHA e tamanho batendo. */
    public function confereCom(int $tamanhoBytes, string $sha256): bool
    {
        return $this->tamanhoBytes === $tamanhoBytes && $this->sha256 !== null && hash_equals($this->sha256, $sha256);
    }
}
