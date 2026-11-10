<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\EventListener;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Event\PdfFinalGuardado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\VinculoComAPasta;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * O PDF final com selo foi guardado: o vínculo pendente vai para a pasta (spec assinatura-documentos-etapa-3, E3-11).
 * O evento chega depois do COMMIT de quem guardou o PDF (a assinatura do último signatário, ou o primeiro download).
 * Documento na Lixeira também: a Lixeira é reversível e o vínculo foi decidido antes.
 * Nenhuma falha daqui volta para quem guardou: a recusa desfaz o vínculo pendente, com "vínculo não aplicado" na
 * trilha, e o "Vincular à pasta" volta na tela; o erro inesperado vai para o log.
 */
#[AsEventListener(event: PdfFinalGuardado::class, method: 'aoGuardarPdfFinal')]
final readonly class VinculoPendenteListener
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private AcessoALixeira $lixeira,
        private VinculoComAPasta $vinculo,
        private LoggerInterface $logger,
    ) {
    }

    public function aoGuardarPdfFinal(PdfFinalGuardado $evento): void
    {
        try {
            $documento = $this->lixeira->comLixeiraVisivel(fn (): ?DocumentoParaAssinar => $this->documentos->find($evento->documentoId));
            if ($documento !== null && $documento->isVinculoPendente()) {
                $this->vinculo->aplicar($documento);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Vínculo da assinatura: o vínculo pendente do documento {id} não foi aplicado: {erro}', [
                'id' => $evento->documentoId,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
