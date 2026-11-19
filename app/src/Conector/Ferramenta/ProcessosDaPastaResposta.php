<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Pasta\Entity\Pasta;

/** As respostas da `processos_da_pasta`: só ids e o que mudou, nunca o texto gravado. */
final class ProcessosDaPastaResposta
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>     $extras
     * @param array<string, list<int>> $ids
     */
    public static function direta(Pasta $pasta, ProcessosDaPastaEntrada $entrada, array $extras, array $ids = []): RespostaDaFerramenta
    {
        $dados = ['estado' => 'gravado', 'pasta_id' => (int) $pasta->getId(), 'acao' => $entrada->acao];
        if ($entrada->operacao !== null) {
            $dados['operacao'] = $entrada->operacao;
        }

        return new RespostaDaFerramenta($dados + $extras, contagens: ['gravados' => 1], ids: ['pasta' => [(int) $pasta->getId()]] + $ids);
    }

    /**
     * @param array<string, mixed>     $extras
     * @param array<string, list<int>> $ids
     */
    public static function confirmada(Pasta $pasta, ProcessosDaPastaEntrada $entrada, array $extras, array $ids = []): ResultadoDaConfirmacao
    {
        $dados = ['pasta_id' => (int) $pasta->getId(), 'acao' => $entrada->acao];
        if ($entrada->operacao !== null) {
            $dados['operacao'] = $entrada->operacao;
        }
        $todos = ['pasta' => [(int) $pasta->getId()]] + $ids;

        return new ResultadoDaConfirmacao($dados + $extras, array_merge(...array_values($todos)), ['confirmadas' => 1], $todos);
    }
}
