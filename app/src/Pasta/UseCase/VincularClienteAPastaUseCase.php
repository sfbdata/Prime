<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Repository\ClienteRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\ClienteJaVinculadoAPastaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vincular um cliente já cadastrado à pasta (aba Partes, "Vincular cliente"; `pasta_cliente_vincular`), e a ação
 * `vincular_a_pasta` da ferramenta `atualizar_cliente` do conector de I.A. (spec mcp-remoto-oauth, 6.6).
 *
 * História: a advogada abre a pasta, procura o cliente que já existe no escritório e o vincula. Se a pasta ainda não
 * tinha cliente, ele vira o principal ({@see Pasta::addCliente()}); do segundo em diante, o principal não muda.
 *
 * Guardas, nesta ordem, sem sessão (pessoa e escritório por parâmetro):
 *  1. a pasta é do escritório ({@see PastaDeOutroEscritorioException}, a tela dá 404);
 *  2. a pessoa pode EDITAR a pasta ({@see AcessoAPasta::podeEditar()}, com o cadeado; {@see SemPermissaoParaEditarPastaException});
 *  3. o cliente é do escritório, buscado com o escritório explícito, e a pessoa pode VÊ-LO (auditoria 3.17: enumerar
 *     ids não revela cadastro de quem ela não vê); senão, {@see ClienteNaoEncontradoException}, o mesmo 404;
 *  4. o cliente ainda não está na pasta ({@see ClienteJaVinculadoAPastaException}, 422).
 *
 * Não confere a lixeira nem o envio automático do Push: a tela não conferia (a pasta excluída não mostra o botão), e o
 * conector recusa os dois antes de chamar aqui.
 */
final class VincularClienteAPastaUseCase
{
    public function __construct(
        private readonly ClienteRepository $clientes,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly PermissionChecker $permissoes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return Cliente o cliente vinculado
     *
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaEditarPastaException
     * @throws ClienteNaoEncontradoException
     * @throws ClienteJaVinculadoAPastaException
     */
    public function executar(Pasta $pasta, int $clienteId, User $usuario, Tenant $tenant): Cliente
    {
        $this->exigirEdicaoDaPasta($pasta, $usuario, $tenant);
        $cliente = $this->clienteVisivel($clienteId, $usuario, $tenant);
        if ($pasta->getClientes()->contains($cliente)) {
            throw new ClienteJaVinculadoAPastaException();
        }

        $pasta->addCliente($cliente);
        $this->em->flush();

        return $cliente;
    }

    private function exigirEdicaoDaPasta(Pasta $pasta, User $usuario, Tenant $tenant): void
    {
        if ($pasta->getTenant()?->getId() !== $tenant->getId()) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeEditar($usuario, $pasta)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
    }

    private function clienteVisivel(int $clienteId, User $usuario, Tenant $tenant): Cliente
    {
        $cliente = $this->clientes->findOneBy(['id' => $clienteId, 'tenant' => $tenant]);
        if (!$cliente instanceof Cliente
            || !$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $clienteId, AccessRequest::ACTION_VIEW)) {
            throw new ClienteNaoEncontradoException($clienteId);
        }

        return $cliente;
    }
}
