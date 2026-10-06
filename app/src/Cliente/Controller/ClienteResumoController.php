<?php

declare(strict_types=1);

namespace App\Cliente\Controller;

use App\Cliente\DTO\ClienteResumoOutput;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\PendenciasDoCadastro;
use App\Cliente\Service\QualificacaoDoCliente;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fragmento HTML da janela "Detalhes do cliente" da tela da pasta (desenho 1.2.3,
 * dc L.779-836). SOMENTE LEITURA: nome, documento, contatos, "cliente desde" e as
 * pastas do mesmo cliente.
 *
 * Guardas, nesta ordem:
 *  1. o cliente é do escritório atual — o `find()` passa pelo TenantFilter, e a
 *     posse é conferida de novo aqui (outro tenant = 404, nunca 403: 403 confirmaria
 *     que o id existe);
 *  2. o usuário pode VER o cliente (`canAccessResource('cliente', view)`, a mesma
 *     regra da ficha `cliente_show`) — a janela mostra os mesmos dados que ela;
 *  3. cada pasta da lista passa por `canAccessResource('pasta', view)`, a mesma
 *     regra que abre a pasta. O tenant sozinho NÃO basta: um colaborador com acesso
 *     a uma pasta isolada não pode descobrir, por aqui, número e ação das outras
 *     pastas do mesmo cliente.
 */
final class ClienteResumoController extends AbstractController
{
    public function __construct(
        private readonly ClienteRepository $clienteRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PendenciasDoCadastro $pendencias,
        private readonly QualificacaoDoCliente $qualificacao,
    ) {}

    #[Route('/clientes/{id}/resumo', name: 'cliente_resumo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function resumo(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        $cliente = $this->clienteRepository->find($id);
        if ($cliente === null || $cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem acesso a este cliente.');
        }

        $visiveis = array_values(array_filter(
            $this->pastaRepository->findByCliente($cliente, $tenant),
            fn (Pasta $pasta): bool => $this->permissionChecker->canAccessResource(
                $usuario,
                $tenant,
                AccessRequest::RESOURCE_PASTA,
                (int) $pasta->getId(),
                AccessRequest::ACTION_VIEW,
            ),
        ));

        $pastaAtual   = $request->query->get('pasta');
        $pastaAtualId = \is_string($pastaAtual) && ctype_digit($pastaAtual) ? (int) $pastaAtual : null;

        $resumo = ClienteResumoOutput::montar(
            $cliente,
            $visiveis,
            $pastaAtualId,
            $this->pendencias->de($cliente),
            $this->qualificacao->de($cliente),
            $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_EDIT),
        );

        return $this->render('cliente/_resumo.html.twig', ['resumo' => $resumo]);
    }
}
