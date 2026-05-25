<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ExcluirPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
    ) {}

    public function executar(Pasta $pasta, User $autor, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        foreach ($pasta->getDocumentos() as $doc) {
            $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());
            if ($this->storage->existe($caminho)) {
                $this->storage->excluir($caminho);
            }
        }

        $this->em->remove($pasta);
        $this->em->flush();
    }
}
