<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\EstadoDoPontoDoDiaOutput;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use App\Ponto\Service\HomeOfficeResolver;
use App\Ponto\Service\SedesDaCercaDoPonto;
use Psr\Clock\ClockInterface;

/**
 * O estado do ponto de hoje para o atalho do cabeçalho (spec `presenca-e-ponto-no-cabecalho.md` §3): qual batida a
 * linha oferece e os dados que a tela precisa para avaliar a cerca antes do envio. Usa a escolha ÚNICA da folha
 * (`ponto-folha-uma-batida-por-tipo.md` §9.9), nunca "a última batida", e só as batidas deste escritório. Não grava e
 * não cria regra: a batida continua sendo decidida pelo servidor em `PontoController::batida()`.
 */
final class ObterEstadoDoPontoDoDiaUseCase
{
    private const TIPOS = [RegistroPonto::TIPO_ENTRADA, RegistroPonto::TIPO_REPOUSO, RegistroPonto::TIPO_RETORNO, RegistroPonto::TIPO_SAIDA];

    public function __construct(
        private readonly RegistroPontoRepository $registros,
        private readonly EscolhaDasBatidasDoDia $escolha,
        private readonly HomeOfficeResolver $homeOffice,
        private readonly SedesDaCercaDoPonto $sedes,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(User $eu, Tenant $tenant): EstadoDoPontoDoDiaOutput
    {
        $agora = $this->clock->now();
        // O dia de hoje vai para a escolha, como no `index`: a vigência da regra depende dele.
        $escolhidas = $this->escolha->escolher($this->registros->batidasDoDiaNoEscritorio($eu, $tenant, $agora), $agora);
        $hoje = $this->horariosDe($escolhidas);

        return new EstadoDoPontoDoDiaOutput(
            dia: $agora->format('Y-m-d'),
            estado: self::estadoDe($hoje),
            hoje: $hoje,
            homeOffice: $this->homeOffice->estaLiberado($eu, $tenant, $agora),
            sedes: $this->sedes->doEscritorio($tenant),
            // O mesmo padrão do servidor (`PontoController::batida`): sem configuração de jornada a validação vale.
            interjornadaLigada: $tenant->getJornadaTenant()?->isValidacaoInterjornadaHabilitada() ?? true,
        );
    }

    /** @return array{entrada: ?string, repouso: ?string, retorno: ?string, saida: ?string} */
    private function horariosDe(BatidasEscolhidas $escolhidas): array
    {
        $hoje = ['entrada' => null, 'repouso' => null, 'retorno' => null, 'saida' => null];
        foreach (self::TIPOS as $tipo) {
            $hoje[$tipo] = $escolhidas->doTipo($tipo)?->getDataHora()?->format('H:i');
        }

        return $hoje;
    }

    /**
     * Os estados da linha (dc `pontoDe`, mais o intervalo que o dc não tem): sem entrada, bate a entrada (também no dia
     * com saída e sem entrada: o servidor decide); com saída, concluída; repouso sem retorno, em intervalo (sem ação:
     * um clique no almoço não pode gravar a saída); senão, em jornada.
     *
     * @param array{entrada: ?string, repouso: ?string, retorno: ?string, saida: ?string} $hoje
     */
    private static function estadoDe(array $hoje): string
    {
        if ($hoje['entrada'] === null) {
            return EstadoDoPontoDoDiaOutput::BATER_ENTRADA;
        }
        if ($hoje['saida'] !== null) {
            return EstadoDoPontoDoDiaOutput::JORNADA_CONCLUIDA;
        }
        if ($hoje['repouso'] !== null && $hoje['retorno'] === null) {
            return EstadoDoPontoDoDiaOutput::EM_INTERVALO;
        }

        return EstadoDoPontoDoDiaOutput::EM_JORNADA;
    }
}
