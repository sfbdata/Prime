<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\AlertaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\AlertasDaPasta;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Alertas da Controladoria da pasta (desenho 02 de 31/10/2026): leitura, carregada pelo botão ao abrir a pasta. */
#[Route('/pasta')]
final class PastaControladoriaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AcessoAPasta $acesso,
        private readonly AlertasDaPasta $alertas,
    ) {
    }

    #[Route('/{id}/controladoria', name: 'pasta_controladoria', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function alertas(Pasta $pasta): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant || !$this->acesso->podeVer($user, $pasta)) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $lista = $this->alertas->montar($pasta, $tenant, new \DateTimeImmutable());

        return $this->json([
            'alertas' => array_map(static fn (AlertaDaPasta $a): array => $a->paraArray(), $lista),
            'urgentes' => count(array_filter($lista, static fn (AlertaDaPasta $a): bool => $a->nivel !== AlertaDaPasta::BAIXA)),
        ]);
    }
}
