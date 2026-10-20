<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Entity\RecursoEstado;

/**
 * Estado EFETIVO de uma chave do catálogo (R4 já aplicado): o que a tela do Master, o menu e o
 * guarda de rota enxergam. Sem linha gravada = ativo, sem motivo e sem data.
 */
final readonly class EstadoDoRecursoOutput
{
    public function __construct(
        public string $chave,
        public bool $ativo,
        public ?string $motivo = null,
        /** AAAA-MM-DD, ou null sem previsão. */
        public ?string $ate = null,
    ) {
    }

    public static function ativo(string $chave): self
    {
        return new self($chave, true);
    }

    public static function fromEntity(RecursoEstado $estado, \DateTimeImmutable $hoje): self
    {
        if ($estado->estaAtivoEm($hoje)) {
            return self::ativo($estado->getChave());
        }

        return new self($estado->getChave(), false, $estado->getMotivo(), $estado->getAte()?->format('Y-m-d'));
    }

    /** Previsão no formato da tela (dd/mm/aaaa), ou null. */
    public function previsao(): ?string
    {
        return $this->ate !== null ? implode('/', array_reverse(explode('-', $this->ate))) : null;
    }

    /** @return array{chave: string, ativo: bool, motivo: ?string, ate: ?string} */
    public function paraJson(): array
    {
        return ['chave' => $this->chave, 'ativo' => $this->ativo, 'motivo' => $this->motivo, 'ate' => $this->ate];
    }
}
