<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Resultado claro da importação do cadastro de condôminos: o que nasceu, o que foi acrescentado e o que
 * não entrou. Serve ao dry-run (o que ACONTECERIA) e à confirmação (o que aconteceu).
 *
 * Note que não existe "atualizado": o modelo de qualificação é ADITIVO (spec §5.2) — contato novo entra
 * na lista e vira o atual, o anterior fica no histórico. Nada é sobrescrito, então nada é "alterado".
 */
final class ResultadoImportacaoCadastro
{
    /**
     * @param list<string>         $objetosCriados   identificações de unidade que não existiam
     * @param list<string>         $pessoasCriadas   nomes das pessoas novas
     * @param list<string>         $vinculosCriados  "unidade → pessoa" dos vínculos abertos
     * @param list<LinhaRejeitada> $rejeitadas
     */
    public function __construct(
        public readonly array $objetosCriados,
        public readonly array $pessoasCriadas,
        public readonly array $vinculosCriados,
        public readonly int $telefonesAcrescentados,
        public readonly int $emailsAcrescentados,
        public readonly int $enderecosAcrescentados,
        public readonly int $pessoasReaproveitadas,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
    ) {
    }

    public function totalObjetosCriados(): int
    {
        return count($this->objetosCriados);
    }

    public function totalPessoasCriadas(): int
    {
        return count($this->pessoasCriadas);
    }

    public function totalVinculosCriados(): int
    {
        return count($this->vinculosCriados);
    }

    public function totalRejeitadas(): int
    {
        return count($this->rejeitadas);
    }
}
