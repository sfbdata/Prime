<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Desfaz a lápide: a pasta riscada volta a ser uma pasta comum, ativa e editável.
 *
 * Não há nada a devolver do disco nem do Drive — a exclusão-lápide não apagou nada. Quem restaura
 * é quem pode excluir (decisão do dono), e o rastro da exclusão continua no histórico e na
 * auditoria: o `AuditLogSubscriber` grava esta limpeza como uma alteração, então a pasta guarda as
 * duas pontas — quando foi excluída e quando voltou.
 */
final class RestaurarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        // O guard real é da entidade (restaurar() recusa pasta não excluída); aqui a checagem
        // existe para o controller poder responder "não há o que restaurar" sem estourar 500.
        if (!$pasta->estaExcluida()) {
            throw new \InvalidArgumentException('Esta pasta não está excluída.');
        }

        $pasta->restaurar();
        $this->em->flush();
    }
}
