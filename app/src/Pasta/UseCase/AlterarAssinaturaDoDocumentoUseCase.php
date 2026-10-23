<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Enum\OrigemDaAssinatura;
use App\Pasta\Exception\AssinaturaDoDocumentoRecusadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Service\PermissionChecker;
use Psr\Clock\ClockInterface;

/**
 * "Marcar como assinado" e "Desmarcar assinatura" do ⋮ do arquivo, na aba Financeiro.
 *
 * História: o cliente assinou o contrato à caneta, o escritório escaneou e enviou. O arquivo não traz
 * assinatura digital, então o selo não nasceu sozinho no upload; o advogado marca pelo ⋮ e a linha
 * ganha o selo "Assinado". Marcou o arquivo errado: desmarca. A assinatura digital (vista no upload)
 * não se desmarca pela tela, porque o arquivo continua assinado.
 *
 * Guardas: documento desta pasta e deste escritório (senão "não encontrado", 404), permissão de EDITAR
 * a pasta (a mesma de Renomear e Excluir no ⋮, 403). Pasta na lápide recebe a recusa do
 * `PastaSomenteLeituraListener`, como todo POST nela.
 */
final class AlterarAssinaturaDoDocumentoUseCase
{
    public function __construct(
        private readonly PastaDocumentoRepository $documentos,
        private readonly PermissionChecker $permissionChecker,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function marcar(Pasta $pasta, PastaDocumento $documento, User $usuario, Tenant $tenant): void
    {
        $this->conferir($pasta, $documento, $usuario, $tenant);

        if ($documento->estaAssinado()) {
            throw new AssinaturaDoDocumentoRecusadaException('Este arquivo já está marcado como assinado.');
        }

        $documento->marcarAssinadoManualmente($usuario, $this->relogio->now());
        $this->documentos->salvar();
    }

    public function desmarcar(Pasta $pasta, PastaDocumento $documento, User $usuario, Tenant $tenant): void
    {
        $this->conferir($pasta, $documento, $usuario, $tenant);

        if ($documento->getAssinaturaOrigem() === OrigemDaAssinatura::Digital) {
            throw new AssinaturaDoDocumentoRecusadaException('A assinatura digital é do próprio arquivo e não se desmarca.');
        }

        if (!$documento->estaAssinado()) {
            throw new AssinaturaDoDocumentoRecusadaException('Este arquivo não está marcado como assinado.');
        }

        $documento->desmarcarAssinaturaManual();
        $this->documentos->salvar();
    }

    private function conferir(Pasta $pasta, PastaDocumento $documento, User $usuario, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant || $documento->getTenant() !== $tenant || $documento->getPasta() !== $pasta) {
            throw new PastaDeOutroEscritorioException('Documento não encontrado.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
    }
}
