<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\DTO\LeituraDoClienteOutput;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaVerClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\LeituraDoCliente;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * Ler um cliente, como a janela "Detalhes do cliente" (`cliente_resumo`) e a ferramenta `ler_clientes` do conector de
 * I.A. (spec mcp-remoto-oauth, 6.4). Sem sessão, `TenantContext` nem filtro do Doctrine: pessoa e escritório vêm por
 * parâmetro.
 *
 * Guardas, nesta ordem:
 *  1. o cliente é do escritório (a busca filtra pelo escritório, sem depender do TenantFilter); de outro escritório ou
 *     inexistente, a mesma {@see ClienteNaoEncontradoException} (a tela dá 404: 403 confirmaria que o id existe);
 *  2. a pessoa pode VER o cliente (`canAccessResource('cliente', view)`, a regra da ficha), senão
 *     {@see SemPermissaoParaVerClienteException}.
 *
 * Depois, a {@see LeituraDoCliente} monta as pastas que a pessoa abre, as pendências e o "pode editar".
 */
final class LerClienteUseCase
{
    public function __construct(
        private readonly ClienteRepository $clientes,
        private readonly PermissionChecker $permissoes,
        private readonly LeituraDoCliente $leitura,
    ) {
    }

    /**
     * @throws ClienteNaoEncontradoException
     * @throws SemPermissaoParaVerClienteException
     */
    public function executar(int $clienteId, User $quemOlha, Tenant $escritorio): LeituraDoClienteOutput
    {
        $cliente = $this->clientes->findOneBy(['id' => $clienteId, 'tenant' => $escritorio]);
        if ($cliente === null || $cliente->getTenant()?->getId() !== $escritorio->getId()) {
            throw new ClienteNaoEncontradoException($clienteId);
        }

        if (!$this->permissoes->canAccessResource($quemOlha, $escritorio, AccessRequest::RESOURCE_CLIENTE, $clienteId, AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerClienteException(sprintf('Sem acesso ao cliente %d.', $clienteId));
        }

        return $this->leitura->montar($cliente, $quemOlha, $escritorio);
    }
}
