<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\ApresentacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDeUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Confere o PDF escolhido no passo 1 sem gravar nada (etapa 5, E5-6): o arquivo só sobe ao salvar o rascunho, mas o
 * quadro "Documento já assinado" do passo 3 (dc:188) aparece antes, com o resultado do motor do servidor.
 */
final readonly class ConferirPdfEnviadoUseCase
{
    public function __construct(
        private ConferenciaDePdf $conferencia,
        private ApresentacaoDaConferencia $apresentacao,
    ) {
    }

    /**
     * @return array<string, mixed>|null o quadro; nulo quando o PDF não traz assinatura digital
     *
     * @throws AcaoRecusada arquivo que não é PDF ou passa de 10 MB
     */
    public function executar(UploadedFile $pdf): ?array
    {
        ConferenciaDeUpload::pdf($pdf);

        return $this->apresentacao->quadro($this->conferencia->conferir($pdf->getContent()));
    }
}
