<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Os quatro relatórios que a contabilidade emite por carteira (SPEC espelho §4.2).
 *
 * Os mesmos quatro valores já existem como constantes em `RegistrarEmissaoNaCarteira` — este enum
 * NÃO as substitui nesta fase (mexer lá alteraria o JSON já gravado em `Carteira`, fora do escopo).
 * Aqui ele tipa a coluna do espelho, que nasce agora.
 */
enum TipoRelatorioContabil: string
{
    case Cadastro = 'cadastro';
    case Inadimplencia = 'inadimplencia';
    case Receitas = 'receitas';
    case Acordos = 'acordos';
}
