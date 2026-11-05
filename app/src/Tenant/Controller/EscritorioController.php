<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Service\Tenant\TenantContext;
use App\Tenant\DTO\CriarEscritorioInput;
use App\Tenant\Form\CriarEscritorioType;
use App\Tenant\UseCase\CriarEscritorioUseCase;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\UseCase\ExcluirEscritorioUseCase;
use App\Tenant\UseCase\SairDoEscritorioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EscritorioController extends AbstractController
{
    private const CHAVE_CONFIRMACAO_NAO_CONFERE = 'escritorio.flash.confirmacao_nao_confere';

    /** Id do token CSRF da rota antiga de exclusão (+ id do escritório). */
    public const CSRF_EXCLUIR = 'escritorio_excluir_';

    public function __construct(
        private readonly SairDoEscritorioUseCase $sairDoEscritorio,
        private readonly CriarEscritorioUseCase $criarEscritorio,
        private readonly ExcluirEscritorioUseCase $excluirEscritorio,
        private readonly TenantContext $tenantContext,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly TranslatorInterface $tradutor,
    ) {}

    /**
     * Recusa, com flash, a conta criada por um Master (spec perfis-1.2-contas C5 e Q4, regra em
     * {@see RegraDosMasters::recusaParaExcluir()}) e a confirmação com o nome errado.
     */
    #[Route('/escritorio/{id}/excluir', name: 'escritorio_excluir', methods: ['POST'])]
    public function excluir(Tenant $tenant, Request $request): Response
    {
        $usuario = $this->exigirQuemPodeExcluir($tenant, $request);

        $recusa = $this->regraDosMasters->recusaParaExcluir($tenant) ?? ($request->request->getString('confirmar_nome') !== (string) $tenant->getName() ? $this->tradutor->trans(self::CHAVE_CONFIRMACAO_NAO_CONFERE) : null);
        if ($recusa !== null) {
            $this->addFlash('danger', $recusa);

            return $this->redirectToRoute('app_tenant_show', ['tenantId' => $tenant->getId()]);
        }

        try {
            $this->excluirEscritorio->executar($tenant, $usuario);
        } catch (PedidoDeExclusaoRecusado $e) {
            // Pedido de exclusão pendente: só o Master decide (403); decidido ao mesmo tempo por outro (409).
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_tenant_show', ['tenantId' => $tenant->getId()]);
        }

        if ($this->tenantContext->getCurrentTenant()?->getId() === $tenant->getId()) {
            $this->tenantContext->clearCurrentTenant();
        }

        $this->addFlash('success', $this->tradutor->trans('escritorio.flash.escritorio_excluido'));

        return $this->redirectToRoute('tenant_selecionar');
    }

    /**
     * Usuário logado, CSRF válido e só o Master (como `app_tenant_delete`). O escritório exclui a própria conta pelo
     * pedido de exclusão do Meu Perfil, que o Master decide: o admin do escritório não pula esse caminho por aqui
     * (auditoria 2.06). O id do token é desta rota, nunca o genérico `excluir_{id}` que outra tela poderia emitir.
     */
    private function exigirQuemPodeExcluir(Tenant $tenant, Request $request): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_EXCLUIR . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        if (!in_array('ROLE_SUPER_ADMIN', $usuario->getRoles(), true)) {
            throw $this->createAccessDeniedException('Somente o Master exclui o escritório por esta rota.');
        }

        return $usuario;
    }

    #[Route('/escritorio/criar', name: 'escritorio_criar', methods: ['GET', 'POST'])]
    public function criar(Request $request): Response
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $exigirOab = $usuario->getOabNumero() === null;
        $input     = new CriarEscritorioInput();
        $form      = $this->createForm(CriarEscritorioType::class, $input, ['exigir_oab' => $exigirOab]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $tenant = $this->criarEscritorio->executar($input, $usuario);
                $this->tenantContext->setCurrentTenant($tenant->getId());
                $this->addFlash('success', $this->tradutor->trans('escritorio.flash.escritorio_criado'));

                return $this->redirectToRoute('expediente_index');
            } catch (\DomainException | \InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('tenant/criar.html.twig', [
            'form'      => $form,
            'exigirOab' => $exigirOab,
        ]);
    }

    #[Route('/escritorio/{id}/sair', name: 'escritorio_sair', methods: ['POST'])]
    public function sair(Tenant $tenant, Request $request): Response
    {
        $usuario = $this->getUser();
        if ($usuario === null) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('sair_' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $this->sairDoEscritorio->executar($usuario, $tenant);

            if ($this->tenantContext->getCurrentTenant()?->getId() === $tenant->getId()) {
                $this->tenantContext->clearCurrentTenant();
            }

            $this->addFlash('success', 'Você saiu do escritório.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('tenant_selecionar');
    }
}
