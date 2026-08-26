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
     * ⚠️ São DOIS motivos distintos, com consequências opostas, e por isso o motivo vai linha a linha:
     * a **congelada** nunca é re-hidratada, então o valor dela fica parado; a **fora da lista
     * aprovada** continua viva e crescendo. Um texto único no rodapé já mentiu sobre isso.
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
        return count($this->foraDoExigivel());
    }

    /**
     * As corrigidas que estão FORA do exigível — as que a correção só ajusta a ficha.
     *
     * 🔑 **É desta lista, e só dela, que sai a linha `--ids=` pronta para colar.** Sugerir a lista
     * inteira punha o caminho de menor esforço (copiar a linha) a serviço do pior resultado possível:
     * gravar um override PERMANENTE numa dívida que alguém está cobrando hoje. A §10.8 mediu que o
     * defeito do override é a DURAÇÃO, não o valor — então o que precisa de conferência humana extra
     * não é a existência da candidata, é ela estar viva.
     *
     * @return list<array<string, mixed>>
     */
    public function foraDoExigivel(): array
    {
        return array_values(array_filter($this->corrigidas, static fn (array $c): bool => $c['foraDoExigivel']));
    }

    /**
     * As corrigidas que estão DENTRO do exigível — nelas a correção muda o que o devedor deve.
     *
     * Saem em tabela própria, com aviso, e **fora** da linha pronta para colar: quem quiser corrigir
     * uma delas inclui o id à mão, depois de conferir na planilha. Medido em produção em 20/08: são
     * **0 de 135** — todas as candidatas de hoje têm acordo substituto vigente. A separação existe
     * para o dia em que não forem.
     *
     * @return list<array<string, mixed>>
     */
    public function dentroDoExigivel(): array
    {
        return array_values(array_filter($this->corrigidas, static fn (array $c): bool => !$c['foraDoExigivel']));
    }

    /** Nenhuma obrigação pode sumir entre o universo encontrado e o que a correção fez. */
    public function contasFecham(): bool
    {
        return count($this->corrigidas) + count($this->puladas) === $this->candidatas;
    }
}
