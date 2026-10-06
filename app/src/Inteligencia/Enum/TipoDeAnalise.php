<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * O que a análise faz. Só `ResumoPush` está implementado nesta fatia; os demais existem para a
 * coluna `tipo` já nascer com o vocabulário das fatias seguintes (agentes da pasta, pergunta
 * livre, resumo de documento) sem migration de enum.
 */
enum TipoDeAnalise: string
{
    case ResumoPush = 'resumo_push';
    case AnalisePasta = 'analise_pasta';
    case PerguntaLivre = 'pergunta_livre';
    case ResumoDocumento = 'resumo_documento';
}
