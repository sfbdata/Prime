<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AuditoriaDoCadeado;
use App\Shared\Contract\Auditavel;
use App\Repository\UserRepository;
use App\Repository\AuditLogRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/auditoria')]
final class AuditLogController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DesfazerAlteracaoAuditLogUseCase $desfazerUseCase,
        private readonly AuditoriaDoCadeado $auditoriaDoCadeado,
    ) {}

    #[Route('', name: 'audit_log_index', methods: ['GET'])]
    public function index(
        Request $request,
        AuditLogRepository $auditLogRepository,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        PermissionChecker $permissionChecker
    ): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$permissionChecker->canAdminister($currentUser, $tenant, 'admin.audit.view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar a trilha de auditoria.');
        }

        $entityFilter = trim((string) $request->query->get('entity', ''));
        $userFilter = trim((string) $request->query->get('user', ''));
        $dateFromInput = trim((string) $request->query->get('date_from', ''));
        $dateToInput = trim((string) $request->query->get('date_to', ''));

        $dateFrom = $this->parseDate($dateFromInput);
        $dateTo = $this->parseDate($dateToInput);
        $page = max(1, $request->query->getInt('page', 1));

        $tenantId = $tenant?->getId();

        $entityOptions = $this->getEntityOptions($entityManager);
        $userOptions = $userRepository->findAuditFilterOptions(is_int($tenantId) ? $tenantId : null);

        $totalItems = $auditLogRepository->countByFilters(
            $entityFilter !== '' ? $entityFilter : null,
            $userFilter !== '' ? $userFilter : null,
            $dateFrom,
            $dateTo,
            is_int($tenantId) ? $tenantId : null
        );

        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page = min($page, $totalPages);

        $auditLogs = $auditLogRepository->findByFilters(
            $entityFilter !== '' ? $entityFilter : null,
            $userFilter !== '' ? $userFilter : null,
            $dateFrom,
            $dateTo,
            is_int($tenantId) ? $tenantId : null,
            $page,
            self::PER_PAGE
        );

        // Cadeado (spec pasta-cadeado D4): linha de pasta oculta para quem lê sai sem conteúdo e sem desfazer.
        $ocultasPeloCadeado = $tenant !== null ? $this->auditoriaDoCadeado->linhasOcultas($currentUser, $tenant, $auditLogs) : [];
        $podeDesfazerMap = [];
        foreach ($auditLogs as $auditLog) {
            $podeDesfazerMap[(int) $auditLog->getId()] = !isset($ocultasPeloCadeado[(int) $auditLog->getId()]) && $this->podeDesfazer($auditLog, $currentUser, $tenant, $permissionChecker);
        }

        return $this->render('audit/index.html.twig', [
            'auditLogs' => $auditLogs,
            'podeDesfazerMap' => $podeDesfazerMap,
            'ocultasPeloCadeado' => $ocultasPeloCadeado,
            'filters' => [
                'entity' => $entityFilter,
                'user' => $userFilter,
                'date_from' => $dateFromInput,
                'date_to' => $dateToInput,
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
            ],
            'entityOptions' => $entityOptions,
            'userOptions' => $userOptions,
        ]);
    }

    #[Route('/{id}/desfazer', name: 'audit_log_desfazer', methods: ['POST'])]
    public function desfazer(
        int $id,
        Request $request,
        PermissionChecker $permissionChecker,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$permissionChecker->canAdminister($currentUser, $tenant, 'admin.audit.view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para desfazer alterações.');
        }

        $tokenValue = (string) $request->request->get('_token', '');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('audit_desfazer_' . $id, $tokenValue))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        if ($tenant !== null && $this->auditoriaDoCadeado->linhaOcultaPorId($currentUser, $tenant, $id)) {
            throw $this->createAccessDeniedException('O usuário primário restringiu o acesso a esta pasta.');
        }

        $this->exigirPermissaoDeEdicao($id, $currentUser, $tenant, $permissionChecker);
        $tenantId = $tenant?->getId();
        $resultado = $this->desfazerUseCase->executar($id, is_int($tenantId) ? $tenantId : 0);

        if (!$resultado->sucesso) {
            $this->addFlash('error', (string) $resultado->erro);
        } else {
            $this->addFlash('success', 'Alteração desfeita. Novo registro de auditoria criado.');

            if ($resultado->truncado) {
                $this->addFlash('warning', 'Um ou mais campos de texto longo podem ter sido restaurados parcialmente (limite de 500 caracteres no log).');
            }
        }

        return $this->redirectToRoute('audit_log_index');
    }

    /** O botão "desfazer" só aparece para quem o servidor deixaria desfazer (achado de auditoria interna F19). */
    private function podeDesfazer(AuditLog $log, User $user, ?Tenant $tenant, PermissionChecker $permissionChecker): bool
    {
        if (!$this->desfazerUseCase->podeReverter($log)) {
            return false;
        }
        $permissao = $this->desfazerUseCase->permissaoDeEdicao($log);

        return $permissao === null || $permissionChecker->canAdminister($user, $tenant, $permissao);
    }

    /**
     * Desfazer é editar (achado de auditoria interna F19): a linha de Sede, Cargo ou Lotação exige também a permissão
     * da tela que edita a entidade. Sem ela, 403 e nada muda.
     */
    private function exigirPermissaoDeEdicao(int $id, User $user, ?Tenant $tenant, PermissionChecker $permissionChecker): void
    {
        $tenantId = $tenant?->getId();
        $permissao = $this->desfazerUseCase->permissaoDeEdicaoPorId($id, is_int($tenantId) ? $tenantId : 0);

        if ($permissao !== null && !$permissionChecker->canAdminister($user, $tenant, $permissao)) {
            throw $this->createAccessDeniedException('Você não tem permissão para desfazer alterações.');
        }
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function getEntityOptions(EntityManagerInterface $entityManager): array
    {
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $classes = [];

        foreach ($metadata as $classMetadata) {
            $className = $classMetadata->getName();

            if (!is_a($className, Auditavel::class, true)) {
                continue;
            }

            $classes[] = $className;
        }

        sort($classes);

        return array_map(fn (string $className): array => [
            'value' => $className,
            'label' => $this->getEntityShortName($className),
        ], $classes);
    }

    private function getEntityShortName(string $className): string
    {
        $parts = explode('\\', $className);

        return (string) end($parts);
    }
}