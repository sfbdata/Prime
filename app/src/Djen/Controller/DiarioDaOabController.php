<?php

declare(strict_types=1);

namespace App\Djen\Controller;

use App\Djen\DTO\RegistrarPublicacaoDoDiarioInput;
use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Djen\Exception\PublicacaoDoDiarioInvalidaException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\UseCase\RegistrarPublicacaoDoDiarioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        return $this->render('djen/diario.html.twig', [
            'abas' => $this->contagensDasAbas($tenant, $usuario),
            'publicacoes' => $this->diario->listarDoUsuario($tenant, $usuario, self::FILTROS[$filtro], $busca),
            'filtro' => $filtro,
            'busca' => $busca,
            'hoje' => new \DateTimeImmutable('today'),
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
