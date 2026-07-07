<?php

declare(strict_types=1);

namespace App\Djen\Controller;

use App\Djen\DTO\OabMonitoradaInput;
use App\Djen\DTO\OabMonitoradaOutput;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\PublicacaoDjenOutput;
use App\Djen\Exception\OabMonitoradaJaExisteException;
use App\Djen\Form\OabMonitoradaType;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\FormatadorTeorDjen;
use App\Djen\UseCase\AdicionarOabMonitoradaUseCase;
use App\Djen\UseCase\AlternarStatusOabMonitoradaUseCase;
use App\Djen\UseCase\RemoverOabMonitoradaUseCase;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publicações do DJEN captadas para o escritório atual + gestão das OABs monitoradas + sincronização
 * sob demanda. Toda action é gateada por `canAccessModule('djen')` e faz busca tenant-safe (o `find` por
 * PK não passa pelo TenantFilter): cross-tenant resulta em 404.
 */
#[Route('/djen')]
final class DjenController extends AbstractController
{
    private const MODULO = 'djen';
    private const POR_PAGINA = 25;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly OabMonitoradaRepository $oabRepository,
        private readonly AdicionarOabMonitoradaUseCase $adicionarOab,
        private readonly RemoverOabMonitoradaUseCase $removerOab,
        private readonly AlternarStatusOabMonitoradaUseCase $alternarOab,
        private readonly SincronizarPublicacoesDjenUseCase $sincronizar,
    ) {
    }

    #[Route('', name: 'djen_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        $filtros = [
            'busca' => trim((string) $request->query->get('busca', '')),
            'tribunal' => (string) $request->query->get('tribunal', ''),
            'meio' => (string) $request->query->get('meio', ''),
            'vinculo' => (string) $request->query->get('vinculo', ''),
            'data_de' => (string) $request->query->get('data_de', ''),
            'data_ate' => (string) $request->query->get('data_ate', ''),
        ];
        $pagina = max(1, (int) $request->query->get('pagina', 1));

        $total = $this->publicacaoRepository->countByFiltros($tenant, $filtros);

        return $this->render('djen/index.html.twig', [
            'publicacoes' => $this->publicacaoRepository->listarItensDoTenant($tenant, $filtros, $pagina, self::POR_PAGINA),
            'filtros' => $filtros,
            'tribunais' => $this->publicacaoRepository->listarTribunaisDoTenant($tenant),
            'pagina' => $pagina,
            'total' => $total,
            'totalPaginas' => (int) ceil($total / self::POR_PAGINA),
        ]);
    }

    #[Route('/oabs', name: 'djen_oabs', methods: ['GET', 'POST'])]
    public function oabs(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        $input = new OabMonitoradaInput();
        $form = $this->createForm(OabMonitoradaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();

            try {
                $this->adicionarOab->executar($input, $tenant, $user);
                $this->addFlash('success', 'OAB adicionada ao monitoramento.');

                return $this->redirectToRoute('djen_oabs');
            } catch (OabMonitoradaJaExisteException $e) {
                $this->addFlash('warning', $e->getMessage());
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('djen/oabs.html.twig', [
            'form' => $form,
            'oabs' => array_map(
                static fn ($oab): OabMonitoradaOutput => OabMonitoradaOutput::fromEntity($oab),
                $this->oabRepository->listarDoTenant($tenant),
            ),
        ]);
    }

    #[Route('/oabs/{id}/alternar', name: 'djen_oab_alternar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function alternarOabAction(int $id, Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        // Busca tenant-safe ANTES do CSRF (leitura sem efeito): cross-tenant vira 404 de forma
        // determinística (o guard IDOR é exercido mesmo sem token válido); a mutação fica após o CSRF.
        $oab = $this->oabRepository->findOneByIdDoTenant($id, $tenant);
        if ($oab === null) {
            throw $this->createNotFoundException('OAB monitorada não encontrada.');
        }

        if (!$this->isCsrfTokenValid('djen_oab_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('djen_oabs');
        }

        $ativo = $this->alternarOab->executar($oab);
        $this->addFlash('success', $ativo ? 'Monitoramento reativado.' : 'Monitoramento pausado.');

        return $this->redirectToRoute('djen_oabs');
    }

    #[Route('/oabs/{id}/remover', name: 'djen_oab_remover', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removerOabAction(int $id, Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        // Busca tenant-safe ANTES do CSRF (leitura sem efeito): cross-tenant vira 404 de forma
        // determinística (o guard IDOR é exercido mesmo sem token válido); a remoção fica após o CSRF.
        $oab = $this->oabRepository->findOneByIdDoTenant($id, $tenant);
        if ($oab === null) {
            throw $this->createNotFoundException('OAB monitorada não encontrada.');
        }

        if (!$this->isCsrfTokenValid('djen_oab_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('djen_oabs');
        }

        $this->removerOab->executar($oab);
        $this->addFlash('success', 'OAB removida do monitoramento.');

        return $this->redirectToRoute('djen_oabs');
    }

    #[Route('/sincronizar', name: 'djen_sincronizar', methods: ['POST'])]
    public function sincronizarAction(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        if (!$this->isCsrfTokenValid('djen_sincronizar', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('djen_index');
        }

        $periodo = PeriodoConsulta::ultimosDias(1, new \DateTimeImmutable('today'));

        try {
            $resultado = $this->sincronizar->executar($tenant, $periodo);

            if ($resultado->temFalhas()) {
                $this->addFlash('warning', 'Sincronização parcial (alguns tribunais não responderam). ' . $resultado->resumo());
            } else {
                $this->addFlash('success', $resultado->resumo());
            }
        } catch (\Throwable) {
            $this->addFlash('danger', 'Não foi possível sincronizar com o DJEN agora. Tente novamente em instantes.');
        }

        return $this->redirectToRoute('djen_index');
    }

    #[Route('/{id}', name: 'djen_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, FormatadorTeorDjen $formatadorTeor): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        $publicacao = $this->publicacaoRepository->findOneByIdDoTenant($id, $tenant);
        if ($publicacao === null) {
            throw $this->createNotFoundException('Publicação não encontrada.');
        }

        if (!$publicacao->isLida()) {
            $publicacao->setLida(true);
            $this->publicacaoRepository->salvar($publicacao, true);
        }

        // Teor externo (CNJ): formata/sanitiza mantendo a leitura segura antes de exibir.
        return $this->render('djen/show.html.twig', [
            'publicacao' => PublicacaoDjenOutput::fromEntity($publicacao, $formatadorTeor->formatar($publicacao->getTexto())),
        ]);
    }

    /**
     * Retorna o tenant atual se o usuário tem acesso ao módulo DJEN; null caso contrário.
     */
    private function tenantComAcesso(): ?Tenant
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($user instanceof User && $tenant !== null && $this->permissionChecker->canAccessModule($user, $tenant, self::MODULO)) {
            return $tenant;
        }

        return null;
    }

    private function negarAcesso(): Response
    {
        $this->addFlash('warning', 'Você não tem permissão para acessar o módulo DJEN.');

        return $this->redirectToRoute('homepage');
    }
}
