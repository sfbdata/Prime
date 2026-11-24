<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Enum\MotivoDoFimDoVinculo;

/**
 * O que vale para todos os escritórios numa volta da sincronização: o convênio OAB, as exceções por UF, a chave geral,
 * o momento e o motivo de fechar pela UF (`uf_desligada` na tarefa diária e na mudança da UF pelo Master;
 * `estado_mudou` quando o próprio escritório troca de estado).
 */
final class ContextoDosVinculos
{
    /** @param array<string, ConvenioDaUf> $excecoesPorUf sigla => exceção */
    public function __construct(
        public readonly ?Convenio $oab,
        public readonly array $excecoesPorUf,
        public readonly bool $chaveLigada,
        public readonly \DateTimeImmutable $agora,
        public readonly MotivoDoFimDoVinculo $motivoDaUf,
    ) {
    }

    /** A UF está "sem convênio" a partir de hoje (a data `desde` da exceção já chegou). */
    public function ufDesligada(string $uf): bool
    {
        $excecao = $this->excecoesPorUf[$uf] ?? null;

        return $excecao?->getModo() === ConvenioDaUf::MODO_SEM
            && $excecao->getDesde()->format('Y-m-d') <= $this->agora->format('Y-m-d');
    }
}
