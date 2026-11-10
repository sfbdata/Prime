<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * "Cancelar envio" do detalhe (contrato da etapa 2, seção 4.1): só o que aguarda assinatura, com a linha travada e
 * relida. Os links param de funcionar (a página mostra "Envio cancelado"); documento e evidências ficam.
 */
final readonly class CancelarEnvioUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private TrilhaDoDocumento $trilha,
        private ApresentacaoDoDocumento $apresentacao,
    ) {
    }

    /**
     * @return array<string, mixed> o `Detalhe` do documento cancelado
     *
     * @throws DocumentoNaoEncontrado
     * @throws AcaoRecusada
     */
    public function executar(User $ator, Tenant $tenant, int $id): array
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();

        $this->documentos->emTransacao(function () use ($documento, $tenant, $ator): void {
            if (!$this->documentos->travarERecarregar($documento, $tenant)) {
                throw new DocumentoNaoEncontrado();
            }
            $documento->cancelarEnvio();
            $this->trilha->acrescentar($documento, TipoDeEvento::EnvioCancelado, [], $ator);
        });

        return $this->apresentacao->detalhe($documento, $this->eventos->doDocumento($documento));
    }
}
