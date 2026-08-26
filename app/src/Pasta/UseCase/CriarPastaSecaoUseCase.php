<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CriarPastaSecaoUseCase
{
    /** Teto de profundidade da árvore. Seção sem pai está no nível 1; o nível 11 é recusado. */
    public const PROFUNDIDADE_MAXIMA = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaSecaoRepository $secaoRepository,
    ) {
    }

    public function executar(
        Pasta $pasta,
        User $autor,
        string $nome,
        Tenant $tenant,
        ?PastaSecao $pai = null,
    ): PastaSecao {
        $nome = trim($nome);

        if ($nome === '') {
            throw new \InvalidArgumentException('O nome da seção não pode ser vazio.');
        }

        if (mb_strlen($nome) > 255) {
            throw new \InvalidArgumentException('O nome da seção deve ter no máximo 255 caracteres.');
        }

        if ($pai !== null) {
            if ($pai->getTenant() !== $tenant) {
                throw new AccessDeniedException('Pasta de destino não pertence ao tenant do usuário.');
            }

            if ($pai->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('A pasta de destino não pertence à mesma pasta.');
            }

            if ($pai->getProfundidade() >= self::PROFUNDIDADE_MAXIMA) {
                throw new \InvalidArgumentException(
                    sprintf('Não é possível passar de %d níveis de pasta.', self::PROFUNDIDADE_MAXIMA),
                );
            }
        }

        $ordem = $this->secaoRepository->proximaOrdem($pasta, $tenant, $pai);

        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem($ordem);
        $secao->setPai($pai);

        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }
}
