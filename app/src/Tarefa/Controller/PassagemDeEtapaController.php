<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\DTO\DevolverMetaInput;
use App\Tarefa\DTO\PassarEtapaInput;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Exception\PassagemRecusadaException;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\UseCase\DevolverMetaUseCase;
use App\Tarefa\UseCase\MontarEtapaDaMetaUseCase;
use App\Tarefa\UseCase\PassarEtapaDaMetaUseCase;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Passagem de etapa e devolução da meta (desenhos 04 e 04B de 22/10; spec `minhas-metas-esteira-2210.md`, Fase 3):
 * os dados do modal "Concluir etapa e passar adiante", a passagem e o "Devolver para X". Respostas em JSON para o
 * `meta-passagem.js`, que mostra o erro no modal e, no sucesso, recarrega a tela com o aviso.
 *
 * Guarda: escritório da sessão, módulo `tarefas` e a guarda da meta ({@see AcessoAMeta}: outro escritório ou pasta
 * oculta é 404). Quem pode passar e devolver é regra dos UseCases (D3); a falta de permissão vira 403.
 */
#[Route('/tarefas')]
final class PassagemDeEtapaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AcessoAMeta $acessoAMeta,
    ) {
    }

    #[Route('/{id}/passagem', name: 'tarefa_passagem', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function dados(Tarefa $tarefa, MontarEtapaDaMetaUseCase $useCase, QuemCuidaDaMeta $quemCuida, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        [$usuario, $tenant] = $this->acesso($tarefa);
        if (!$quemCuida->ehResponsavelOuCriador($tarefa, $usuario)) {
            return $this->erro(PassagemRecusadaException::semPermissaoParaPassar());
        }
        if ($tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA) {
            return $this->erro(PassagemRecusadaException::jaConcluida());
        }

        return $this->json($useCase->dadosDoModal($tarefa, $tenant, new \DateTimeImmutable()) + [
            'url'   => $this->generateUrl('tarefa_passar', ['id' => $tarefa->getId()]),
            'token' => $csrf->getToken('passar_etapa_' . $tarefa->getId())->getValue(),
        ]);
    }

    #[Route('/{id}/passar', name: 'tarefa_passar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function passar(Tarefa $tarefa, Request $request, PassarEtapaDaMetaUseCase $useCase, ColaboradoresAtribuiveis $atribuiveis): JsonResponse
    {
        [$usuario, $tenant] = $this->acesso($tarefa);
        $this->exigirCsrf('passar_etapa_' . $tarefa->getId(), $request);
        $idDoResponsavel = $request->request->getInt('responsavel');
        $responsavel = $idDoResponsavel > 0 ? $atribuiveis->resolver($idDoResponsavel, $tenant) : null;
        if ($idDoResponsavel > 0 && $responsavel === null) {
            return $this->json(['ok' => false, 'erro' => 'Escolha um membro da equipe para o responsável.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $input = new PassarEtapaInput(
            (string) $request->request->get('tipo', ''),
            (string) $request->request->get('destino', ''),
            (string) $request->request->get('prazo', ''),
            (string) $request->request->get('pendencias', ''),
            (string) $request->request->get('feito', ''),
        );

        try {
            $feito = $useCase->executar($tarefa, $usuario, $tenant, $input, $responsavel, new \DateTimeImmutable());
        } catch (PassagemRecusadaException|DespachoRecusadoException $e) {
            return $this->erro($e);
        }

        return $this->json(['ok' => true, 'proxima' => $feito->proxima, 'pasta' => $feito->pasta, 'novaMeta' => $feito->novaMetaId]);
    }

    #[Route('/{id}/devolver', name: 'tarefa_devolver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function devolver(Tarefa $tarefa, Request $request, DevolverMetaUseCase $useCase): JsonResponse
    {
        [$usuario, $tenant] = $this->acesso($tarefa);
        $this->exigirCsrf('devolver_meta_' . $tarefa->getId(), $request);
        $input = new DevolverMetaInput((string) $request->request->get('motivo', ''), (string) $request->request->get('o_que_falta', ''));

        try {
            $feito = $useCase->executar($tarefa, $usuario, $tenant, $input, new \DateTimeImmutable());
        } catch (PassagemRecusadaException|DespachoRecusadoException $e) {
            return $this->erro($e);
        }

        return $this->json(['ok' => true, 'para' => $feito->para, 'avisado' => $feito->avisado]);
    }

    private function erro(PassagemRecusadaException|DespachoRecusadoException $e): JsonResponse
    {
        $proibido = $e instanceof PassagemRecusadaException && $e->ehFaltaDePermissao();

        return $this->json(['ok' => false, 'erro' => $e->getMessage()], $proibido ? Response::HTTP_FORBIDDEN : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function exigirCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    /** @return array{0: User, 1: Tenant} */
    private function acesso(Tarefa $tarefa): array
    {
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($usuario, $tenant, 'tarefas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }
        $this->acessoAMeta->exigir($tarefa, $tenant, $usuario);

        return [$usuario, $tenant];
    }
}
