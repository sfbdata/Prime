<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Saída do `CadastroCondominosAdapter`: o que dá para importar, o que foi recusado e com que motivo, e
 * quantas linhas nem eram dado (cabeçalho/rodapé). Espelha `ResultadoLeitura` do lado do dinheiro.
 */
final class ResultadoLeituraCadastro
{
    /**
     * @param list<CondominoImportavel> $importaveis
     * @param list<LinhaRejeitada>      $rejeitadas  inclui rejeições PARCIAIS (documento ou telefone
     *                                              recusado) cuja pessoa ainda entra em $importaveis
     */
    public function __construct(
        public readonly array $importaveis,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
    ) {
    }
}
