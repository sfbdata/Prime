<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Sede;
use App\Repository\SedeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * O "Remover" da sede (Ponto E10, decisão do Coordenador de 09/11): a batida gravada é só inserção, então a sede que tem
 * batida não é apagada. Ela é encerrada (`removidaEm`): some das listas e da cerca, não vale para batida nova, e as
 * batidas antigas continuam apontando para ela. A sede sem batida é apagada como antes.
 *
 * Grava com flush; quem chama abre a transação. Se uma batida nova entrar na sede entre a conferência e o DELETE, a FK
 * (ON DELETE SET NULL) tenta mudar a batida, o gatilho `registro_ponto_so_insercao` recusa e a remoção falha inteira:
 * quem perde é o administrador (tenta de novo), nunca a batida.
 */
final class RemocaoDaSedeDoPonto
{
    public function __construct(
        private readonly SedeRepository $sedes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return bool true quando a sede foi encerrada (tinha batida); false quando foi apagada. */
    public function remover(Sede $sede): bool
    {
        if ($this->sedes->temBatida($sede)) {
            $sede->encerrar($this->relogio->now());
            $this->em->flush();

            return true;
        }

        $this->em->remove($sede);
        $this->em->flush();

        return false;
    }
}
