<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ConfiguracaoDeInteligenciaInput;
use App\Inteligencia\DTO\ConfiguracaoDeInteligenciaOutput;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;

/**
 * O admin do escritório liga/desliga a BlueJus IA e define cotas e mascaramento. Quem: quem tem
 * `admin.inteligencia.manage` (o controller confere). Cria a linha na primeira gravação; ligar
 * registra o consentimento (D3). Auditado pela interface `Auditavel`.
 */
final class AtualizarConfiguracaoDeInteligenciaUseCase
{
    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly ConsultarConfiguracaoDeInteligenciaUseCase $consultar,
    ) {
    }

    public function executar(ConfiguracaoDeInteligenciaInput $input, User $user, Tenant $tenant): ConfiguracaoDeInteligenciaOutput
    {
        $configuracao = $this->configuracoes->findDoTenant($tenant) ?? new ConfiguracaoDeInteligencia($tenant);

        $configuracao->atualizar(
            habilitada: $input->habilitada,
            limiteDiario: (int) ($input->limiteDiario ?? ConfiguracaoDeInteligencia::LIMITE_DIARIO_PADRAO),
            limiteMensal: (int) ($input->limiteMensal ?? ConfiguracaoDeInteligencia::LIMITE_MENSAL_PADRAO),
            mascararDadosPessoais: $input->mascararDadosPessoais,
            por: $user,
        );

        $this->configuracoes->salvar($configuracao, true);

        return $this->consultar->executar($tenant);
    }
}
