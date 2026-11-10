<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O PDF final com selo do documento concluído (contrato da etapa 3, seção 2, item 7): quem baixa e quem vincula à pasta
 * pedem a chave por aqui, sem saber se o arquivo já existia.
 */
interface PdfFinalDoDocumento
{
    /**
     * A chave do PDF final guardado, gerando e guardando se faltar. Lança se o documento não está concluído.
     *
     * @param (\Closure(): void)|null $aCadaPedaco     chamado a cada pedaço gerado (só quando gera): a web renova as travas
     * @param bool                    $ultimaTentativa a última tentativa do selo (etapa 8, E8-4): a falha que tentaria de
     *                                                 novo guarda sem o selo
     *
     * @throws FalhaNoSelo o selo falhou e tenta de novo (fora da última tentativa); nada foi guardado
     */
    public function garantir(DocumentoParaAssinar $doc, ?\Closure $aCadaPedaco = null, bool $ultimaTentativa = false): ChaveDeArquivo;
}
