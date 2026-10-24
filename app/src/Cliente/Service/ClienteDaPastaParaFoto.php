<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\FotoDoClienteRecusadaException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;

/**
 * As guardas comuns de trocar e remover a foto do cliente pelo cabeçalho da pasta, na ordem em que recusam:
 *
 *  1. a pasta é do escritório da sessão (senão 404, sem revelar que existe);
 *  2. quem pede VÊ a pasta (o pedido vem dela);
 *  3. o cliente é do escritório e está na pasta (o principal está sempre entre os clientes dela); senão 404;
 *  4. quem pede pode EDITAR o cliente (`cliente` + `edit`, o mesmo do Salvar do Cadastro);
 *  5. a pasta não está excluída (lápide é somente leitura).
 */
final class ClienteDaPastaParaFoto
{
    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly ClienteRepository $clientes,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /** @return array{0: Pasta, 1: Cliente} */
    public function carregar(int $pastaId, int $clienteId, User $usuario, Tenant $tenant): array
    {
        $pasta = $this->pastaVisivel($pastaId, $usuario, $tenant);
        $cliente = $this->clienteDaPasta($pasta, $clienteId, $tenant);

        if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $clienteId, AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarClienteException('Sem permissão para editar este cliente.');
        }
        if ($pasta->estaExcluida()) {
            throw new FotoDoClienteRecusadaException('Esta pasta foi excluída e está somente para leitura. Restaure a pasta para voltar a editá-la.');
        }

        return [$pasta, $cliente];
    }

    private function pastaVisivel(int $pastaId, User $usuario, Tenant $tenant): Pasta
    {
        $pasta = $this->pastas->find($pastaId);
        if (!$pasta instanceof Pasta || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem acesso a esta pasta.');
        }

        return $pasta;
    }

    private function clienteDaPasta(Pasta $pasta, int $clienteId, Tenant $tenant): Cliente
    {
        $cliente = $this->clientes->find($clienteId);
        if (!$cliente instanceof Cliente || $cliente->getTenant()?->getId() !== $tenant->getId() || !$pasta->getClientes()->contains($cliente)) {
            throw new ClienteNaoEncontradoException($clienteId);
        }

        return $cliente;
    }
}
