<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ResultadoDaConfirmacao;

/** Monta o {@see ResultadoDaConfirmacao} do que a `documentos_e_pecas` gravou: a pasta sempre vai nos ids do log. */
final class DocumentosEPecasResultado
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>     $dados      o que vai ao app de I.A.: ids, contagens e nomes curtos, nunca o conteúdo
     * @param array<string, list<int>> $ids        o que foi mexido, por tipo (a pasta entra sozinha)
     * @param array<string, int>       $contagens
     * @param (\Closure(): void)|null  $depoisDoCommit o passo para depois do COMMIT da confirmação (a remoção dos
     *                                                arquivos do `excluir_de_vez`)
     */
    public static function de(DocumentosEPecasContexto $contexto, array $dados, array $ids = [], array $contagens = [], ?\Closure $depoisDoCommit = null): ResultadoDaConfirmacao
    {
        $planos = [];
        foreach ($ids as $lista) {
            array_push($planos, ...$lista);
        }

        return new ResultadoDaConfirmacao(
            $dados,
            array_values(array_unique($planos)),
            $contagens,
            ['pasta' => [(int) $contexto->pasta->getId()]] + $ids,
            $depoisDoCommit,
        );
    }
}
