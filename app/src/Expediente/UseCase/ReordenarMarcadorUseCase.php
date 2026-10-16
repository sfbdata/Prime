<?php

declare(strict_types=1);

namespace App\Expediente\UseCase;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Leva um marcador para antes ou depois de outro no trilho: é o arrastar e soltar e o "Mover para cima /
 * para baixo" do desenho 1.1 (dc `mkMover`, E12).
 *
 * História: quem organiza o expediente arrasta "4. Protocolar" para cima de "2. Distribuir". Os dois
 * precisam estar no mesmo nível (dois de topo, ou dois submarcadores do mesmo pai); o movido entra na
 * posição pedida e o nível inteiro é renumerado 0..n, o que também conserta ordens repetidas antigas
 * (todos nasceram com ordem 0). Os submarcadores acompanham o pai sem precisar mexer neles.
 */
final class ReordenarMarcadorUseCase
{
    public function __construct(
        private readonly MarcadorRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(int $id, int $alvoId, bool $depois, Tenant $tenant): void
    {
        $movido = $this->repository->findPorTenant($id, $tenant);
        $alvo   = $this->repository->findPorTenant($alvoId, $tenant);

        if ($movido === null || $alvo === null) {
            throw new \DomainException('Marcador não encontrado.');
        }

        if ($movido === $alvo) {
            return;
        }

        if ($movido->getPai() !== $alvo->getPai()) {
            throw new \DomainException($movido->isRaiz()
                ? 'Arraste para cima ou para baixo de outro marcador principal'
                : 'Submarcadores só podem ser reordenados dentro do mesmo marcador');
        }

        $resto = array_values(array_filter(
            $this->repository->findIrmaos($movido->getPai(), $tenant),
            fn (Marcador $m): bool => $m !== $movido,
        ));

        $posicao = array_search($alvo, $resto, true);
        array_splice($resto, $depois ? $posicao + 1 : $posicao, 0, [$movido]);

        foreach ($resto as $ordem => $marcador) {
            $marcador->setOrdem($ordem);
        }

        $this->em->flush();
    }
}
