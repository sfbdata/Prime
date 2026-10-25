<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\FotoDaPastaRecusadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;

/**
 * As guardas comuns de trocar e remover a foto da pasta pelo cabeçalho, na ordem em que recusam (o molde é o
 * `ClienteDaPastaParaFoto` da foto do cliente):
 *
 *  1. a pasta é do escritório da sessão (senão 404, sem revelar que existe);
 *  2. quem pede VÊ a pasta;
 *  3. quem pede pode EDITAR a pasta (`pasta` + `edit`; o cadeado em Visualizar já recusa aqui);
 *  4. a pasta não está excluída (lápide é somente leitura).
 */
final class PastaParaFoto
{
    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function carregar(int $pastaId, User $usuario, Tenant $tenant): Pasta
    {
        $pasta = $this->pastas->find($pastaId);
        if (!$pasta instanceof Pasta || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem acesso a esta pasta.');
        }
        if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
        if ($pasta->estaExcluida()) {
            throw new FotoDaPastaRecusadaException('Esta pasta foi excluída e está somente para leitura. Restaure a pasta para voltar a editá-la.');
        }

        return $pasta;
    }
}
