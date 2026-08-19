<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * O que a correção do honorário da PARCELA DE ACORDO fez (ou faria, na simulação) — spec
 * `docs/specs/cobranca-honorario-no-total.md` §10.5.
 *
 * 🔑 **`honorarioRemovido` NÃO é "dívida que baixou".** Enquanto o acordo substituto estiver vigente,
 * estas obrigações estão FORA do exigível (`ObrigacaoRepository::aplicarExigibilidade` exclui quem tem
 * substituto vigente) — medido em produção em 19/08: 0 de 135 no exigível. O número que muda hoje é o
 * da TELA DO ACORDO, que lista as parcelas sem esse filtro. Apresentar isto como redução de saldo
 * repetiria o erro que já reportou o total desta frente errado três vezes.
 */
final readonly class ResultadoReconciliacaoHonorario
{
    /**
     * @param list<array{obrigacaoId: int, casoId: int, unidade: string, referencia: ?string,
     *                   competencia: ?string, valorOriginal: int, honorarioRemovido: int,
     *                   acordoOrigem: ?int, acordoSubstituto: ?int, foraDoExigivel: bool}> $corrigidas
     * @param list<array{obrigacaoId: int, unidade: string, referencia: ?string, motivo: string,
     *                   honorarioQueFicou: int}>                                              $puladas
     */
    public function __construct(
        public bool $aplicou,
        public int $candidatas,
        public array $corrigidas,
        public array $puladas,
        public int $casosComEvento,
    ) {
    }

    /** O honorário indevido que saiu (ou sairia) do campo materializado. */
    public function honorarioRemovidoEmCentavos(): int
    {
        return array_sum(array_column($this->corrigidas, 'honorarioRemovido'));
    }

    /**
     * O honorário indevido que PERMANECE por causa das puladas — não pode sumir do relatório.
     *
     * Pular obrigação congelada é a decisão certa (congelada não é re-hidratada, e mexer no snapshot
     * dela é decisão de outra natureza), mas não é no-op: é valor que fica inflado no banco.
     */
    public function honorarioQueFicouEmCentavos(): int
    {
        return array_sum(array_column($this->puladas, 'honorarioQueFicou'));
    }

    /**
     * Quantas das corrigidas estão FORA do exigível — pelas duas cláusulas de
     * {@see \App\Cobranca\Repository\ObrigacaoRepository::aplicarExigibilidade}: substituto vigente
     * **ou** acordo de origem não vigente.
     *
     * É o recorte que separa "arrumei a ficha" de "mudei o que alguém deve". A diferença entre este
     * número e o total de corrigidas é a quantidade que HOJE muda o saldo — e ela é apresentada ao dono
     * como exata, então tem de sair da régua inteira.
     */
    public function corrigidasForaDoExigivel(): int
    {
        return count(array_filter($this->corrigidas, static fn (array $c): bool => $c['foraDoExigivel']));
    }

    /** Nenhuma obrigação pode sumir entre o universo encontrado e o que a correção fez. */
    public function contasFecham(): bool
    {
        return count($this->corrigidas) + count($this->puladas) === $this->candidatas;
    }
}
