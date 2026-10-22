<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\EtapaDaPasta;

/**
 * Situação da meta na tela de detalhe (desenho 04B de 22/10): a faixa "Aguardando" (L50), o painel lateral de
 * situação (L77, L136) e o bloco DETALHES DA META (L85, L162-164).
 *
 * `estado` é `concluida`, `revisao`, `atrasada` ou `pendente`, nessa precedência (o painel mostra "Aguardando
 * revisão" mesmo com o prazo vencido). `atrasada` é o prazo vencido em meta não concluída (o quadro vermelho do
 * prazo); `diasDeAtraso` conta os dias corridos desde o vencimento. `posicao` nula: o tipo de demanda não passa pela
 * etapa, e o painel mostra só o nome. `nomeDaEtapa` nulo: vale o nome do catálogo.
 */
final class SituacaoDaMetaOutput
{
    public const CONCLUIDA = 'concluida';
    public const REVISAO   = 'revisao';
    public const ATRASADA  = 'atrasada';
    public const PENDENTE  = 'pendente';

    public function __construct(
        public readonly string $estado,
        public readonly int $diasDeAtraso,
        public readonly bool $atrasada,
        public readonly EtapaDaPasta $etapa,
        public readonly ?int $posicao,
        public readonly int $total,
        public readonly ?string $nomeDaEtapa,
        public readonly ?AguardandoNaMetaOutput $aguardando,
        public readonly ?\DateTimeImmutable $prazo,
        public readonly \DateTimeImmutable $criadaEm,
        public readonly int $diasDesdeACriacao,
        public readonly ?string $despachante,
    ) {
    }

    /** Ícone do painel de situação (L136 `stIc`). */
    public function icone(): string
    {
        return match ($this->estado) {
            self::CONCLUIDA => 'bi-check2-circle',
            self::REVISAO   => 'bi-hourglass-split',
            self::ATRASADA  => 'bi-exclamation-octagon',
            default         => 'bi-hourglass',
        };
    }
}
