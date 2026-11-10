<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\MessageHandler;

use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\Repository\TenantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gera e guarda o PDF final com selo no worker ({@see PdfFinalDoDocumento::garantir()}). O documento é procurado no
 * escritório da mensagem, mesmo na Lixeira (a mensagem é a fronteira de confiança do worker). Escritório ou documento
 * que sumiu, ou que não está concluído: nada a fazer. Falha na geração sobe para o Messenger tentar de novo; o que
 * escapar é gerado no primeiro download.
 */
#[AsMessageHandler]
final readonly class GerarPdfFinalDoDocumentoHandler
{
    public function __construct(
        private TenantRepository $tenants,
        private DocumentoParaAssinarRepository $documentos,
        private PdfFinalDoDocumento $pdfFinal,
    ) {
    }

    public function __invoke(GerarPdfFinalDoDocumento $mensagem): void
    {
        $tenant = $this->tenants->find($mensagem->tenantId);
        if ($tenant === null) {
            return;
        }

        $documento = $this->documentos->doEscritorioMesmoNaLixeira($mensagem->documentoId, $tenant);
        if ($documento === null || $documento->getSituacao() !== SituacaoDoDocumento::Concluido) {
            return;
        }

        $this->pdfFinal->garantir($documento);
    }
}
