<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Um boleto (agrupado por NN) pronto para virar UMA Obrigação (decisão C do mapeamento TOPLIFE).
 * Value object fonte-agnóstico: o adapter TOPLIFE traduz as linhas do relatório para este conceito
 * do domínio; o UseCase de importação consome isto sem saber que a origem era uma planilha.
 *
 * Valores em CENTAVOS inteiros (padrão do domínio). Honorários informados servem só ao
 * preview/reconciliação — NÃO são persistidos por obrigação (o domínio os deriva da Carteira, §18/§19).
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
        public readonly int $encargosCentavos,
        public readonly int $honorariosInformadosCentavos,
        public readonly \DateTimeImmutable $vencimento,
        public readonly string $competencia,
        public readonly ?string $acordoTexto,
        public readonly array $linhas,
    ) {
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
