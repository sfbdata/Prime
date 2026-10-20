<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Tenant\Tenant;

/**
 * Se a conta (escritório) está fora do ar: suspensa pelo Master ou excluída e em quarentena (as duas deixam
 * `isActive` falso). É a regra que quem age sobre um escritório sem passar pela sessão dele consulta (o Convidado
 * do Trabalho Externo, docs/specs/te-convidado-escritorio-fora-do-ar.md). A "suspensa a pedido" do #125 entra
 * aqui quando ele estiver no master.
 */
final class SituacaoDaConta
{
    public function foraDoAr(Tenant $conta): bool
    {
        return $conta->isActive() !== true;
    }
}
