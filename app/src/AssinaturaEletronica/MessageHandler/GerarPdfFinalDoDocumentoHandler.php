<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\MessageHandler;

use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\Selo\CadeiaDoSeloPendente;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\Repository\TenantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Gera e guarda o PDF final com selo no worker ({@see PdfFinalDoDocumento::garantir()}). O documento é procurado no
 * escritório da mensagem, mesmo na Lixeira (a mensagem é a fronteira de confiança do worker). Escritório ou documento
 * que sumiu, ou que não está concluído: nada a fazer.
 *
 * Selo (etapa 8, E8-4): a tentativa vem na mensagem, e a 4ª é a última (guarda sem o selo, com `selo_nao_aplicado` e
 * `logger->error`). A {@see FalhaNoSelo} que tenta de novo, antes da 4ª, não guardou nada: o documento fica com a
 * cadeia do selo marcada ({@see CadeiaDoSeloPendente}, para a web não gerar de novo enquanto isso), a mesma mensagem
 * volta à fila com a tentativa seguinte, 1 min depois da 1ª, 5 min depois da 2ª e 15 min depois da 3ª, e esta termina
 * sem erro (o retry do transporte, de segundos, é curto para o validador fora do ar). Qualquer outra falha sobe para o
 * Messenger tentar de novo; o que escapar é gerado no primeiro download.
 */
#[AsMessageHandler]
final readonly class GerarPdfFinalDoDocumentoHandler
{
    public const ULTIMA_TENTATIVA = 4;

    /** A espera antes da próxima tentativa, em milissegundos, pela tentativa que acabou de falhar. */
    private const ESPERA_EM_MS = [1 => 60_000, 2 => 300_000, 3 => 900_000];

    public function __construct(
        private TenantRepository $tenants,
        private DocumentoParaAssinarRepository $documentos,
        private PdfFinalDoDocumento $pdfFinal,
        private MessageBusInterface $mensagens,
        private CadeiaDoSeloPendente $cadeiaDoSelo,
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

        try {
            $this->pdfFinal->garantir($documento, null, $mensagem->tentativa >= self::ULTIMA_TENTATIVA);
        } catch (FalhaNoSelo $falha) {
            $espera = self::ESPERA_EM_MS[$mensagem->tentativa] ?? throw $falha;
            // Já marcada (pela web, ou numa tentativa anterior), a marca segue valendo: volta à fila do mesmo jeito.
            $this->cadeiaDoSelo->marcar($mensagem->documentoId);
            $this->mensagens->dispatch(
                new GerarPdfFinalDoDocumento($mensagem->documentoId, $mensagem->tenantId, $mensagem->tentativa + 1),
                [new DelayStamp($espera)],
            );
        }
    }
}
