<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marca ou desmarca a pasta como "Administrativo sem processo" (interruptor no cabeçalho da aba
 * Processo, desenho 1.2.3).
 *
 * Quem: quem pode EDITAR a pasta. O quê: declarar que a pasta é de atuação administrativa
 * (consultoria, extrajudicial, contrato) e não terá processo judicial. Por quê: separar a pasta
 * que "ainda não tem processo vinculado" (pendência) da que nunca vai ter (não é pendência).
 *
 * Recebe o ESTADO FINAL, não "alterna": dois cliques (ou duas abas abertas) não se desfazem um
 * ao outro — cada POST diz o que o usuário viu no interruptor ao clicar. Estado igual ao atual
 * não grava nada (nem flush, nem linha de auditoria). Pasta com processo vinculado PODE ser
 * marcada: o desenho pede só uma confirmação na tela, não proíbe.
 *
 * Guardas, nesta ordem:
 *   1. a pasta é do escritório da sessão — senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o usuário pode editar a pasta — senão {@see SemPermissaoParaEditarPastaException} (403).
 *
 * Auditoria: `Pasta` implementa `Auditavel`; a troca do campo entra no `audit_log` pelo
 * subscriber, como qualquer outra edição da pasta.
 *
 * @return bool true quando o valor mudou
 */
final class DefinirPastaAdministrativaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function executar(Pasta $pasta, bool $administrativa, User $usuario, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        if ($pasta->isAdministrativa() === $administrativa) {
            return false;
        }

        $pasta->setAdministrativa($administrativa);
        $this->em->flush();

        return true;
    }
}
