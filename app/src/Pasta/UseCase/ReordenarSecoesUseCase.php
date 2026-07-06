<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ReordenarSecoesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaSecaoRepository $secaoRepository,
    ) {
    }

    /** @param int[] $idsOrdenados IDs das seções na nova ordem desejada */
    public function executar(Pasta $pasta, Tenant $tenant, array $idsOrdenados): void
    {
        if ($idsOrdenados === []) {
            return;
        }

        $secoes = $this->secaoRepository->findByPasta($pasta, $tenant);

        $mapa = [];
        foreach ($secoes as $secao) {
            $mapa[$secao->getId()] = $secao;
        }

        $novaOrdem = 1;
        foreach ($idsOrdenados as $id) {
            $id = (int) $id;
            if (!isset($mapa[$id])) {
                continue;
            }
            $mapa[$id]->setOrdem($novaOrdem);
            ++$novaOrdem;
        }

        $this->em->flush();
    }
}
