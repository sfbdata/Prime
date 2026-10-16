<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;

/**
 * Define (ou limpa) o escritório-sede da Central, de onde sai a Equipe (D2).
 *
 * Quem: o dono do sistema, pelo console (`trabalho-externo:definir-sede`). O desenho não tem
 * lugar para isso na tela, então fica fora dela até o dono decidir onde mora.
 */
final class DefinirSedeDaCentralUseCase
{
    public function __construct(
        private readonly ConfiguracaoCentralRepository $repositorio,
    ) {
    }

    public function executar(?Tenant $sede): ConfiguracaoCentral
    {
        $cfg = $this->repositorio->atual();
        $cfg->definirSede($sede);
        $this->repositorio->salvar($cfg, flush: true);

        return $cfg;
    }
}
