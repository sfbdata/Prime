<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Entity\Auth\User;

/**
 * Quem vê e chama as ferramentas do Painel do Master (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisões 34 e 38): as de
 * {@see CatalogoFechadoDoConector::SO_DO_MASTER} só aparecem para o Master da plataforma, no `tools/list`, na
 * `quem_sou_eu` e no guia das "Conexões de I.A."; as outras, para todos, como antes. Sem pessoa conhecida, nenhuma do
 * Master aparece (lado seguro).
 */
final class VisibilidadeDasFerramentasDoMaster
{
    private function __construct()
    {
    }

    public static function visivelPara(string $nome, ?User $pessoa): bool
    {
        if (!CatalogoFechadoDoConector::soDoMaster($nome)) {
            return true;
        }

        return $pessoa !== null && GuardaDoMasterDoConector::ehMaster($pessoa);
    }
}
