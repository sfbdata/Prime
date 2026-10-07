<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\FonteDeContagensDaPasta;
use App\Inteligencia\DTO\OQueAIaSabeOutput;
use App\Pasta\Entity\Pasta;

/**
 * Grade "O que a IA sabe desta pasta" (IA-006, desenho dc L861-864). Quem: quem pode ver a pasta e
 * usar a IA (guarda do controller do painel). O quê: quatro contagens reais desta pasta, deste
 * escritório. Por quê: mostrar, antes do clique, o volume do que os agentes vão ler.
 *
 * A Pasta chega já conferida pelo chamador (tenant + `canAccessResource`); o tenant vai explícito
 * para a fonte mesmo assim.
 */
final class ContarOQueAIaSabeDaPastaUseCase
{
    public function __construct(
        private readonly FonteDeContagensDaPasta $contagens,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): OQueAIaSabeOutput
    {
        return $this->contagens->contar($tenant, $pasta);
    }
}
