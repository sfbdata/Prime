<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Repository\TenantRoleRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Exception\MembroDaEquipeNaoEncontrado;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\UseCase\AlterarSituacaoNaEquipeUseCase;
use App\Tenant\UseCase\DemitirDaEquipeUseCase;
use App\Tenant\UseCase\ListarEquipeDaContaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aba "Minha conta" do modal "Conta do escritório": equipe, situação e demitir (desenho bj-contas.js, VS 18.10.26; spec
 * docs/specs/equipe-da-conta.md §4). Sempre no escritório da sessão; só o primário dele ou o Master. As ações respondem
 * JSON para o JS do modal; o CSRF vem no header X-CSRF-Token (token 'ajax' do base).
 */
#[Route('/escritorio/equipe', name: 'escritorio_equipe')]
final class EquipeDaContaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListarEquipeDaContaUseCase $listar,
        private readonly AlterarSituacaoNaEquipeUseCase $alterarSituacao,
        private readonly DemitirDaEquipeUseCase $demitir,
        private readonly TenantRoleRepository $perfis,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function equipe(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant() ?? throw $this->createAccessDeniedException();
        try {
            $membros = $this->listar->executar($this->usuario(), $tenant);
        } catch (OperacaoDePrimarioRecusada) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('menu_usuario/_minha_conta.html.twig', [
            'escritorio'          => $tenant->getName(),
            'membros'             => $membros,
            'perfisSecundarios'   => array_map(
                static fn ($p): array => ['id' => (int) $p->getId(), 'nome' => $p->getNomeExibido()],
                $this->perfis->findSecundarios($tenant),
            ),
        ]);
    }

    #[Route('/{id}/situacao', name: '_situacao', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function situacao(int $id, Request $request): JsonResponse
    {
        $dados    = json_decode($request->getContent(), true);
        $situacao = SituacaoNaEquipe::tryFrom(is_array($dados) ? (string) ($dados['situacao'] ?? '') : '');
        if ($situacao === null) {
            return new JsonResponse(['erro' => 'Situação inválida.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->acao($request, fn (): string => $this->mensagemDaSituacao(
            $this->alterarSituacao->executar($this->usuario(), $this->tenantAtual(), $id, $situacao)->getUser()->getFullName(),
            $situacao,
        ));
    }

    #[Route('/{id}/demitir', name: '_demitir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function demitir(int $id, Request $request): JsonResponse
    {
        return $this->acao($request, fn (): string => $this->demitir->executar($this->usuario(), $this->tenantAtual(), $id) . ' foi removido(a) da conta');
    }

    /** @param callable(): string $executar devolve a mensagem de sucesso */
    private function acao(Request $request, callable $executar): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }
        try {
            return new JsonResponse(['ok' => true, 'mensagem' => $executar()]);
        } catch (MembroDaEquipeNaoEncontrado) {
            return new JsonResponse(['erro' => 'Pessoa não encontrada nesta conta.'], Response::HTTP_NOT_FOUND);
        } catch (\DomainException | \InvalidArgumentException $e) {
            // Recusas das regras (situação e as travas da remoção, que usam as duas exceções).
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function mensagemDaSituacao(?string $nome, SituacaoNaEquipe $situacao): string
    {
        return $nome . ': ' . mb_strtolower($situacao->rotulo());
    }

    private function tenantAtual(): \App\Entity\Tenant\Tenant
    {
        return $this->tenantContext->getCurrentTenant() ?? throw new MembroDaEquipeNaoEncontrado();
    }

    private function usuario(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
