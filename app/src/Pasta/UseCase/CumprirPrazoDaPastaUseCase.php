<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Marcar como cumprido" no ⋮ de um prazo manual (desenho 1.2.3, `pzCumprir`): sai de "Próximos prazos" e o
 * Registro ganha "Prazo cumprido: …". Com `$excluir`, é o "Excluir" do mesmo ⋮ (`pzExcluir`): apaga e registra
 * "Prazo excluído: …". Os dois só existem para prazos registrados à mão.
 */
final class CumprirPrazoDaPastaUseCase
{
    public function __construct(
        private readonly PastaPrazoRepository $repository,
        private readonly PermissionChecker $permissionChecker,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, int $prazoId, User $usuario, Tenant $tenant, bool $excluir = false): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
        $prazo = $this->repository->findDaPasta($prazoId, $pasta, $tenant);
        if ($prazo === null) {
            throw new \DomainException('Prazo não encontrado.');
        }
        // O calculado do Push se cumpre (a sincronização preserva o cumprido), mas não se exclui: some quando a
        // publicação deixa de gerar o prazo. O lido de um registro não se cumpre nem se exclui.
        if (!$prazo->isManual() && ($excluir || !$prazo->isPush())) {
            throw new \DomainException('Prazo lido de um registro muda quando o registro muda.');
        }

        if ($excluir) {
            $this->registro->registrar($pasta, $usuario, 'prazo', 'Prazo excluído: ' . $prazo->getTitulo());
            $this->em->remove($prazo);
        } else {
            $prazo->cumprir($usuario);
            $this->registro->registrar($pasta, $usuario, 'prazo', 'Prazo cumprido: ' . $prazo->getTitulo());
        }
        $this->em->flush();
    }
}
