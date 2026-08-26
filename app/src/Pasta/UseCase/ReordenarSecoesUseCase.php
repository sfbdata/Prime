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

        // A ordem é POSIÇÃO ENTRE IRMÃS: cada grupo de mesmo pai numera do 1. Um contador global
        // faria a 1ª subpasta de um pai nascer com a ordem do fim da lista da pasta inteira.
        $proxima = [];
        foreach ($idsOrdenados as $id) {
            $id = (int) $id;
            if (!isset($mapa[$id])) {
                continue;
            }
            $secao = $mapa[$id];
            $chave = (string) ($secao->getPai()?->getId() ?? 'raiz');
            $proxima[$chave] ??= 1;
            $secao->setOrdem($proxima[$chave]);
            ++$proxima[$chave];
        }

        $this->em->flush();
    }
}
