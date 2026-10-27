<?php

declare(strict_types=1);

namespace App\Djen\Controller;

use App\Djen\DTO\PublicacaoDjenOutput;
use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\FormatadorTeorDjen;
use App\Djen\Service\PrazoDaPublicacaoCalculador;
use App\Djen\UseCase\CriarMetaDoPrazoDaPublicacaoUseCase;
use App\Djen\UseCase\CumprirPrazoDaPublicacaoUseCase;
use App\Djen\UseCase\DefinirPrazoDaPublicacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Detalhe da publicação (modal), prazo, "prazo cumprido" e meta do prazo (desenho 11, aba Publicações). */
#[Route('/push-processual/publicacoes/{id}', requirements: ['id' => '\d+'])]
final class PublicacaoPrazoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PrazoDaPublicacaoCalculador $calculador,
        private readonly FormatadorTeorDjen $formatadorTeor,
        private readonly DefinirPrazoDaPublicacaoUseCase $definirPrazo,
        private readonly CumprirPrazoDaPublicacaoUseCase $cumprirPrazo,
        private readonly CriarMetaDoPrazoDaPublicacaoUseCase $criarMeta,
    ) {
    }

    /** Fragmento do modal. Abrir marca como lida (desenho). */
    #[Route('/detalhe', name: 'push_processual_publicacao_detalhe', methods: ['GET'])]
    public function detalhe(int $id): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        $publicacao = $this->publicacoes->findOneByIdDoTenant($id, $tenant);
        if ($publicacao === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        if (!$publicacao->isLida()) {
            $publicacao->setLida(true);
            $this->publicacoes->salvar($publicacao, true);
        }

        $doTeor = $this->publicacoes->prazosDoPushPorPublicacao($tenant, [$id])[$id] ?? null;
        $prazo = $this->calculador->calcular(
            $publicacao->getDataDisponibilizacao(),
            $publicacao->getPrazoDias(),
            $doTeor['data'] ?? null,
            $publicacao->getPrazoCumpridoEm() !== null || ($doTeor['cumprido'] ?? false),
            $tenant->getEstado(),
            new \DateTimeImmutable('today'),
        );
        $pasta = $this->publicacoes->pastaDaPublicacao($publicacao);

        return $this->render('djen/_detalhe.html.twig', [
            'pub' => PublicacaoDjenOutput::fromEntity($publicacao, $this->formatadorTeor->formatar($publicacao->getTexto())),
            'prazo' => $prazo,
            'prazoDias' => $publicacao->getPrazoDias(),
            'pasta' => $pasta,
            'tarefaId' => $publicacao->getTarefa()?->getId(),
        ]);
    }

    #[Route('/prazo', name: 'push_processual_publicacao_prazo', methods: ['POST'])]
    public function prazo(int $id, Request $request): Response
    {
        return $this->agir($request, $id, function (Tenant $tenant, User $usuario) use ($id, $request): string {
            $dias = (int) $request->request->get('dias', 0);
            $this->definirPrazo->executar($tenant, $id, $dias > 0 ? $dias : null);

            return $dias > 0 ? sprintf('Prazo de %d dias úteis definido.', $dias) : 'Prazo removido.';
        });
    }

    #[Route('/cumprido', name: 'push_processual_publicacao_cumprido', methods: ['POST'])]
    public function cumprido(int $id, Request $request): Response
    {
        return $this->agir($request, $id, function (Tenant $tenant, User $usuario) use ($id, $request): string {
            $cumprido = $request->request->get('cumprido') === '1';
            $this->cumprirPrazo->executar($tenant, $usuario, $id, $cumprido);

            return $cumprido ? 'Prazo marcado como cumprido.' : 'Prazo reaberto.';
        });
    }

    #[Route('/meta', name: 'push_processual_publicacao_meta', methods: ['POST'])]
    public function meta(int $id, Request $request): Response
    {
        return $this->agir($request, $id, function (Tenant $tenant, User $usuario) use ($id): string {
            $doTeor = $this->publicacoes->prazosDoPushPorPublicacao($tenant, [$id])[$id] ?? null;
            $tarefa = $this->criarMeta->executar($tenant, $usuario, $id, $doTeor['data'] ?? null, new \DateTimeImmutable('today'));

            return sprintf('Meta criada com prazo até %s. Veja em Minhas Metas.', $tarefa->getPrazo()?->format('d/m/Y') ?? '');
        });
    }

    /** @param callable(Tenant, User): string $acao devolve a mensagem de sucesso */
    private function agir(Request $request, int $id, callable $acao): Response
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
        if ($destino === null) {
            return $this->redirectToRoute('push_processual_index', ['abrir' => $id]);
        }
        $separador = str_contains($destino, '?') ? '&' : '?';

        return $this->redirect(preg_replace('/([?&])abrir=\d+&?/', '$1', $destino) . $separador . 'abrir=' . $id);
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
