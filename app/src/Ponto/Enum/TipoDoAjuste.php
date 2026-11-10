<?php

declare(strict_types=1);

namespace App\Ponto\Enum;

/**
 * O que um ajuste fez com a batida (E10, plano-e10-ponto §6): a batida gravada nunca muda, e cada correção do gestor
 * entra como uma linha nova de `ponto_ajuste`, presa à anterior da mesma batida. O valor é o que a coluna `tipo`
 * guarda (CHECK da Version20261109280000), então um valor gravado nunca muda.
 *
 * - Inclusão: a batida que não existia (o gestor incluiu, ou o esquecimento abonado). É a raiz da própria linhagem.
 * - Alteração: muda o tipo, a data ou a hora da batida.
 * - Desconsideração: tira a batida da conta (o registro original fica guardado).
 * - Restauração: desfaz uma desconsideração; a batida volta como estava antes dela.
 */
enum TipoDoAjuste: string
{
    case Inclusao        = 'inclusao';
    case Alteracao       = 'alteracao';
    case Desconsideracao = 'desconsideracao';
    case Restauracao     = 'restauracao';
}
