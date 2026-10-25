<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ConfiguracaoDeInteligenciaOutput;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\CustoDaIa;
use App\Inteligencia\Service\ProvedorDeLinguagem;

/**
 * O painel /admin/inteligencia: configuração do escritório + estado da plataforma + uso + consumo do mês (gasto
 * estimado em US$ das análises com tokens, inclusive excluídas e falhas). Tudo do escritório recebido, tenant explícito.
 */
final class ConsultarConfiguracaoDeInteligenciaUseCase
{
    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly ProvedorDeLinguagem $provedor,
        private readonly bool $iaHabilitada,
        private readonly CustoDaIa $custo,
    ) {
    }

    public function executar(Tenant $tenant): ConfiguracaoDeInteligenciaOutput
    {
        $inicioDoMes = new \DateTimeImmutable('first day of this month midnight');

        return ConfiguracaoDeInteligenciaOutput::de(
            $this->configuracoes->findDoTenant($tenant),
            $this->provedor->nome(),
            $this->provedor->estaConfigurado(),
            $this->iaHabilitada,
            $this->analises->contarDesde($tenant, new \DateTimeImmutable('today')),
            $this->analises->contarDesde($tenant, $inicioDoMes),
            $this->custo->centavosDoConsumo($this->analises->somarTokensPorModeloDesde($tenant, $inicioDoMes)),
        );
    }
}
