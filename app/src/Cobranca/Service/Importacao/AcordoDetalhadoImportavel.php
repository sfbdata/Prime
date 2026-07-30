<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Uma ABA do relatório "Acordos detalhados" já traduzida para o domínio: o cabeçalho do acordo, a
 * relação das contas originais e as parcelas geradas (spec `cobranca-importar-acordos-detalhados.md` §2).
 *
 * O acordo NUNCA é criado a partir daqui (§3.1): ele é responsabilidade do relatório de inadimplência.
 * Este VO só descreve o que a contábil afirma sobre um acordo que já deve existir — por isso `numero` é
 * a única identidade que importa, e a unidade/sacado servem para o operador conferir no dry-run.
 */
final class AcordoDetalhadoImportavel
{
    /**
     * @param list<ContaOriginalImportavel> $contasOriginais
     * @param list<ParcelaAcordoImportavel> $parcelas
     */
    public function __construct(
        public readonly int $numero,
        public readonly string $unidade,
        public readonly string $sacado,
        public readonly string $situacao,
        public readonly ?\DateTimeImmutable $dataBase,
        public readonly ?\DateTimeImmutable $criadoEm,
        public readonly ?int $valorTotalContasOriginaisCentavos,
        public readonly ?int $valorFinalAcordadoCentavos,
        public readonly ?\DateTimeImmutable $emissao,
        public readonly array $contasOriginais,
        public readonly array $parcelas,
    ) {
    }

    /** Soma da coluna "Valor original" das contas originais — para conferir contra o cabeçalho. */
    public function somaContasOriginaisCentavos(): int
    {
        return array_sum(array_map(static fn (ContaOriginalImportavel $c): int => $c->valorCentavos, $this->contasOriginais));
    }

    /** Soma das parcelas — para conferir contra "Valor final acordado" do cabeçalho. */
    public function somaParcelasCentavos(): int
    {
        return array_sum(array_map(static fn (ParcelaAcordoImportavel $p): int => $p->valorCentavos, $this->parcelas));
    }
}
