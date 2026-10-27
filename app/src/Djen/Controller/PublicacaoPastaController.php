<?php

declare(strict_types=1);

namespace App\Djen\Controller;

use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\UseCase\DesvincularPublicacaoDaPastaUseCase;
use App\Djen\UseCase\VincularPublicacaoAPastaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Vincular e desvincular a pasta pela linha da publicação (desenho 11, aba Publicações). */
#[Route('/push-processual/publicacoes')]
final class PublicacaoPastaController extends AbstractController
{
    private const LIMITE_DA_BUSCA = 8;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PastaRepository $pastas,
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly VincularPublicacaoAPastaUseCase $vincular,
        private readonly DesvincularPublicacaoDaPastaUseCase $desvincular,
    ) {
    }

    #[Route('/pastas', name: 'push_processual_pastas_busca', methods: ['GET'])]
    public function buscar(Request $request): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->pastas->buscarParaVinculo((string) $request->query->get('q', ''), $tenant, self::LIMITE_DA_BUSCA, [], $usuario));
    }

    /** Partes da publicação (destinatários do DJEN) para o modal "Vincular a uma pasta". */
    #[Route('/{id}/partes', name: 'push_processual_publicacao_partes', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function partes(int $id): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }
        $publicacao = $this->publicacoes->findOneByIdDoTenant($id, $tenant);
        if ($publicacao === null) {
            return new JsonResponse([], Response::HTTP_NOT_FOUND);
        }
        $nomes = [];
        foreach ($publicacao->getDestinatarios() as $d) {
            $nome = trim((string) ($d['nome'] ?? ''));
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }

        return new JsonResponse(['partes' => implode(' × ', array_unique($nomes))]);
    }

    #[Route('/{id}/vincular', name: 'push_processual_publicacao_vincular', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function vincularAction(int $id, Request $request): Response
    {
        return $this->agir($request, function (Tenant $tenant, User $usuario) use ($id, $request): string {
            $trocar = (int) $request->request->get('trocar_da_pasta_id', 0);
            $nup = trim((string) $request->request->get('nup', ''));
            $pasta = $nup !== '' && (int) $request->request->get('pasta_id', 0) === 0
                ? $this->vincular->executarPorNup($tenant, $usuario, $id, $nup, $trocar > 0 ? $trocar : null)
                : $this->vincular->executar($tenant, $usuario, $id, (int) $request->request->get('pasta_id', 0), $trocar > 0 ? $trocar : null);

            return sprintf('Processo %s vinculado à Pasta %s.', (string) $request->request->get('numero', ''), $pasta->getNup() ?? '#' . $pasta->getId());
        });
    }

    /** Aba Processos da OAB: o processo é identificado pelo número, sem publicação na mão. */
    #[Route('/processo/vincular', name: 'push_processual_processo_vincular', methods: ['POST'])]
    public function vincularProcessoAction(Request $request): Response
    {
        return $this->agir($request, function (Tenant $tenant, User $usuario) use ($request): string {
            $trocar = (int) $request->request->get('trocar_da_pasta_id', 0);
            $numero = (string) $request->request->get('numero', '');
            $nup = trim((string) $request->request->get('nup', ''));
            $pasta = $nup !== '' && (int) $request->request->get('pasta_id', 0) === 0
                ? $this->vincular->executarPorNumeroENup($tenant, $usuario, $numero, $nup, $trocar > 0 ? $trocar : null)
                : $this->vincular->executarPorNumero($tenant, $usuario, $numero, (int) $request->request->get('pasta_id', 0), $trocar > 0 ? $trocar : null);

            return sprintf('Processo %s vinculado à Pasta %s.', $numero, $pasta->getNup() ?? '#' . $pasta->getId());
        });
    }

    #[Route('/processo/desvincular', name: 'push_processual_processo_desvincular', methods: ['POST'])]
    public function desvincularProcessoAction(Request $request): Response
    {
        return $this->agir($request, function (Tenant $tenant, User $usuario) use ($request): string {
            $numero = (string) $request->request->get('numero', '');
            $pasta = $this->desvincular->executarPorNumero($tenant, $usuario, $numero, (int) $request->request->get('pasta_id', 0));

            return sprintf('Processo %s desvinculado da Pasta %s.', $numero, $pasta->getNup() ?? '#' . $pasta->getId());
        });
    }

    #[Route('/{id}/desvincular', name: 'push_processual_publicacao_desvincular', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function desvincularAction(int $id, Request $request): Response
    {
        return $this->agir($request, function (Tenant $tenant, User $usuario) use ($id, $request): string {
            $pasta = $this->desvincular->executar($tenant, $usuario, $id, (int) $request->request->get('pasta_id', 0));

            return sprintf('Processo %s desvinculado da Pasta %s.', (string) $request->request->get('numero', ''), $pasta->getNup() ?? '#' . $pasta->getId());
        });
    }

    /** @param callable(Tenant, User): string $acao devolve a mensagem de sucesso */
    private function agir(Request $request, callable $acao): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo Push Processual.');

            return $this->redirectToRoute('homepage');
        }

        if (!$this->isCsrfTokenValid('push_publicacoes', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
        } else {
            try {
                $this->addFlash('success', $acao($tenant, $usuario));
            } catch (VinculoDaPublicacaoInvalidoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        $destino = DjenController::caminhoInterno((string) $request->request->get('voltar', ''));

        return $destino !== null ? $this->redirect($destino) : $this->redirectToRoute('push_processual_index');
    }

    /** @return array{0: ?Tenant, 1: ?User} */
    private function contexto(): array
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($usuario instanceof User && $tenant !== null && $this->permissionChecker->canAccessModule($usuario, $tenant, 'djen')) {
            return [$tenant, $usuario];
        }

        return [null, null];
    }
}
