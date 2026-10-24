<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CarteiraDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Mover para outra carteira" do menu ⋮ do cabeçalho (desenho 02 - EXPEDIENTES VS 18.10.26, `menuPasta('mover')`).
 *
 * História: a pasta entrou como cível, mas o caso é trabalhista; quem pode editar a pasta abre o ⋮, escolhe
 * "Trabalhista" e o Registro ganha "Carteira: movida de "Sem carteira" para "Trabalhista".". Escolher a carteira em
 * que a pasta já está não grava nada (no desenho, o clique na atual não faz nada). Pasta de outro escritório não é
 * encontrada; sem permissão de EDITAR a pasta, recusa; pasta na lixeira não muda de carteira.
 */
final class MoverPastaDeCarteiraUseCase
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Devolve se a pasta mudou de carteira. */
    public function executar(Pasta $pasta, User $usuario, Tenant $tenant, CarteiraDaPasta $carteira): bool
    {
        $this->exigirPastaEditavel($pasta, $usuario, $tenant);

        $de = CarteiraDaPasta::rotuloDe($pasta->getCarteira());
        if (!$pasta->moverParaCarteira($carteira)) {
            return false;
        }

        $this->registro->registrar($pasta, $usuario, 'carteira', 'movida de "' . $de . '" para "' . $carteira->rotulo() . '".');
        $this->em->flush();

        return true;
    }

    private function exigirPastaEditavel(Pasta $pasta, User $usuario, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
        if ($pasta->estaExcluida()) {
            throw new \DomainException('Pasta excluída não muda de carteira.');
        }
    }
}
