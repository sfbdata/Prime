<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\ModoDeCobrancaDaIa;

/**
 * Uma linha da tabela "Escritórios" da aba do Master. Só o nome do escritório: nada de CPF ou e-mail de ninguém.
 * O aceite só aparece com a IA habilitada pelo admin (é o aceite vigente; o de quem desligou depois é histórico).
 * O consumo do mês (análises que contam na cota e o gasto estimado em centavos de US$) vem do UseCase.
 */
final class EscritorioNaIaDoMasterOutput
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly bool $liberada,
        public readonly ?\DateTimeImmutable $aceiteEm,
        public readonly ModoDeCobrancaDaIa $modoDeCobranca,
        public readonly ?int $orcamentoMensalCentavos,
        public readonly int $analisesNoMes = 0,
        public readonly int $gastoNoMesCentavos = 0,
    ) {
    }

    /** @param array{tenant_id: int, nome: string, liberada: bool, habilitada: bool, aceite_em: ?string, modo: ?string, orcamento: ?int} $linha */
    public static function daLinha(array $linha, int $analisesNoMes = 0, int $gastoNoMesCentavos = 0): self
    {
        return new self(
            tenantId: $linha['tenant_id'],
            nome: $linha['nome'],
            liberada: $linha['liberada'],
            aceiteEm: $linha['habilitada'] && $linha['aceite_em'] !== null ? new \DateTimeImmutable($linha['aceite_em']) : null,
            modoDeCobranca: ModoDeCobrancaDaIa::tryFrom((string) $linha['modo']) ?? ModoDeCobrancaDaIa::Incluso,
            orcamentoMensalCentavos: $linha['orcamento'],
            analisesNoMes: $analisesNoMes,
            gastoNoMesCentavos: $gastoNoMesCentavos,
        );
    }

    /** Em `incluso` e `adicional`: o gasto do mês chegou ao orçamento e a IA está travada (selo "orçamento atingido"). */
    public function orcamentoAtingido(): bool
    {
        return $this->modoDeCobranca->orcamentoAtingido($this->orcamentoMensalCentavos, $this->gastoNoMesCentavos);
    }

    /** Em `limite_gratis`: o gasto do mês passou da parte grátis (selo "acima do grátis"; não trava). */
    public function acimaDoGratis(): bool
    {
        return $this->modoDeCobranca->acimaDoGratis($this->orcamentoMensalCentavos, $this->gastoNoMesCentavos);
    }
}
