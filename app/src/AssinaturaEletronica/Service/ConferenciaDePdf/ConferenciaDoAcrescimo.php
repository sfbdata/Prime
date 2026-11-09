<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * O que o acréscimo de uma assinatura (os bytes entre a versão anterior e o fim da faixa dela) faz com o documento
 * (M27). A análise é conservadora; a comparação completa das versões é do pyHanko, na etapa 6.
 */
enum ConferenciaDoAcrescimo: string
{
    /** Primeira assinatura, sem versão anterior: não há acréscimo para olhar. */
    case NaoSeAplica = 'nao_se_aplica';
    /** O acréscimo só traz o que a assinatura precisa (assinatura, campo, formulário, dados de validação, aparência). */
    case SoAssinatura = 'so_assinatura';
    /** O acréscimo muda ou cria algo que pode mudar o que aparece no documento. */
    case MudaConteudo = 'muda_conteudo';
    /** Não foi possível ler o acréscimo inteiro. */
    case NaoConferido = 'nao_conferido';
}
