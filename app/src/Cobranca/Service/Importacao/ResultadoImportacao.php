<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Resultado CLARO de uma importação (SPEC §21): o que foi criado, atualizado, rejeitado e ignorado.
 * Serve tanto ao preview (dry-run: o que ACONTECERIA) quanto à confirmação (o que aconteceu). Contagens
 * de objetos/pessoas/casos novos ajudam o operador a conferir antes de confirmar.
 */
final class ResultadoImportacao
{
    /**
     * @param list<string>         $obrigacoesCriadas     NNs de boletos que viram Obrigação nova
     * @param list<string>         $obrigacoesAtualizadas NNs de boletos já existentes (reimportação idempotente)
     * @param list<LinhaRejeitada> $rejeitadas
     * @param list<string>         $sacadosDivergentes    objetos onde o Sacado do relatório difere da pessoa cobrada atual
     */
    public function __construct(
        public readonly array $obrigacoesCriadas,
        public readonly array $obrigacoesAtualizadas,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
        public readonly int $objetosCriados,
        public readonly int $pessoasCriadas,
        public readonly int $casosCriados,
        public readonly array $sacadosDivergentes,
    ) {
    }

    public function totalImportadas(): int
    {
        return count($this->obrigacoesCriadas);
    }

    public function totalAtualizadas(): int
    {
        return count($this->obrigacoesAtualizadas);
    }

    public function totalRejeitadas(): int
    {
        return count($this->rejeitadas);
    }
}
