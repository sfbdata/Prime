<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Move uma pasta para dentro de outra, ou de volta para a raiz da pasta ($destino = null).
 *
 * Os dois guards daqui não são zelo: sem o de ciclo a pasta some da árvore (ninguém a alcança a
 * partir da raiz) e, quando o espelho com o Drive entrar (Entrega 2), a travessia vira laço
 * infinito contra a API real. O de profundidade valida a SUBÁRVORE inteira — validar só o nó
 * movido deixa passar uma árvore de 4 níveis indo parar no nível 8.
 */
final class MoverPastaSecaoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaSecaoRepository $secaoRepository,
    ) {
    }

    public function executar(PastaSecao $secao, ?PastaSecao $destino, User $autor, Tenant $tenant): void
    {
        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        $pasta = $secao->getPasta();
        if ($pasta === null) {
            throw new \LogicException('Seção sem pasta associada.');
        }

        if ($destino !== null) {
            if ($destino->getTenant() !== $tenant) {
                throw new AccessDeniedException('Pasta de destino não pertence ao tenant do usuário.');
            }

            if ($destino->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('A pasta de destino não pertence à mesma pasta.');
            }

            if ($destino === $secao || $destino->descendeDe($secao)) {
                throw new \InvalidArgumentException('Não é possível mover uma pasta para dentro dela mesma.');
            }

            if ($destino->getProfundidade() + $secao->getAltura() > CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA) {
                throw new \InvalidArgumentException(
                    sprintf('Não é possível passar de %d níveis de pasta.', CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA),
                );
            }
        }

        $secao->setPai($destino);
        $secao->setOrdem($this->secaoRepository->proximaOrdem($pasta, $tenant, $destino));

        $this->em->flush();
    }
}
