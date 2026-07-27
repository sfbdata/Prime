<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Aviso de risco de prescrição no cabeçalho do objeto (2026-07-27). Montado por
 * `CalculadoraPrescricao` a partir da obrigação EM ABERTO mais antiga do caso.
 *
 * É uma CONTAGEM DE DIAS, não um parecer. O sistema soma o prazo padrão ao vencimento mais antigo e
 * mostra quanto falta; não conhece marcos de interrupção ou suspensão, não distingue natureza da
 * dívida e não substitui a análise de quem vai ajuizar. A tela exibe esse aviso junto do número — e
 * é por isso que ele mora aqui, num campo, e não numa nota solta do Twig que alguém pode remover
 * sem perceber o que está removendo.
 */
final class PrescricaoOutput
{
    /** Faltam mais de `DIAS_ATENCAO` dias — informativo, sem alarme. */
    public const SEVERIDADE_INFORMATIVA = 'informativa';
    /** Entrou na faixa de atenção: dá para planejar o ajuizamento com folga. */
    public const SEVERIDADE_ATENCAO = 'atencao';
    /** Faixa crítica: o prazo aperta. */
    public const SEVERIDADE_CRITICA = 'critica';
    /** O prazo já passou. */
    public const SEVERIDADE_ESGOTADA = 'esgotada';

    public function __construct(
        /** Id da obrigação mais antiga em aberto — o link "Ver competência" aponta para ela. */
        public readonly int $obrigacaoId,
        public readonly string $obrigacaoDescricao,
        /** Mês/ano de referência da competência mais antiga (o vencimento da obrigação). */
        public readonly \DateTimeImmutable $vencimentoMaisAntigo,
        public readonly \DateTimeImmutable $prazoLimite,
        /** Negativo quando o prazo já passou — a tela decide a frase, o cálculo não esconde o sinal. */
        public readonly int $diasRestantes,
        public readonly string $severidade,
        /** Anos do prazo aplicado, para a tela poder dizer de onde saiu a conta. */
        public readonly int $prazoAnos,
    ) {
    }

    public function esgotada(): bool
    {
        return $this->severidade === self::SEVERIDADE_ESGOTADA;
    }
}
