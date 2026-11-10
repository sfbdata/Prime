<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * Lugar do PDF final enquanto o gerador não chega (contrato da etapa 3, seção 2, item 7): o bloco 1 troca o alias de
 * {@see PdfFinalDoDocumento} em `config/services.yaml` pela implementação real e apaga esta classe.
 */
final class PdfFinalProvisorio implements PdfFinalDoDocumento
{
    public function garantir(DocumentoParaAssinar $doc): ChaveDeArquivo
    {
        throw new \LogicException('O PDF final com selo ainda não é gerado.');
    }
}
