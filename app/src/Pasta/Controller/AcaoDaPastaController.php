<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\EditarAcaoDaPastaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Sync\Service\ReconciliadorDePasta;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Editar ação da pasta" da aba Processo (decisão do dono de 23/10/2026; desenho 02: "Ação · vem do menu
 * Processo"). O formulário mora em `_processos_vinculados.html.twig`, aberto pelo ⋮ do cartão do processo
 * principal ou pelo ⋮ do cabeçalho. Mesmo contrato `{sucesso, aviso, html}` da edição no cartão do processo:
 * com XHR, o pasta-processo.js troca a aba pelo parcial re-renderizado; sem XHR, volta para a aba Processo.
 *
 * Ordem das recusas (nada é gravado em nenhuma): pasta de outro escritório 404 → CSRF 403 → campo ausente 422 →
 * sem EDIT na pasta 403 (no UseCase; o `canAccessResource` já traz o teto do cadeado) → texto longo 422. Antes da
 * action, a `BarreiraDoCadeadoListener` recusa quem só visualiza pela tranca e o `PastaSomenteLeituraListener`
 * recusa a lápide, como em todo POST de pasta.
 *
 * A Ação faz parte do nome da pasta no Drive: como no "Editar dados", o nome de antes é capturado ANTES de gravar e
 * a sincronização só é enfileirada se ele mudou.
 */
#[Route('/pasta')]
final class AcaoDaPastaController extends AbstractController
{
    public function __construct(
        private readonly EditarAcaoDaPastaUseCase $editarAcao,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly SincronizacaoPastaDispatcher $syncDispatcher,
    ) {
    }

    #[Route(
        '/{id}/acao',
        name: 'pasta_acao_salvar',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function salvar(Pasta $pasta, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        $isXhr   = $request->isXmlHttpRequest();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->erro($pasta, $isXhr, 'Pasta não encontrada.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid('pasta_acao_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->erro($pasta, $isXhr, 'Token de segurança inválido.', Response::HTTP_FORBIDDEN);
        }
        // Campo AUSENTE não é "limpar" (a regra do `editar()` do PastaController): só o campo presente decide.
        if (!$request->request->has('nome_acao')) {
            return $this->erro($pasta, $isXhr, 'Informe a ação da pasta.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $nomeAntes = ReconciliadorDePasta::nomeEsperado($pasta->getNup(), $pasta->getNomeCliente(), $pasta->getNomeAcao());

        try {
            $mudou = $this->editarAcao->executar($pasta, (string) $request->request->get('nome_acao'), $usuario, $tenant);
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->erro($pasta, $isXhr, $e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->erro($pasta, $isXhr, $e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->erro($pasta, $isXhr, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($mudou) {
            $this->syncDispatcher->despacharSeNomeMudou($pasta, $usuario, $tenant, $nomeAntes);
        }

        return $this->sucesso($pasta, $usuario, $tenant, $isXhr, $mudou ? 'Ação da pasta salva.' : 'A ação da pasta não mudou.');
    }

    /** Com XHR, o parcial da aba re-renderizado (contrato `{sucesso, aviso, html}`); sem XHR, volta à aba Processo. */
    private function sucesso(Pasta $pasta, User $usuario, Tenant $tenant, bool $isXhr, string $aviso): Response
    {
        if ($isXhr) {
            return $this->json([
                'sucesso' => true,
                'aviso'   => $aviso,
                'html'    => $this->renderView('pasta/_processos_vinculados.html.twig', [
                    'pasta'           => $pasta,
                    'podeEditarPasta' => $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT),
                ]),
            ]);
        }

        $this->addFlash('success', $aviso);

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'processo']);
    }

    private function erro(Pasta $pasta, bool $isXhr, string $mensagem, int $status): Response
    {
        if ($isXhr) {
            return $this->json(['erro' => $mensagem], $status);
        }
        if ($status === Response::HTTP_NOT_FOUND) {
            throw $this->createNotFoundException($mensagem);
        }
        if ($status === Response::HTTP_FORBIDDEN) {
            throw $this->createAccessDeniedException($mensagem);
        }
        $this->addFlash('danger', $mensagem);

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'processo']);
    }
}
