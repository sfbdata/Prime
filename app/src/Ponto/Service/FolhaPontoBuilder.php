<?php

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;

class FolhaPontoBuilder
{
    public function __construct(
        private readonly CalculadoraJornada $calculadora,
        private readonly RegistroPontoRepository $registroPontoRepository,
        private readonly JustificativaPontoRepository $justificativaPontoRepository,
        private readonly LancamentoHorasPagasRepository $lancamentoHorasPagasRepository,
        private readonly JornadaColaboradorRepository $jornadaColaboradorRepository,
        private readonly FeriadosDaPessoa $feriadosDaPessoa,
    ) {}

    /**
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     * @param array<string, JustificativaPonto> $justificativasDoMes Justificativas indexadas por 'Y-m-d'
     * @param JornadaColaborador|HistoricoDeJornadas|null $jornada As versões da jornada pessoal (cada dia usa a que
     *                                            valia nele, spec ponto-jornada-vigencia §3.2). Uma jornada solta vale
     *                                            para todos os dias, como antes das versões.
     * @param ?\DateTimeInterface $inicioContagem Data da primeira batida do colaborador — início da contagem.
     *                                            `null` significa colaborador sem nenhum registro de ponto: nenhum
     *                                            dia conta. Dias anteriores a ela ficam fora (sem saldo, sem somar
     *                                            no banco). Admissão e data de cadastro não entram nessa conta.
     * @param ?\DateTimeImmutable $hoje         O dia de hoje da folha (dia futuro e hoje sem saída). Omitido, o de
     *                                            agora; o banco passa o dia de referência dos lotes (E17).
     * @return array<int, array{diaMes: string, diaSemana: string, entrada: string, repouso: string, retorno: string, saida: string, fimSemana: bool, minutosTrabalhadosDia: int|null, saldoDia: int|null, saldoAcumulado: int|null, saldoAcumuladoAteOntem: int|null, justificadoDia: bool, justificativa: JustificativaPonto|null, homeOffice: bool, semInternet: bool, antesDoPrimeiroRegistro: bool, registroIncompleto: bool, aConferir: list<string>, batidasDesconsideradas: list<array{id: ?int, tipo: string, hora: string}>}>
     */
    public function buildRows(
        \DateTimeImmutable $inicioMes,
        \DateTimeImmutable $fimMes,
        array $batidas,
        bool $includeEmptyDays = true,
        bool $orderDesc = false,
        JornadaColaborador|HistoricoDeJornadas|null $jornada = null,
        array $feriados = [],
        array $justificativasDoMes = [],
        ?JornadaTenant $jornadaTenant = null,
        \DateTimeInterface|null|false $inicioContagem = false,
        ?\DateTimeImmutable $hoje = null
    ): array {
        $this->exigirInicioContagem($inicioContagem, __FUNCTION__);
        $historico = $jornada instanceof JornadaColaborador ? HistoricoDeJornadas::deUma($jornada) : $jornada;

        // Todas as batidas de cada dia, na ordem em que chegaram. Quais delas valem é decidido pela
        // escolha única (`CalculadoraJornada::escolherBatidas`), e células, links, intervalo, minutos e
        // saldo leem essa mesma escolha. Até 21/09/2026 a folha escolhia aqui, sozinha, e a regra da
        // calculadora nunca chegava à tela (`docs/specs/ponto-folha-uma-batida-por-tipo.md`).
        $batidasPorDia = [];
        foreach ($batidas as $batida) {
            $batidasPorDia[$batida->getDataHora()->format('Y-m-d')][] = $batida;
        }

        $diasSemana = [
            1 => 'Segunda',
            2 => 'Terça',
            3 => 'Quarta',
            4 => 'Quinta',
            5 => 'Sexta',
            6 => 'Sábado',
            7 => 'Domingo',
        ];

        $rows = [];
        $saldoAcumulado = null;
        $hoje = $hoje?->setTime(0, 0) ?? new \DateTimeImmutable('today');

        $inicioContagemNorm = $inicioContagem !== null
            ? \DateTimeImmutable::createFromInterface($inicioContagem)->setTime(0, 0, 0)
            : null;

        for ($dia = $inicioMes; $dia <= $fimMes; $dia = $dia->modify('+1 day')) {
            $chaveDia = $dia->format('Y-m-d');
            $indiceDiaSemana = (int) $dia->format('N');
            $diaFuturo = $dia > $hoje;
            // Sem primeiro registro, nenhum dia conta; com ele, os anteriores ficam de fora.
            $diaForaDaContagem = $inicioContagemNorm === null || $dia < $inicioContagemNorm;
            $escolha = $this->calculadora->escolherBatidas($batidasPorDia[$chaveDia] ?? [], $dia);

            $row = [
                'diaMes'    => $dia->format('d'),
                'diaSemana' => $diasSemana[$indiceDiaSemana],
                'chaveDia'  => $chaveDia,
                'entrada'   => $escolha->entrada?->getDataHora()->format('H:i:s') ?? '',
                'repouso'   => $escolha->repouso?->getDataHora()->format('H:i:s') ?? '',
                'retorno'   => $escolha->retorno?->getDataHora()->format('H:i:s') ?? '',
                'saida'     => $escolha->saida?->getDataHora()->format('H:i:s')   ?? '',
                'entradaId' => $escolha->entrada?->getId(),
                'repousoId' => $escolha->repouso?->getId(),
                'retornoId' => $escolha->retorno?->getId(),
                'saidaId'   => $escolha->saida?->getId(),
                // Dia ambíguo (dois registros distintos do mesmo tipo, retorno antes do repouso): só
                // avisa, na tela do colaborador e na ficha do admin. O PDF e o XLSX assinados não mudam.
                'aConferir' => $escolha->aConferir,
                // As batidas do dia que não entram na conta, para o admin alcançar e corrigir — antes
                // desta mudança elas não apareciam em lugar nenhum da folha.
                'batidasDesconsideradas' => array_map(
                    static fn (RegistroPonto $batida): array => [
                        'id'   => $batida->getId(),
                        'tipo' => $batida->getTipo(),
                        'hora' => $batida->getDataHora()->format('H:i:s'),
                    ],
                    $escolha->desconsideradas,
                ),
                'fimSemana'       => $indiceDiaSemana >= 6,
                'domingo'         => $indiceDiaSemana === 7,
                'antesDoPrimeiroRegistro' => $diaForaDaContagem,
                // Nasce false e só o ramo que apura o dia decide o contrário: dia futuro, dia anterior
                // ao primeiro registro, colaborador sem jornada e o "hoje ainda sem saída" não têm
                // saldo apurado, e marcar pendência neles seria cobrar batida que ainda vai acontecer.
                'registroIncompleto'      => false,
                'isFeriado'       => false,
                'nomeFeriado'     => null,
                'minutosIntervalo'      => null,
                'minutosTrabalhadosDia' => null,
                'saldoDia'       => null,
                'saldoAcumulado' => null,
                'saldoAcumuladoAteOntem' => null,   // E17: o "Saldo mês" do dia em andamento (desenho L157)
                'justificadoDia' => false,
                'justificativa'  => $justificativasDoMes[$chaveDia] ?? null,
                'homeOffice'     => $this->diaTeveHomeOffice($escolha->escolhidas()),
                'semInternet'    => $this->diaTeveBatidaSemInternet($escolha->escolhidas()),
            ];

            $jornadaDoDia = $historico?->vigenteEm($dia);
            if ($jornadaDoDia !== null) {
                // Os que valem para a pessoa da jornada NO DIA (abrangência pela lotação que ela tinha no dia, E6).
                $feriadosDaPessoa = $this->feriadosDaPessoa->filtrar($feriados, $jornadaDoDia->getUser(), $dia);
                $feriadoDoDia = $this->calculadora->getFeriadoDoDia($dia, $feriadosDaPessoa);
                if ($diaFuturo || $diaForaDaContagem) {
                    // Dia futuro ou anterior ao primeiro registro: não exibe saldo nem soma no banco
                    $row['minutosTrabalhadosDia'] = null;
                    $row['saldoDia']              = null;
                    $row['saldoAcumulado']        = null;
                } else {
                    if ($feriadoDoDia !== null) {
                        $row['isFeriado']   = true;
                        $row['nomeFeriado'] = $feriadoDoDia->getNome();
                        $row['diaSemana']   = 'FERIADO - ' . $row['diaSemana'];
                    }

                    $temSaida = $escolha->saida !== null;
                    $diaHoje  = $dia->format('Y-m-d') === $hoje->format('Y-m-d');

                    // minutosIntervalo: do repouso ao retorno ESCOLHIDOS (os das células). A fórmula é a
                    // de sempre, mesmo com os dois fora de ordem: é ela que alimenta o indicador
                    // "intrajornada conforme" do PDF e do XLSX, que esta mudança não altera. Por isso a
                    // distância absoluta, e não o `diffMinutos`, que desde 23/10 não credita intervalo invertido.
                    if ($escolha->repouso !== null && $escolha->retorno !== null) {
                        $row['minutosIntervalo'] = $this->calculadora->distanciaEmMinutos(
                            $escolha->repouso->getDataHora(),
                            $escolha->retorno->getDataHora()
                        );
                    }

                    // Dia atual sem saída: mostra horas trabalhadas mas não saldo nem banco
                    if ($diaHoje && !$temSaida) {
                        $minutos = $this->calculadora->calcularMinutosDaEscolha($escolha);
                        $row['minutosTrabalhadosDia'] = $minutos;
                        $row['saldoDia']              = null;
                        $row['saldoAcumulado']        = null;
                        $row['saldoAcumuladoAteOntem'] = $saldoAcumulado;   // null sem dia apurado antes (dia 1º)
                    } else {
                        $minutos = $this->calculadora->calcularMinutosDaEscolha($escolha);
                        $saldoDia = $this->calculadora->calcularSaldoDiaDaEscolha($jornadaDoDia->getUser(), $dia, $escolha, $jornadaDoDia, $feriadosDaPessoa, $jornadaTenant);

                        $registroIncompleto = $this->calculadora->registroIncompletoDaEscolha($escolha);

                        $justificativaDoDia = $justificativasDoMes[$chaveDia] ?? null;
                        $justificadoDia = false;

                        // Dia mal batido JÁ VEIO zerado de `calcularSaldoDia` — não se zera de novo
                        // aqui: duas fontes para o mesmo zero divergem na primeira mudança, e uma
                        // injeção de defeito na calculadora passaria despercebida por este caminho.
                        // O que a flag faz é pular o tratamento de justificativa: nenhuma abona um dia
                        // incompleto, nem o abono parcial (que somaria minutos a um dia sem medição).
                        // A pendência existe para o admin corrigir as batidas, não para abonar por cima.
                        if (!$registroIncompleto && $justificativaDoDia !== null && $justificativaDoDia->getStatus() === 'abonado') {
                            // `tryFrom` porque `tipo` é string anulável na entidade e o editar aceita
                            // limpá-la: tipo nulo ou slug desconhecido continua abonando, como sempre fez.
                            $tipoJustificativa = $justificativaDoDia->getTipo() !== null
                                ? TipoJustificativa::tryFrom($justificativaDoDia->getTipo())
                                : null;

                            if ($tipoJustificativa !== null && !$tipoJustificativa->abonaSaldo()) {
                                // Categoria técnica (Esquecimento de Registro e irmãos) e falta não
                                // justificada: aparecem na folha, mas não perdoam a jornada. A aprovação
                                // do esquecimento já repôs a batida — o déficit que sobra é atraso ou
                                // saída antecipada, que nada têm a ver com o registro esquecido.
                                $justificadoDia = true;
                            } elseif ($justificativaDoDia->isAbonoParcial()) {
                                $saldoDia += $justificativaDoDia->getMinutosAbonados();
                                $justificadoDia = true;
                            } elseif ($saldoDia < 0) {
                                $saldoDia = 0;
                                $justificadoDia = true;
                            }
                        }

                        $saldoAcumulado = ($saldoAcumulado ?? 0) + $saldoDia;

                        $row['minutosTrabalhadosDia'] = $minutos;
                        $row['saldoDia']              = $saldoDia;
                        $row['saldoAcumulado']        = $saldoAcumulado;
                        $row['justificadoDia']        = $justificadoDia;
                        $row['justificativa']         = $justificativaDoDia;
                        $row['registroIncompleto']    = $registroIncompleto;
                    }
                }
            }

            if (
                !$includeEmptyDays
                && $row['entrada'] === ''
                && $row['repouso'] === ''
                && $row['retorno'] === ''
                && $row['saida'] === ''
                && $row['justificativa'] === null
            ) {
                continue;
            }

            $rows[] = $row;
        }

        if ($orderDesc) {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Saldo do mês exibido: o ÚLTIMO `saldoAcumulado` não-nulo das linhas da folha — exatamente o
     * número que a última linha preenchida da coluna "Saldo do Mês" mostra. `null` quando nenhuma
     * linha tem saldo apurado (mês sem batida nenhuma; dia em aberto, sem saída, devolve `null` e
     * por isso a varredura é de trás para frente). O banco de horas soma este número mês a mês
     * (`saldosDaFolhaPorCompetencia`).
     *
     * Atenção ao escopo: é o saldo DAQUELE MÊS, não o banco acumulado — `buildRows` faz o
     * `saldoAcumulado` nascer em zero no primeiro dia do intervalo pedido.
     *
     * O banco (`saldosDaFolhaPorCompetencia`) soma este número mês a mês. A /ponto e a ficha do admin tiram o total do
     * mês do próprio banco (E17), e o PDF e o XLSX fazem a mesma conta sobre as linhas em `montarDadosFolha`.
     *
     * @param array<int, array<string, mixed>> $rows linhas de `buildRows()`
     */
    public function saldoAcumuladoFinal(array $rows): ?int
    {
        foreach (array_reverse($rows) as $row) {
            if (($row['saldoAcumulado'] ?? null) !== null) {
                return (int) $row['saldoAcumulado'];
            }
        }

        return null;
    }

    /**
     * Indica se o dia teve ao menos uma batida em home office (para o selo no espelho).
     *
     * @param list<RegistroPonto> $batidasDoDia as escolhidas do dia, como sempre foi
     */
    private function diaTeveHomeOffice(array $batidasDoDia): bool
    {
        foreach ($batidasDoDia as $batida) {
            if ($batida->isHomeOffice()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica se alguma batida escolhida do dia veio da fila do aparelho, guardada sem internet (selo "Sem internet",
     * spec ponto-desenho-0811 E14). Como o home office: as batidas fora da conta não acendem o selo.
     *
     * @param list<RegistroPonto> $batidasDoDia as escolhidas do dia
     */
    private function diaTeveBatidaSemInternet(array $batidasDoDia): bool
    {
        foreach ($batidasDoDia as $batida) {
            if ($batida->isEnviadaSemInternet()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distingue "não informado" (erro de chamada) de `null` ("colaborador sem registro de ponto").
     * Sem esta guarda, esquecer o parâmetro zeraria a folha em SILÊNCIO — e a semântica do valor
     * omitido acabou de se inverter (antes: conta tudo; agora: não conta nada), então uma chamada
     * antiga sobrevivendo a um merge daria saldo errado sem nenhum sinal.
     */
    private function exigirInicioContagem(\DateTimeInterface|null|false $inicioContagem, string $metodo): void
    {
        if ($inicioContagem === false) {
            throw new \InvalidArgumentException(sprintf(
                '%s: informe $inicioContagem (data da primeira batida do colaborador). '
                . 'null significa "sem nenhum registro de ponto", não "não informado".',
                $metodo
            ));
        }
    }

    /**
     * A jornada da folha da /ponto e do banco de horas, montada só aqui: as versões da jornada pessoal no escritório da
     * folha (cada dia usa a que valia nele, spec seguranca-b-jornada §3.2 e ponto-jornada-vigencia §3.2) e, para quem
     * não tem jornada pessoal e nos dias antes da primeira versão, a jornada vazia, que o JornadaResolver completa com a
     * padrão do escritório (§10.4). O banco (topo, aba, saldo anterior do PDF e do XLSX) conta os dias com esta mesma
     * jornada, então a soma do "Saldo mês" da folha da /ponto é o banco.
     */
    public function jornadaDaFolha(User $user, Tenant $tenant): HistoricoDeJornadas|JornadaColaborador
    {
        $semJornadaPessoal = (new JornadaColaborador())->setUser($user);

        return $this->jornadaColaboradorRepository->historicoDe($user, $tenant)?->comJornadaAntesDaPrimeira($semJornadaPessoal)
            ?? $semJornadaPessoal;
    }

    /**
     * Mesma sentinela do $inicioContagem, e pelo mesmo motivo: distingue "não informado" (erro de
     * chamada) de `null` ("não há tenant"). Enquanto o parâmetro tinha default `null`, qualquer
     * chamador que esquecesse o tenant perdia os lançamentos de horas pagas em SILÊNCIO — sem
     * exceção, sem log, com a folha aparentemente normal e o banco de horas errado.
     */
    private function exigirTenant(Tenant|null|false $tenant, string $metodo): ?Tenant
    {
        if ($tenant === false) {
            throw new \InvalidArgumentException(sprintf(
                '%s: informe $tenant (escritório do colaborador). '
                . 'null significa "não há tenant" — e então os lançamentos de horas pagas não somam.',
                $metodo
            ));
        }

        return $tenant;
    }

    /**
     * O banco de horas até o último dia do mês informado: o "saldo anterior" da competência exportada e o "saldo
     * depois" do aviso das horas pagas. Desde a E17 (spec ponto-desenho-0811, P51 a) atravessa os anos: soma todas as
     * competências de `saldosDoBancoPorCompetencia` até o mês pedido, o mesmo número dos lotes da aba "Banco de Horas".
     *
     * @param Feriado[] $feriados
     * @param Tenant|null|false $tenant Escritório do colaborador, obrigatório: `false` (omitido) é erro
     *                                  de chamada e lança; `null` significa "não há tenant" e então os
     *                                  lançamentos de horas pagas não somam.
     */
    public function calcularSaldoAteMes(User $user, int $ano, int $mes, array $feriados, ?JornadaTenant $jornadaTenant = null, \DateTimeInterface|null|false $inicioContagem = false, Tenant|null|false $tenant = false): int
    {
        $this->exigirInicioContagem($inicioContagem, __FUNCTION__);
        $tenantNorm = $this->exigirTenant($tenant, __FUNCTION__);
        $inicio = $inicioContagem instanceof \DateTimeInterface ? $inicioContagem : null;
        $ate = sprintf('%04d-%02d', $ano, $mes);

        $saldo = 0;
        foreach ($this->saldosDoBancoPorCompetencia($user, $tenantNorm, $feriados, $jornadaTenant, $inicio) as $competencia => $minutos) {
            if ($competencia <= $ate) {
                $saldo += $minutos;
            }
        }

        return $saldo;
    }

    /**
     * O banco de horas mês a mês ('AAAA-MM' => minutos, da competência mais antiga para a mais nova), a fonte única do
     * saldo do topo, dos lotes (`BancoDeHorasPorLotes`) e do "saldo anterior" das exportações.
     *
     * O valor de um mês é o saldo da folha daquele mês (do início da contagem até hoje; dia futuro e hoje sem saída não
     * contam), com a jornada da folha da /ponto (`jornadaDaFolha`), mais os lançamentos de horas pagas da competência,
     * com sinal (P53). Atravessa os anos: o banco não zera mais em 1º de janeiro (P51 a). Horas pagas somam sempre: o
     * lançamento de antes do início da contagem ou de competência futura entra na própria competência. Sem escritório,
     * nada (não há como filtrar). `$hoje` é o dia de referência (o mesmo dos vencimentos dos lotes); omitido, hoje.
     *
     * Consultas fixas, qualquer que seja o número de meses: a jornada, as batidas e as justificativas do período
     * inteiro e as horas pagas agrupadas por competência. A /ponto abre várias vezes por dia e os meses crescem sem teto.
     *
     * @param Feriado[] $feriados
     * @return array<string, int>
     */
    public function saldosDoBancoPorCompetencia(User $user, ?Tenant $tenant, array $feriados, ?JornadaTenant $jornadaTenant, ?\DateTimeInterface $inicioContagem, ?\DateTimeImmutable $hoje = null): array
    {
        if ($tenant === null) {
            return [];
        }

        return $this->saldosDoBancoComJornada($user, $tenant, $this->jornadaDaFolha($user, $tenant), $feriados, $jornadaTenant, $inicioContagem, $hoje ?? new \DateTimeImmutable('today'));
    }

    /**
     * O mesmo banco de `saldosDoBancoPorCompetencia`, com a jornada informada. A tela usa sempre `jornadaDaFolha`; outra
     * jornada só serve para comparar com a regra de antes da E17 (`app:ponto:banco:comparar-virada`, as versões cruas).
     *
     * @param Feriado[] $feriados
     * @return array<string, int>
     */
    public function saldosDoBancoComJornada(User $user, Tenant $tenant, JornadaColaborador|HistoricoDeJornadas|null $jornada, array $feriados, ?JornadaTenant $jornadaTenant, ?\DateTimeInterface $inicioContagem, \DateTimeImmutable $hoje): array
    {
        $saldos = $this->saldosDaFolhaPorCompetencia($user, $tenant, $jornada, $feriados, $jornadaTenant, $inicioContagem, $hoje->setTime(0, 0));
        foreach ($this->lancamentoHorasPagasRepository->somarPorCompetenciaDoColaborador($user, $tenant) as $competencia => $minutos) {
            $saldos[$competencia] = ($saldos[$competencia] ?? 0) + $minutos;
        }
        ksort($saldos, SORT_STRING);

        return $saldos;
    }

    /**
     * O saldo da folha de cada mês, do mês do início da contagem até o mês de `$hoje`. Sem jornada ou sem nenhum
     * registro de ponto, nada conta. Cada mês é a folha do mês, a mesma de `buildRows`: o banco é a soma do "Saldo mês"
     * de cada competência.
     *
     * @param Feriado[] $feriados
     * @return array<string, int>
     */
    private function saldosDaFolhaPorCompetencia(User $user, Tenant $tenant, JornadaColaborador|HistoricoDeJornadas|null $jornada, array $feriados, ?JornadaTenant $jornadaTenant, ?\DateTimeInterface $inicioContagem, \DateTimeImmutable $hoje): array
    {
        $inicio = $inicioContagem === null ? null : \DateTimeImmutable::createFromInterface($inicioContagem)->setTime(0, 0, 0);
        if ($jornada === null || $inicio === null || $inicio > $hoje) {
            return [];
        }

        $batidasPorMes = $this->batidasPorMes($user, $tenant, $inicio, $hoje->setTime(23, 59, 59));
        $justificativas = $this->justificativaPontoRepository->findByUserEPeriodoIndexed($user, $tenant, $inicio, $hoje);

        $saldos = [];
        for ($mes = $inicio->modify('first day of this month'); $mes <= $hoje; $mes = $mes->modify('+1 month')) {
            $fimDoMes = $mes->modify('last day of this month');
            $rows = $this->buildRows(
                $inicio > $mes ? $inicio : $mes,
                ($hoje < $fimDoMes ? $hoje : $fimDoMes)->setTime(23, 59, 59),
                $batidasPorMes[$mes->format('Y-m')] ?? [],
                true,
                false,
                $jornada,
                $feriados,
                $justificativas,
                $jornadaTenant,
                $inicio,
                $hoje,
            );
            $saldos[$mes->format('Y-m')] = $this->saldoAcumuladoFinal($rows) ?? 0;
        }

        return $saldos;
    }

    /**
     * As batidas da pessoa NESTE escritório no período, numa consulta só, separadas por 'AAAA-MM'.
     *
     * @return array<string, list<RegistroPonto>>
     */
    private function batidasPorMes(User $user, Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        $porMes = [];
        foreach ($this->registroPontoRepository->batidasDoPeriodoNoEscritorio($user, $tenant, $inicio, $fim) as $batida) {
            $porMes[$batida->getDataHora()->format('Y-m')][] = $batida;
        }

        return $porMes;
    }

    /**
     * Soma, com sinal, os lançamentos de horas pagas do colaborador numa competência.
     * Público porque as telas precisam exibir a linha "Horas pagas" do mês exibido.
     */
    public function somarHorasPagasDaCompetencia(User $user, ?Tenant $tenant, int $ano, int $mes): int
    {
        if ($tenant === null) {
            return 0;
        }

        return $this->lancamentoHorasPagasRepository->somarPorCompetencia($user, $tenant, $ano, $mes);
    }
}
