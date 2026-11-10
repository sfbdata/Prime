<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\EventListener;

use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\VinculoComAPasta;
use App\Repository\TenantRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * O worker desistiu do PDF final com selo (spec assinatura-documentos-etapa-3, E3-11): a falha que lança no
 * {@see \App\AssinaturaEletronica\MessageHandler\GerarPdfFinalDoDocumentoHandler} (gerar ou gravar) passou por todas as
 * tentativas do Messenger (`willRetry()` falso, decidido antes pelo ouvinte do retry, de prioridade 100). O vínculo
 * pendente vira "vínculo não aplicado" com o motivo do PDF indisponível, em nome de quem vinculou, e se desfaz: o
 * "Vincular à pasta" volta ({@see VinculoComAPasta::pdfFinalFalhou()}). Enquanto houver nova tentativa, nada muda. A
 * falha do selo que tenta de novo não chega aqui: o handler a devolve à fila sem lançar.
 *
 * A falha dentro de uma transação fecha o EntityManager: ele é reaberto antes de ler o documento. O escritório e o
 * documento são procurados como no handler, mesmo na Lixeira. Nenhuma falha daqui sobe: o erro vai para o log e a
 * mensagem segue para a fila de falhas como as outras.
 */
final readonly class FalhaDoPdfFinalListener
{
    public function __construct(
        private TenantRepository $tenants,
        private DocumentoParaAssinarRepository $documentos,
        private VinculoComAPasta $vinculo,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function __invoke(WorkerMessageFailedEvent $evento): void
    {
        $mensagem = $evento->getEnvelope()->getMessage();
        if (!$mensagem instanceof GerarPdfFinalDoDocumento || $evento->willRetry()) {
            return;
        }

        try {
            $this->documentos->reabrirSeFechado();
            $tenant = $this->tenants->find($mensagem->tenantId);
            $documento = $tenant === null ? null : $this->documentos->doEscritorioMesmoNaLixeira($mensagem->documentoId, $tenant);
            if ($documento !== null) {
                $this->vinculo->pdfFinalFalhou($documento, self::causa($evento->getThrowable()));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Vínculo da assinatura: a falha do PDF final do documento {id} não desfez o vínculo pendente: {erro}', [
                'id' => $mensagem->documentoId,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    /** O Messenger embrulha a exceção do handler; o que interessa é a de dentro. */
    private static function causa(\Throwable $erro): \Throwable
    {
        if ($erro instanceof HandlerFailedException) {
            return array_values($erro->getWrappedExceptions())[0] ?? $erro;
        }

        return $erro;
    }
}
