<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O PDF final com selo do documento concluído (contrato da etapa 3, seção 2, item 7): quem baixa e quem vincula à pasta
 * pedem a chave por aqui, sem saber se o arquivo já existia.
 */
interface PdfFinalDoDocumento
{
    /**
     * A chave do PDF final guardado, gerando e guardando se faltar. Lança se o documento não está concluído.
     */
    public function garantir(DocumentoParaAssinar $doc): ChaveDeArquivo;
}
