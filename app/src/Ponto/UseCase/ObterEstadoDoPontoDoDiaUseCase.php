<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\EstadoDoPontoDoDiaOutput;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use App\Ponto\Service\EsquecimentosRegistrados;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\HomeOfficeResolver;
use App\Ponto\Service\InicioContagemResolver;
use App\Ponto\Service\PublicoDosAvisosDePonto;
use App\Ponto\Service\SedesDaCercaDoPonto;
use Psr\Clock\ClockInterface;

/**
 * O estado do ponto de hoje para o atalho do cabeçalho (spec `presenca-e-ponto-no-cabecalho.md` §3): qual batida a
 * linha oferece e os dados que a tela precisa para avaliar a cerca antes do envio. Usa a escolha ÚNICA da folha
 * (`ponto-folha-uma-batida-por-tipo.md` §9.9), nunca "a última batida", e só as batidas deste escritório. Não grava e
 * não cria regra: a batida continua sendo decidida pelo servidor em `PontoController::batida()`, e o "Regularizar
 * ponto de ontem" só leva ao pedido de justificativa de esquecimento que já existe (decisão do dono, 19/10). Ele é um
 * aviso ao lado do estado de hoje e nunca tira a batida (`ponto-esquecimento-nao-trava.md` R3, dono 23/10).
 */
final class ObterEstadoDoPontoDoDiaUseCase
{
    private const TIPOS = [RegistroPonto::TIPO_ENTRADA, RegistroPonto::TIPO_REPOUSO, RegistroPonto::TIPO_RETORNO, RegistroPonto::TIPO_SAIDA];

    public function __construct(
        private readonly RegistroPontoRepository $registros,
        private readonly EscolhaDasBatidasDoDia $escolha,
        private readonly HomeOfficeResolver $homeOffice,
        private readonly SedesDaCercaDoPonto $sedes,
        private readonly FolhaPontoBuilder $folha,
        private readonly FeriadoRepository $feriados,
        private readonly JustificativaPontoRepository $justificativas,
        private readonly InicioContagemResolver $inicioContagem,
        private readonly EsquecimentosRegistrados $esquecimentos,
        private readonly ClockInterface $clock,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly PublicoDosAvisosDePonto $publico,
    ) {
    }

    public function executar(User $eu, Tenant $tenant): EstadoDoPontoDoDiaOutput
    {
        $agora = $this->clock->now();
        // O dia de hoje vai para a escolha, como no `index`: a vigência da regra depende dele.
        $escolhidas = $this->escolha->escolher($this->registros->batidasDoDiaNoEscritorio($eu, $tenant, $agora), $agora);
        $hoje = $this->horariosDe($escolhidas);
        // R7: o "Regularizar ponto de ontem" só para o público dos avisos de ponto.
        $ontemSemSaida = $this->publico->recebe($eu, $tenant) ? $this->ontemSemSaida($eu, $tenant, $agora) : null;

        return new EstadoDoPontoDoDiaOutput(
            dia: $agora->format('Y-m-d'),
            estado: self::estadoDe($hoje),
            hoje: $hoje,
            homeOffice: $this->homeOffice->estaLiberado($eu, $tenant, $agora),
            sedes: $this->sedes->doEscritorio($tenant),
            ontemSemSaida: $ontemSemSaida,
            interjornadaMinimaMin: self::interjornadaMinima($tenant),
            aviso: $ontemSemSaida !== null ? EstadoDoPontoDoDiaOutput::REGULARIZAR_ONTEM : null,
        );
    }

    /**
     * Ontem com entrada e sem saída, só quando a folha ofereceria o "+" da saída (`ponto-atalho-esquecimento-registro.md`,
     * condições 4 a 9): a linha de ontem sai do próprio `FolhaPontoBuilder`, com as mesmas entradas do `index` (fim de
     * semana, feriado, início da contagem, escolha das batidas), e o esquecimento já pedido ou abonado não volta.
     */
    private function ontemSemSaida(User $eu, Tenant $tenant, \DateTimeImmutable $agora): ?string
    {
        $ontem = $agora->modify('-1 day');
        $batidas = $this->registros->batidasDoDiaNoEscritorio($eu, $tenant, $ontem);
        if ($batidas === []) {
            return null;   // condição 9: dia sem batida segue pelo "Justificar Falta"
        }
        // O caso comum (ontem com entrada e saída) sai aqui, antes da linha da folha, que custa várias consultas.
        $escolhaDeOntem = $this->escolha->escolher($batidas, $ontem);
        if ($escolhaDeOntem->entrada === null || $escolhaDeOntem->saida !== null) {
            return null;
        }
        $linha = $this->linhaDaFolha($eu, $tenant, $ontem, $batidas);
        $dia = $ontem->format('Y-m-d');
        if ($linha === null || $linha['entradaId'] === null || $linha['saidaId'] !== null
            || $linha['fimSemana'] || $linha['isFeriado'] || $linha['antesDoPrimeiroRegistro']) {
            return null;
        }
        $jaPedido = $this->esquecimentos->daCompetencia($eu, (int) $ontem->format('Y'), (int) $ontem->format('n'));

        return isset($jaPedido[$dia . '|' . RegistroPonto::TIPO_SAIDA]) ? null : $dia;
    }

    /**
     * @param list<RegistroPonto> $batidas
     *
     * @return ?array<string, mixed>
     */
    private function linhaDaFolha(User $eu, Tenant $tenant, \DateTimeImmutable $dia, array $batidas): ?array
    {
        $jornada = $this->jornadas->findByUserETenant($eu, $tenant) ?? (new JornadaColaborador())->setUser($eu);
        $linhas = $this->folha->buildRows(
            $dia->setTime(0, 0, 0),
            $dia->setTime(23, 59, 59),
            $batidas,
            true,
            false,
            $jornada,
            $this->feriados->findByTenant($tenant),
            $this->justificativas->findByUserAndCompetenciaIndexed($eu, (int) $dia->format('Y'), (int) $dia->format('n')),
            $tenant->getJornadaTenant(),
            $this->inicioContagem->resolver($eu, $tenant),
        );

        return $linhas[0] ?? null;
    }

    /**
     * O descanso mínimo que o servidor vai cobrar na próxima entrada, para o aviso da saída (decisão do dono, 19/10):
     * os mesmos padrões de `PontoController::batida()` (sem configuração, a validação vale e são 660 min).
     */
    private static function interjornadaMinima(Tenant $tenant): ?int
    {
        $jornada = $tenant->getJornadaTenant();
        if (!($jornada?->isValidacaoInterjornadaHabilitada() ?? true)) {
            return null;
        }

        return $jornada?->getMinimoMinutosInterjornada() ?? 660;
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
     * Os estados da linha (dc `pontoDe`, mais o intervalo que o dc não tem, aprovado pelo dono): sem entrada, bate a
     * entrada (também no dia com saída e sem entrada: o servidor decide); com saída, concluída; repouso sem retorno, em
     * intervalo (sem ação: um clique no almoço não pode gravar a saída); senão, em jornada.
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
