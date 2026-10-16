<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\DocumentoDoAgenteOutput;
use App\Inteligencia\DTO\DocumentosDaPastaDoAgenteOutput;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;

/**
 * `listar_documentos` do Agente de Pastas (Fase 1, só leitura).
 *
 * Diferente da busca, aqui já é o CONTEÚDO da pasta: exige o mesmo que a aba Documentos — a pasta do
 * escritório (senão 404, que não confirma a existência) e `canAccessResource(pasta, view)` (senão 403).
 * A lixeira fica de fora pelo filtro de lixeira do Doctrine, como na aba.
 */
final class ListarDocumentosDaPastaDoAgenteUseCase
{
    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly PastaDocumentoRepository $documentoRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @throws PastaNaoEncontradaException
     * @throws SemPermissaoParaVerPastaException
     */
    public function executar(int $pastaId, User $usuario, Tenant $tenant): DocumentosDaPastaDoAgenteOutput
    {
        $pasta = $this->pastaRepository->findOneBy(['id' => $pastaId, 'tenant' => $tenant])
            ?? throw new PastaNaoEncontradaException($pastaId);

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem acesso a esta pasta.');
        }

        return new DocumentosDaPastaDoAgenteOutput(
            pastaId: $pastaId,
            nup: $pasta->getNup(),
            documentos: array_map(DocumentoDoAgenteOutput::fromEntity(...), $this->documentoRepository->findByPastaComSecao($pasta, $tenant)),
        );
    }
}
