<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaVerClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\DocumentosVisiveisDoCadastro;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * Os documentos do cadastro que a pessoa vê na tela ({@see DocumentosVisiveisDoCadastro}), para a ferramenta
 * `ler_clientes` do conector de I.A. Leitura sem sessão: pessoa e escritório vêm por parâmetro.
 *
 * Guardas: o cliente é do escritório (de outro, ou inexistente, {@see ClienteNaoEncontradoException}) e a pessoa pode
 * VER o cliente (recurso `cliente`, ação `view`, a regra de `cliente_documento_view`), senão
 * {@see SemPermissaoParaVerClienteException}.
 */
final class ListarDocumentosDoClienteUseCase
{
    public function __construct(
        private readonly ClienteRepository $clientes,
        private readonly PermissionChecker $permissoes,
        private readonly DocumentosVisiveisDoCadastro $visiveis,
    ) {
    }

    /**
     * @return list<ClienteDocumento>
     *
     * @throws ClienteNaoEncontradoException
     * @throws SemPermissaoParaVerClienteException
     */
    public function executar(int $clienteId, User $quemOlha, Tenant $escritorio): array
    {
        $cliente = $this->clientes->findOneBy(['id' => $clienteId, 'tenant' => $escritorio]);
        if ($cliente === null || $cliente->getTenant()?->getId() !== $escritorio->getId()) {
            throw new ClienteNaoEncontradoException($clienteId);
        }
        if (!$this->permissoes->canAccessResource($quemOlha, $escritorio, AccessRequest::RESOURCE_CLIENTE, $clienteId, AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerClienteException();
        }

        return $this->visiveis->de($cliente, $quemOlha, $escritorio);
    }
}
