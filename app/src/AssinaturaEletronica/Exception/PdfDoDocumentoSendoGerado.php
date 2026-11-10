<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * "Baixar PDF com selo" do concluído sem PDF guardado enquanto outro pedido da web já gera o PDF deste documento (a
 * trava por documento das {@see \App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf}). Não ocupa vaga nem gasta
 * cota. Vira 429 com "O PDF deste documento está sendo gerado. Tente de novo em alguns minutos.".
 */
final class PdfDoDocumentoSendoGerado extends GeracaoDoPdfRecusada
{
    public const CHAVE = 'assinatura_eletronica_tela.js.pdf.documento_gerando';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
