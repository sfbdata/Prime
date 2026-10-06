<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaFavoritaRepository;
use App\Service\PermissionChecker;

/**
 * Fixa ou tira a pasta dos favoritos do usuário (menu ⋮ → "Fixar nos favoritos").
 *
 * Quem: qualquer usuário que pode VER a pasta. O quê: marcar a pasta para que ela suba ao topo
 * da SUA listagem do Expediente. É alternância: se já é favorita, deixa de ser; se não é, passa
 * a ser. Devolve o estado final.
 *
 * Guardas, nesta ordem:
 *   1. a pasta é do escritório da sessão — senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o usuário pode ver a pasta — senão {@see SemPermissaoParaVerPastaException} (403).
 *
 * Favoritar não muda a pasta: não é escrita nela, é preferência de quem olha. Por isso basta
 * VER (exigir edição faria quem só lê não poder organizar a própria lista).
 */
final class AlternarFavoritoDaPastaUseCase
{
    public function __construct(
        private readonly PastaFavoritaRepository $repository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }

        $existente = $this->repository->buscarDoUsuario($pasta, $usuario, $tenant);

        if ($existente !== null) {
            $this->repository->remover($existente, flush: true);

            return false;
        }

        // Idempotente: se outra requisição (clique duplo) inseriu entre a consulta acima e aqui,
        // o banco ignora a segunda linha e o resultado continua sendo "favorita" — sem 500 e sem
        // EntityManager fechado (ver PastaFavoritaRepository::inserirSeAusente).
        $this->repository->inserirSeAusente($tenant, $usuario, $pasta);

        return true;
    }
}
