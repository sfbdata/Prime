<?php

declare(strict_types=1);

namespace App\Djen\Controller;

use App\Djen\DTO\RegistrarPublicacaoDoDiarioInput;
use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Djen\Exception\PublicacaoDoDiarioInvalidaException;
use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Service\PrazoDaPublicacaoCalculador;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\UseCase\ApagarPublicacoesDoDiarioUseCase;
use App\Djen\UseCase\AtualizarLeituraDoDiarioUseCase;
use App\Djen\UseCase\CriarMetaDaPublicacaoDoDiarioUseCase;
use App\Djen\UseCase\RegistrarPublicacaoDoDiarioUseCase;
use App\Djen\UseCase\VincularPublicacaoDoDiarioAPastaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aba "Diário da OAB" do Push Processual (desenho 11): publicações do DEOAB registradas à mão, só de quem
 * registrou. Spec `docs/specs/push-diario-oab-manual.md`.
 */
#[Route('/push-processual/diario')]
final class DiarioDaOabController extends AbstractController
{
    private const MODULO = 'djen';
    private const FILTROS = ['todas' => null, 'eticas' => TipoDeAtoDoDiario::EticoDisciplinar, 'comunicados' => TipoDeAtoDoDiario::Comunicado];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PublicacaoDoDiarioDaOabRepository $diario,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly ProcessoRepository $processoRepository,
        private readonly OabMonitoradaRepository $oabRepository,
        private readonly RegistrarPublicacaoDoDiarioUseCase $registrarPublicacao,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly AtualizarLeituraDoDiarioUseCase $atualizarLeitura,
        private readonly ApagarPublicacoesDoDiarioUseCase $apagarPublicacoes,
        private readonly VincularPublicacaoDoDiarioAPastaUseCase $vincularPasta,
        private readonly CriarMetaDaPublicacaoDoDiarioUseCase $criarMeta,
        private readonly PrazoDaPublicacaoCalculador $prazoCalculador,
    ) {
    }

    #[Route('', name: 'push_processual_diario', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return $this->negarAcesso();
        }

        $filtro = (string) $request->query->get('filtro', 'todas');
        $filtro = \array_key_exists($filtro, self::FILTROS) ? $filtro : 'todas';
        $busca = trim((string) $request->query->get('busca', ''));

        $publicacoes = $this->diario->listarDoUsuario($tenant, $usuario, self::FILTROS[$filtro], $busca);

        return $this->render('djen/diario.html.twig', [
            'abas' => $this->contagensDasAbas($tenant, $usuario),
            'publicacoes' => $publicacoes,
            'prazos' => $this->prazosDasPublicacoes($tenant, $publicacoes),
            'filtro' => $filtro,
            'busca' => $busca,
            'hoje' => new \DateTimeImmutable('today'),
            'totalLidas' => $this->diario->contarLidasDoUsuario($tenant, $usuario),
        ]);
    }

    #[Route('/registrar', name: 'push_processual_diario_registrar', methods: ['POST'])]
    public function registrar(Request $request): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return $this->negarAcesso();
        }
        if (!$this->isCsrfTokenValid('push_diario', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('push_processual_diario');
        }

        try {
            $this->registrarPublicacao->executar($tenant, $usuario, RegistrarPublicacaoDoDiarioInput::fromRequest($request), new \DateTimeImmutable('today'));
            $this->addFlash('success', 'Publicação registrada no Diário da OAB.');
        } catch (PublicacaoDoDiarioInvalidaException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('push_processual_diario');
    }

    #[Route('/leitura', name: 'push_processual_diario_leitura', methods: ['POST'])]
    public function leitura(Request $request): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return $this->negarAcesso();
        }
        if (!$this->isCsrfTokenValid('push_diario', (string) $request->request->get('_token'))) {
            return $this->recusarToken($request);
        }

        $lida = (string) $request->request->get('lida', '1') === '1';
        $n = $this->atualizarLeitura->executar($tenant, $usuario, $this->idsDoLote($request), $lida, new \DateTimeImmutable());
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['atualizadas' => $n, 'naoLidas' => $this->diario->contarNaoLidasDoUsuario($tenant, $usuario)]);
        }
        $this->addFlash('success', sprintf($lida ? '%d publicação(ões) marcada(s) como lida(s).' : '%d publicação(ões) marcada(s) como não lida(s).', $n));

        return $this->voltar($request);
    }

    #[Route('/excluir', name: 'push_processual_diario_excluir', methods: ['POST'])]
    public function excluir(Request $request): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return $this->negarAcesso();
        }
        if (!$this->isCsrfTokenValid('push_diario', (string) $request->request->get('_token'))) {
            return $this->recusarToken($request);
        }

        $n = $this->apagarPublicacoes->executar($tenant, $usuario, $this->idsDoLote($request), new \DateTimeImmutable());
        $this->addFlash('success', sprintf('%d publicação(ões) excluída(s).', $n));

        return $this->voltar($request);
    }

    #[Route('/limpar-lidas', name: 'push_processual_diario_limpar_lidas', methods: ['POST'])]
    public function limparLidas(Request $request): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return $this->negarAcesso();
        }
        if (!$this->isCsrfTokenValid('push_diario', (string) $request->request->get('_token'))) {
            return $this->recusarToken($request);
        }

        $n = $this->apagarPublicacoes->limparLidas($tenant, $usuario, new \DateTimeImmutable());
        $this->addFlash('success', $n > 0 ? sprintf('%d publicação(ões) lida(s) excluída(s).', $n) : 'Não há publicações lidas para limpar.');

        return $this->voltar($request);
    }

    #[Route('/{id}/pasta', name: 'push_processual_diario_pasta', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pasta(int $id, Request $request): Response
    {
        return $this->agir($request, function (Tenant $tenant, User $usuario) use ($id, $request): string {
            $pastaId = (int) $request->request->get('pasta_id', 0);
            $this->vincularPasta->executar($tenant, $usuario, $id, $pastaId > 0 ? $pastaId : null);

            return $pastaId > 0 ? 'Publicação vinculada à pasta.' : 'Publicação desvinculada da pasta.';
        });
    }

    #[Route('/{id}/meta', name: 'push_processual_diario_meta', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function meta(int $id, Request $request): Response
    {
        return $this->agir($request, function (Tenant $tenant, User $usuario) use ($id): string {
            $tarefa = $this->criarMeta->executar($tenant, $usuario, $id, new \DateTimeImmutable('today'));

            return sprintf('Meta criada com prazo até %s. Veja em Minhas Metas.', $tarefa->getPrazo()?->format('d/m/Y') ?? '');
        });
    }

    /** @param callable(Tenant, User): string $acao devolve a mensagem de sucesso */
    private function agir(Request $request, callable $acao): Response
    {
        [$tenant, $usuario] = $this->contexto();
        if ($tenant === null || $usuario === null) {
            return $this->negarAcesso();
        }
        if (!$this->isCsrfTokenValid('push_diario', (string) $request->request->get('_token'))) {
            return $this->recusarToken($request);
        }

        try {
            $this->addFlash('success', $acao($tenant, $usuario));
        } catch (VinculoDaPublicacaoInvalidoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->voltar($request);
    }

    /**
     * @param list<\App\Djen\Entity\PublicacaoDoDiarioDaOab> $publicacoes
     *
     * @return array<int, \App\Djen\DTO\PrazoDaPublicacao>
     */
    private function prazosDasPublicacoes(Tenant $tenant, array $publicacoes): array
    {
        $hoje = new \DateTimeImmutable('today');
        $prazos = [];
        foreach ($publicacoes as $p) {
            $prazo = $this->prazoCalculador->calcular(\DateTimeImmutable::createFromInterface($p->getDataPublicacao()), $p->getPrazoDias(), null, false, $tenant->getEstado(), $hoje);
            if ($prazo !== null) {
                $prazos[(int) $p->getId()] = $prazo;
            }
        }

        return $prazos;
    }

    private function recusarToken(Request $request): Response
    {
        $this->addFlash('danger', 'Token de segurança inválido.');

        return $this->voltar($request);
    }

    /** Volta para a lista com os filtros em que o usuário estava (só caminho da própria aba). */
    private function voltar(Request $request): Response
    {
        $destino = (string) $request->request->get('voltar', '');
        $base = $this->generateUrl('push_processual_diario');
        if ($destino === $base || str_starts_with($destino, $base . '?')) {
            return $this->redirect($destino);
        }

        return $this->redirect($base);
    }

    /** @return list<int> */
    private function idsDoLote(Request $request): array
    {
        $ids = [];
        foreach ($request->request->all('ids') as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /** @return array{0: ?Tenant, 1: ?User} */
    private function contexto(): array
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($usuario instanceof User && $tenant !== null && $this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO)) {
            return [$tenant, $usuario];
        }

        return [null, null];
    }

    private function negarAcesso(): Response
    {
        $this->addFlash('warning', 'Você não tem permissão para acessar o módulo Push Processual.');

        return $this->redirectToRoute('homepage');
    }

    /** @return array{publicacoesNovas: int, processos: int, diarioNovas: int, oabs: int} */
    private function contagensDasAbas(Tenant $tenant, User $usuario): array
    {
        return [
            'publicacoesNovas' => $this->publicacaoRepository->contarIndicadoresDoTenant($tenant, new \DateTimeImmutable('today'), $this->acessoAPasta->numerosDeProcessoOcultos($usuario, $tenant))['naoLidas'],
            'processos' => $this->processoRepository->contarAcompanhadosDoTenant($tenant),
            'diarioNovas' => $this->diario->contarNaoLidasDoUsuario($tenant, $usuario),
            'oabs' => \count($this->oabRepository->listarDoTenant($tenant)),
        ];
    }
}
