<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\AuditLogRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auditoria')]
class AuditLogController extends AbstractController
{
    private const PER_PAGE = 50;

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

        if (!$permissionChecker->canAdminister($currentUser, 'admin.audit.view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar a trilha de auditoria.');
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);

        $entityFilter = trim((string) $request->query->get('entity', ''));
        $userFilter = trim((string) $request->query->get('user', ''));
        $dateFromInput = trim((string) $request->query->get('date_from', ''));
        $dateToInput = trim((string) $request->query->get('date_to', ''));

        $dateFrom = $this->parseDate($dateFromInput);
        $dateTo = $this->parseDate($dateToInput);
        $page = max(1, $request->query->getInt('page', 1));

        $tenantId = null;
        if (!$isSuperAdmin && method_exists($currentUser, 'getTenant') && $currentUser->getTenant() && method_exists($currentUser->getTenant(), 'getId')) {
            $tenantId = $currentUser->getTenant()->getId();
        }

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

        return $this->render('audit/index.html.twig', [
            'auditLogs' => $auditLogs,
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

            if (!str_starts_with($className, 'App\\Entity\\')) {
                continue;
            }

            if ($className === 'App\\Entity\\Audit\\AuditLog') {
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