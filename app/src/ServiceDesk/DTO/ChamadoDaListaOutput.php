<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\StatusDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;

final class ChamadoDaListaOutput
{
    public function __construct(
        public readonly int $id,
        public readonly int $numero,
        public readonly string $assunto,
        public readonly TipoDeChamado $tipo,
        public readonly PrioridadeDoChamado $prioridade,
        public readonly StatusDoChamado $status,
        public readonly \DateTimeImmutable $criadoEm,
        public readonly ?\DateTimeImmutable $dataAgendada,
        public readonly ?string $horaAgendada,
        public readonly ?int $valorEstimadoCentavos,
        public readonly ?int $valorDebitadoCentavos,
        public readonly string $descricao = '',
    ) {
    }

    public static function fromEntity(ChamadoDeSuporte $c): self
    {
        return new self(
            (int) $c->getId(),
            $c->getNumero(),
            $c->getAssunto(),
            $c->getTipo(),
            $c->getPrioridade(),
            $c->getStatus(),
            $c->getCriadoEm(),
            $c->getDataAgendada(),
            $c->getHoraAgendada(),
            $c->getValorEstimadoCentavos(),
            $c->getValorDebitadoCentavos(),
            $c->getDescricao(),
        );
    }

    public function numeroFormatado(): string
    {
        return '#' . str_pad((string) $this->numero, 4, '0', STR_PAD_LEFT);
    }
}
