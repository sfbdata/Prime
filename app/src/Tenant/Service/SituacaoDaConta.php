<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Tenant\Tenant;

/**
 * Se a conta (escritório) está fora do ar: suspensa pelo Master (`isActive` falso) ou excluída e em quarentena
 * (`excluidoEm` preenchido; o fluxo de exclusão também desliga `isActive`, mas a regra não depende disso). É a regra
 * que quem age sobre um escritório sem passar pela sessão dele consulta (o Convidado do Trabalho Externo,
 * docs/specs/te-convidado-escritorio-fora-do-ar.md). A "suspensa a pedido" do #125 entra
 * aqui quando ele estiver no master.
 */
final class SituacaoDaConta
{
    public function foraDoAr(Tenant $conta): bool
    {
        return $conta->isActive() !== true || $this->excluida($conta);
    }

    /** Excluída e em quarentena (`excluidoEm` preenchido): fora do ar e, para quem é de fora, nem para leitura. */
    public function excluida(Tenant $conta): bool
    {
        return $conta->getExcluidoEm() !== null;
    }
}
