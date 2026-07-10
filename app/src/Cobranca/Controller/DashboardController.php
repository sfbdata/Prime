<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\UseCase\MontarCentralAlertasUseCase;
use App\Cobranca\UseCase\MontarDashboardCobrancaUseCase;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP do Dashboard e da Central de Alertas do escritório (Etapa 9). Duas rotas de LEITURA (GET),
 * visão do TENANT: painel consolidado (financeiro/operacional/resultado) e central de alertas agregada por
 * carteira. Controller FINO: gate de módulo `cobrancas`, filtros simples (carteira/período) resolvidos
 * tenant-safe (anti-IDOR → 404), delega aos UseCases de agregação. Nenhuma regra de negócio aqui; sem
 * escrita → sem CSRF. Tenant nunca vem do request.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly MontarDashboardCobrancaUseCase $montarDashboard,
        private readonly MontarCentralAlertasUseCase $montarCentralAlertas,
    ) {
    }

    #[Route('/painel', name: 'cobranca_dashboard', methods: ['GET'])]
    public function painel(Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->resolverCarteira($request, $tenant);
        [$inicio, $fim] = $this->resolverPeriodo($request);

        $dashboard = $this->montarDashboard->executar($tenant, $carteira, $inicio, $fim);

        return $this->render('cobranca/dashboard/index.html.twig', [
            'dashboard' => $dashboard,
            'carteiras' => $this->carteiraRepository->opcoesFacetaDoTenant($tenant),
            'carteiraId' => $carteira?->getId(),
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
        ]);
    }

    #[Route('/alertas', name: 'cobranca_alertas', methods: ['GET'])]
    public function alertas(Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->resolverCarteira($request, $tenant);

        $central = $this->montarCentralAlertas->executar($tenant, $carteira);

        return $this->render('cobranca/alertas/index.html.twig', [
            'central' => $central,
            'carteiras' => $this->carteiraRepository->opcoesFacetaDoTenant($tenant),
            'carteiraId' => $carteira?->getId(),
        ]);
    }

    /**
     * Filtro opcional por carteira: só resolve se o id vier no request. Sempre tenant-safe
     * (`findOneByIdDoTenant`) — id de outro escritório ou inexistente vira 404 (anti-IDOR).
     */
    private function resolverCarteira(Request $request, Tenant $tenant): ?Carteira
    {
        $id = trim((string) $request->query->get('carteira', ''));
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant((int) $id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        return $carteira;
    }

    /**
     * Período do dashboard (default: mês corrente). Parse defensivo de `inicio`/`fim` (Y-m-d); datas
     * inválidas caem no default. `fim` normalizado para o fim do dia (inclusivo).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolverPeriodo(Request $request): array
    {
        $hoje = new \DateTimeImmutable('today');
        $inicio = $this->parseData((string) $request->query->get('inicio', '')) ?? $hoje->modify('first day of this month');
        $fim = $this->parseData((string) $request->query->get('fim', '')) ?? $hoje;

        // Período coerente: se o início vier depois do fim, usa o mês corrente.
        if ($inicio > $fim) {
            $inicio = $hoje->modify('first day of this month');
            $fim = $hoje;
        }

        return [$inicio->setTime(0, 0, 0), $fim->setTime(23, 59, 59)];
    }

    private function parseData(string $valor): ?\DateTimeImmutable
    {
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $data === false ? null : $data;
    }
}
