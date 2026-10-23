<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\AnalisesDoComandoOutput;
use App\Inteligencia\DTO\PainelDeComandosOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Pasta\Entity\Pasta;

/**
 * As análises dos comandos e relatórios do drawer de uma pasta (spec inteligencia-drawer-da-pasta,
 * F1 e F2): o painel inteiro (as N mais recentes de CADA comando, uma consulta por comando, para um
 * comando com muitas análises não esconder os outros) ou a lista de UM comando (recarga do
 * fragmento depois do polling). As análises antigas, sem comando, aparecem no comando que
 * {@see Comando::doAgenteLegado} dá ao agente delas.
 *
 * Recebe a Pasta já resolvida e conferida pelo chamador (tenant + `canAccessResource`), como o
 * {@see ListarAnalisesDosAgentesUseCase}.
 */
final class ListarAnalisesDosComandosUseCase
{
    public const LIMITE_POR_COMANDO = 10;

    /** Mesma folga do filtro das abas ocultas do {@see ListarAnalisesDosAgentesUseCase}. */
    public const FOLGA_DO_FILTRO = 3;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    /** @param list<string> $abasOcultas abas da pasta ocultas para quem lê */
    public function executar(Pasta $pasta, Tenant $tenant, array $abasOcultas): PainelDeComandosOutput
    {
        $saidas = [];
        foreach (Comando::cases() as $comando) {
            $saidas[$comando->value] = $this->executarParaComando($pasta, $tenant, $comando, $abasOcultas);
        }

        return new PainelDeComandosOutput($saidas);
    }

    /** @param list<string> $abasOcultas */
    public function executarParaComando(Pasta $pasta, Tenant $tenant, Comando $comando, array $abasOcultas): AnalisesDoComandoOutput
    {
        $entidades = $this->analises->listarPorComando(
            $tenant,
            AnaliseDeInteligencia::ALVO_PASTA,
            (int) $pasta->getId(),
            $comando,
            self::LIMITE_POR_COMANDO * self::FOLGA_DO_FILTRO,
        );

        $visiveis = \array_slice(array_values(array_filter(
            $entidades,
            static fn (AnaliseDeInteligencia $a): bool => VisibilidadeDaAnaliseDaPasta::legivelCom($a, $abasOcultas),
        )), 0, self::LIMITE_POR_COMANDO);

        $saidas = [];
        $ultimaConcluida = null;
        foreach ($visiveis as $entidade) {
            $saida = AnaliseOutput::fromEntity($entidade);
            $saidas[] = $saida;
            if ($ultimaConcluida === null && $entidade->estaConcluida()) {
                $ultimaConcluida = $saida;
            }
        }

        return new AnalisesDoComandoOutput($comando, $saidas, $ultimaConcluida);
    }
}
