<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Trancar pasta" / "Destrancar pasta" do botão direito da Lista, desenho 1.1 (E12).
 *
 * História: a pasta recebeu documentos sigilosos; o administrador do escritório (o "usuário primário" do
 * desenho) tranca. Daí em diante a linha mostra o cadeado com quem trancou e quando, e quem não é
 * administrador, ao abrir pela Lista, vê o aviso de sigilo. Só o administrador tranca e destranca; os
 * demais recebem recusa. É alternância e devolve o estado final.
 */
final class AlternarTrancaDaPastaUseCase
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->isAdministradorDoEscritorio($usuario, $tenant)) {
            throw new AccessDeniedException('Somente o usuário primário pode trancar ou destrancar pastas');
        }

        if ($pasta->estaTrancada()) {
            $pasta->destrancar();
        } else {
            $pasta->trancar($usuario, new \DateTimeImmutable());
        }

        $this->em->flush();

        return $pasta->estaTrancada();
    }
}
