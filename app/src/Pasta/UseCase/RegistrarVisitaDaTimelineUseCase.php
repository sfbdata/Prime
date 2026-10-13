<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaTimelineVisitaRepository;
use App\Service\PermissionChecker;
use Psr\Clock\ClockInterface;

/**
 * Registra que o usuário abriu a Timeline inteligente da pasta (PAS-043, "Enquanto você estava
 * fora"). Por REGRA, sem IA: é só a data da abertura, por usuário e pasta.
 *
 * Quem: qualquer usuário que pode VER a pasta — é preferência de quem olha, não escrita na pasta
 * (o mesmo critério do favorito). O quê: a abertura de agora vira a mais recente, e a de antes
 * vira o marco do "o que é novo". Devolve esse marco (nulo na primeira abertura).
 *
 * Guardas, nesta ordem:
 *   1. a pasta é do escritório da sessão — senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o usuário pode ver a pasta — senão {@see SemPermissaoParaVerPastaException} (403).
 *
 * Pasta excluída (lápide): a rota é POST e recebe a pasta, então o `PastaSomenteLeituraListener`
 * recusa antes de chegar aqui, como faz com o favorito. A timeline continua legível (GET).
 */
final class RegistrarVisitaDaTimelineUseCase
{
    public function __construct(
        private readonly PastaTimelineVisitaRepository $repository,
        private readonly PermissionChecker $permissionChecker,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant): ?\DateTimeImmutable
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }

        return $this->repository->registrarAbertura($pasta, $usuario, $tenant, $this->clock->now());
    }
}
