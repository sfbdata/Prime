<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ConfiguracaoDeInteligenciaOutput;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\ProvedorDeLinguagem;

/**
 * O painel /admin/inteligencia: configuração do escritório + estado da plataforma + uso.
 */
final class ConsultarConfiguracaoDeInteligenciaUseCase
{
    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly ProvedorDeLinguagem $provedor,
        private readonly bool $iaHabilitada,
    ) {
    }

    public function executar(Tenant $tenant): ConfiguracaoDeInteligenciaOutput
    {
        return ConfiguracaoDeInteligenciaOutput::de(
            $this->configuracoes->findDoTenant($tenant),
            $this->provedor->nome(),
            $this->provedor->estaConfigurado(),
            $this->iaHabilitada,
            $this->analises->contarDesde($tenant, new \DateTimeImmutable('today')),
            $this->analises->contarDesde($tenant, new \DateTimeImmutable('first day of this month midnight')),
        );
    }
}
