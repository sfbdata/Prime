<?php

declare(strict_types=1);

namespace App\Processo\Service;

/**
 * Situação do processo gravada (código) → rótulo para exibição.
 *
 * O cadastro grava o código ("EM_ANDAMENTO") e a tela da pasta imprimia o código; o desenho
 * (02 - EXPEDIENTES 1.2.3, dc de 13/10) mostra "Em andamento". O mapeamento é EXPLÍCITO e cobre
 * exatamente os quatro valores que o sistema oferece no cadastro (selects de processo/new e do
 * modal de vínculo da pasta).
 *
 * Valor fora da lista volta EXATAMENTE como veio: o Datajud grava texto livre (em maiúsculas, pelo
 * setter), e "humanizar" um código que não conhecemos seria inventar rótulo.
 *
 * É apresentação: não normaliza nem grava nada. Usado pelo filtro Twig `situacao_processo` e pelo
 * resumo do processo no PDF (`PastaResumoController`), para tela e impressão dizerem o mesmo.
 */
final class SituacaoProcessoRotulo
{
    private const ROTULOS = [
        'EM_ANDAMENTO' => 'Em andamento',
        'ENCERRADO'    => 'Encerrado',
        'SUSPENSO'     => 'Suspenso',
        'PARALISADO'   => 'Paralisado',
    ];

    public static function de(string $situacao): string
    {
        return self::ROTULOS[$situacao] ?? $situacao;
    }
}
