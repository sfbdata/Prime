<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Service\PermissionChecker;

/**
 * Liga ou desliga o "Acompanhar alterações" da pasta para o usuário (menu ⋮, desenho "02 -
 * EXPEDIENTES - VS 18.10.26", `prefPasta('seguir')`).
 *
 * Quem: qualquer usuário que pode VER a pasta. O quê: decidir se recebe na Central (Chat I.A) o
 * aviso das alterações da pasta. O padrão é LIGADO (desenho: `seguir` começa verdadeiro); a
 * tabela guarda só quem desligou. É alternância: acompanhando, deixa de acompanhar (grava a
 * exceção); desligado, volta a acompanhar (apaga a exceção). Devolve o estado final.
 *
 * Guardas, na ordem do {@see AlternarFavoritoDaPastaUseCase}:
 *   1. a pasta é do escritório da sessão, senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o usuário pode ver a pasta, senão {@see SemPermissaoParaVerPastaException} (403).
 *
 * Acompanhar não muda a pasta: é preferência de quem olha. Por isso basta VER e, como o
 * favorito, não é auditado.
 */
final class AlternarAcompanhamentoDaPastaUseCase
{
    public function __construct(
        private readonly PastaAcompanhamentoDesligadoRepository $repository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant): bool
    {
        $this->exigirPastaVisivel($pasta, $usuario, $tenant);

        if ($this->repository->estaDesligado($pasta, $usuario, $tenant)) {
            $this->repository->religar($tenant, $usuario, $pasta);

            return true;
        }

        // Idempotente: clique duplo não duplica a linha nem estoura a UNIQUE
        // (ver PastaAcompanhamentoDesligadoRepository::desligar).
        $this->repository->desligar($tenant, $usuario, $pasta);

        return false;
    }

    private function exigirPastaVisivel(Pasta $pasta, User $usuario, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }
    }
}
