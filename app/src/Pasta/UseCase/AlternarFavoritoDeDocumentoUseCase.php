<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDocumentoFavoritoRepository;
use App\Service\PermissionChecker;

/**
 * Liga ou desliga a estrela de um arquivo ou de uma subpasta no explorador da aba Documentos
 * (DOC-23, desenho 1.2.3 — "favoritos sobem ao topo").
 *
 * Quem: qualquer usuário que pode VER a pasta. O quê: marcar o item para que ele suba ao topo da
 * SUA lista. Não é alternância cega: o pedido diz o estado desejado (`$marcado`) e o UseCase o
 * aplica de forma idempotente — marcar duas vezes deixa marcado, desmarcar o que não está marcado
 * não faz nada. Assim um clique duplo ou duas abas nunca invertem o estado que a tela mostra.
 * Devolve o estado final.
 *
 * Guardas, nesta ordem:
 *   1. a pasta é do escritório da sessão — senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o alvo é DESTA pasta e deste escritório — senão a mesma exceção (404: não se confirma que
 *      um item de pasta irmã ou de outro escritório existe). O controller já prova a posse pela
 *      consulta escopada; esta guarda é a segunda barreira para quem chamar o UseCase direto;
 *   3. o usuário pode ver a pasta — senão {@see SemPermissaoParaVerPastaException} (403).
 *
 * Favoritar não muda a pasta: é preferência de quem olha. Por isso basta VER, como em
 * {@see AlternarFavoritoDaPastaUseCase}; e, pelo mesmo precedente, não é auditado.
 */
final class AlternarFavoritoDeDocumentoUseCase
{
    public function __construct(
        private readonly PastaDocumentoFavoritoRepository $repository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function executar(Pasta $pasta, PastaDocumento|PastaSecao $alvo, bool $marcado, User $usuario, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if ($alvo->getPasta() !== $pasta || $alvo->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Item não encontrado.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }

        if ($marcado) {
            $this->repository->marcarSeAusente($tenant, $usuario, $alvo);

            return true;
        }

        $this->repository->desmarcar($tenant, $usuario, $alvo);

        return false;
    }
}
