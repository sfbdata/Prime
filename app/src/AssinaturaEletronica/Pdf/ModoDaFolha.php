<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Para que a folha é montada (`pdf(d, modo)` do desenho): o PDF final com selo, a cópia "para assinar" da etapa 5 ou
 * o rascunho (sem código: imprime "RASCUNHO"). O desenho só muda o nome do arquivo pelo modo; a folha é a mesma.
 */
enum ModoDaFolha: string
{
    case Final = 'final';
    case Assinar = 'assinar';
    case Rascunho = 'rascunho';
}
