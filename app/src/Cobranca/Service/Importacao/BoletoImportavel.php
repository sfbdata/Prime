<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Um boleto (agrupado por NN) pronto para virar UMA Obrigação (decisão C do mapeamento TOPLIFE).
 * Value object fonte-agnóstico: o adapter TOPLIFE traduz as linhas do relatório para este conceito
 * do domínio; o UseCase de importação consome isto sem saber que a origem era uma planilha.
 *
 * Valores em CENTAVOS inteiros (padrão do domínio). Encargos vêm SEPARADOS em juros/multa/correção
 * (spec §9): a fonte já distingue as três parcelas e o domínio passou a materializá-las separadas —
 * colapsá-las de novo num número só perderia informação que a contabilidade forneceu. Honorários
 * informados são a leitura da fonte (o domínio também sabe derivá-los da Carteira, §18/§19).
 */
final class BoletoImportavel
{
    /**
     * @param list<array<string, string|int|float|null>> $linhas detalhamento original (auditoria/preview)
     */
    public function __construct(
        public readonly string $nn,
        public readonly string $objetoIdentificacao,
        public readonly ?string $unidadeMetadata,
        public readonly string $sacadoNome,
        public readonly int $principalCentavos,
        public readonly int $jurosCentavos,
        public readonly int $multaCentavos,
        public readonly int $correcaoCentavos,
        public readonly int $honorariosInformadosCentavos,
        public readonly \DateTimeImmutable $vencimento,
        public readonly string $competencia,
        public readonly ?string $acordoTexto,
        public readonly array $linhas,
    ) {
    }

    /**
     * Encargo agregado (juros + multa + correção), para quem só quer o total — preview, reconciliação,
     * conferência contra a coluna Total do relatório. Fórmula em UM lugar só: é a mesma soma que a
     * `Obrigacao::getEncargosReconhecidos()` faz do outro lado, o que mantém INV-E1 por construção.
     * Honorários ficam FORA (INV-E2: honorário não é dívida do credor).
     */
    public function encargosCentavos(): int
    {
        return $this->jurosCentavos + $this->multaCentavos + $this->correcaoCentavos;
    }

    /** Descrição sugerida da Obrigação (competência + classes presentes). */
    public function descricao(): string
    {
        $classes = [];
        foreach ($this->linhas as $l) {
            $c = trim((string) ($l['classe'] ?? ''));
            if ($c !== '') {
                $classes[$c] = true;
            }
        }

        $rotulo = implode(' + ', array_keys($classes));

        return trim("{$rotulo} — competência {$this->competencia}", ' —');
    }

    /** Observação consolidada (unidade associada + acordo), quando houver. */
    public function observacao(): ?string
    {
        $partes = [];
        if ($this->unidadeMetadata !== null && $this->unidadeMetadata !== '') {
            $partes[] = "Unidades associadas: {$this->unidadeMetadata}";
        }
        if ($this->acordoTexto !== null && $this->acordoTexto !== '') {
            $partes[] = $this->acordoTexto;
        }

        return $partes === [] ? null : implode(' | ', $partes);
    }
}
