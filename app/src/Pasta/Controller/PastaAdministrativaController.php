<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\DefinirPastaAdministrativaUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Interruptor "Administrativo sem processo" do cabeçalho da aba Processo (desenho 1.2.3,
 * `admSPtoggle`).
 *
 * O interruptor é um formulário comum (POST + CSRF) dentro de `_processos_vinculados.html.twig`:
 * sem XHR, volta para a aba Processo da pasta com a mensagem. Com XHR (`X-Requested-With`),
 * responde JSON com o parcial re-renderizado — o mesmo contrato `{sucesso, html}` de
 * vincular/desvincular, para quando o JS da tela passar a interceptá-lo.
 *
 * Guardas: pasta do escritório da sessão (404 — o resolver busca por PK e o TenantFilter não se
 * aplica a `find()`), permissão de EDITAR a pasta (403) — ambas no UseCase —, e CSRF por pasta
 * (403), porque é escrita. Pasta excluída (lápide) recebe a recusa do PastaSomenteLeituraListener,
 * como todo POST nela.
 */
#[Route('/pasta')]
final class PastaAdministrativaController extends AbstractController
{
    public function __construct(
        private readonly DefinirPastaAdministrativaUseCase $definirAdministrativa,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/{id}/administrativa',
        name: 'pasta_administrativa_definir',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function definir(Pasta $pasta, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        $isXhr   = $request->isXmlHttpRequest();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->erro($isXhr, 'Pasta não encontrada.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_administrativa_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->erro($isXhr, 'Token de segurança inválido.', Response::HTTP_FORBIDDEN);
        }

        $administrativa = filter_var($request->request->get('administrativa'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($administrativa === null) {
            return $this->erro($isXhr, 'Valor inválido para "Administrativo sem processo".', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->definirAdministrativa->executar($pasta, $administrativa, $usuario, $tenant);
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->erro($isXhr, $e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->erro($isXhr, $e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        if ($isXhr) {
            return $this->json([
                'sucesso'        => true,
                'administrativa' => $pasta->isAdministrativa(),
                'html'           => $this->renderView('pasta/_processos_vinculados.html.twig', ['pasta' => $pasta]),
            ]);
        }

        $this->addFlash('success', $administrativa
            ? 'Pasta marcada como Administrativo sem processo.'
            : 'Marcação de Administrativo sem processo removida.');

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'processo']);
    }

    private function erro(bool $isXhr, string $mensagem, int $status): Response
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

        return new Response($mensagem, $status);
    }
}
