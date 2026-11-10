<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * "Baixar PDF com selo" do concluído sem PDF guardado enquanto outro pedido da web já gera o PDF deste documento (a
 * trava por documento das {@see \App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf}): esse pedido não ocupa vaga
 * nem gasta cota. Vira 429 com "O PDF deste documento está sendo gerado. Tente de novo em alguns minutos.".
 *
 * A mesma resposta com o selo (etapa 8, E8-4). O clique em que o selo falhou e tenta de novo gerou o PDF, ocupou a
 * vaga e gastou a cota; nada foi guardado e o pedido foi ao worker. Os cliques seguintes, com a cadeia do selo pendente
 * ({@see \App\AssinaturaEletronica\Service\Selo\CadeiaDoSeloPendente}), recebem a mesma resposta sem gerar, sem
 * ocupar vaga e sem gastar cota.
 */
final class PdfDoDocumentoSendoGerado extends GeracaoDoPdfRecusada
{
    public const CHAVE = 'assinatura_eletronica_tela.js.pdf.documento_gerando';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
