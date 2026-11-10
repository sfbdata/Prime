<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Repository\UserTenantRepository;
use App\Tenant\UseCase\GerarCodigoFuncionario;

/**
 * Dados da folha de ponto exportada (PDF e XLSX): empregador, empregado, jornada, linhas do mês
 * formatadas, resumo do banco de horas e controle de intervalos. Saiu do `PontoController` (E16,
 * bloco A) sem mudar o que monta: o array de saída é o mesmo que o `folha_pdf.html.twig` e o
 * `FolhaPontoXlsxExporter` já recebiam.
 */
final class DadosDaFolhaExportada
{
    /** Dias seguidos com registro que o descanso semanal da folha aceita (desenho L693: `maxRun <= 6`). */
    private const DIAS_SEGUIDOS_SEM_DESCANSO = 6;

    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly GerarCodigoFuncionario $gerarCodigo,
        private readonly FolhaPontoBuilder $builder,
    ) {
    }

    /**
     * Monta o array completo de dados para os exportadores de PDF e XLSX.
     *
     * @param Feriado[] $feriados
     * @param array<int, array<string, mixed>> $folhaRows
     * @return array<string, mixed>
     */
    public function montar(
        User $targetUser,
        Tenant $tenant,
        int $ano,
        int $mes,
        array $folhaRows,
        array $feriados,
        ?JornadaColaborador $jornada,
        ?JornadaTenant $jornadaTenant,
        ?\DateTimeInterface $inicioContagem,
    ): array {
        $profile    = $targetUser->getProfile();
        $userTenant = $this->userTenantRepository->findAtivoPorUserETenant($targetUser, $tenant);
        $cargo      = $userTenant?->getCargo();
        $lotacao    = $userTenant?->getLotacao();

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $horarios = $this->resolverHorariosJornada($jornada, $jornadaTenant);

        $minutosSemana = $jornada?->getCargaHorariaSemanal() ?? $jornadaTenant?->getCargaHorariaSemanal() ?? 0;
        $horasSemana = (int) round($minutosSemana / 60);

        $minimoRepouso      = $jornadaTenant?->getMinimoMinutosRepouso()      ?? 60;
        $minimoInterjornada = $jornadaTenant?->getMinimoMinutosInterjornada()  ?? 660;

        $rowsProcessados         = [];
        $totalMinutosTrabalhados = 0;
        $totalMinutosExtras      = 0;
        $feriadosTrabalhados     = 0;
        $finaisSemanasTrabalhados = 0;
        $intrajornadaConforme    = true;
        $interjornadaConforme    = true;
        $ultimaSaidaTs           = null;
        // Saldo DAQUELE MÊS: `buildRows` faz o acumulado nascer em zero no dia 1º do intervalo
        // pedido. É uma das três parcelas do "Saldo do Banco de Horas Atual" montado adiante.
        $saldoDoMesMinutos       = null;

        foreach ($folhaRows as $row) {
            $row['horasTrabalhadas'] = $this->formatarMinutos($row['minutosTrabalhadosDia'] ?? null);
            $row['horasExtras']      = ($row['saldoDia'] ?? 0) > 0 ? $this->formatarMinutos($row['saldoDia']) : '0:00';
            $row['bancoHoras']       = $this->formatarSaldo($row['saldoAcumulado'] ?? null);
            $rowsProcessados[]       = $row;

            $totalMinutosTrabalhados += ($row['minutosTrabalhadosDia'] ?? 0);

            if (($row['saldoDia'] ?? 0) > 0) {
                $totalMinutosExtras += $row['saldoDia'];
            }

            if (!empty($row['isFeriado']) && $row['entrada'] !== '') {
                $feriadosTrabalhados++;
            }

            if (!empty($row['fimSemana']) && $row['entrada'] !== '') {
                $finaisSemanasTrabalhados++;
            }

            if ($row['minutosIntervalo'] !== null && ($row['minutosTrabalhadosDia'] ?? 0) > 0) {
                if ($row['minutosIntervalo'] < $minimoRepouso) {
                    $intrajornadaConforme = false;
                }
            }

            if ($row['entrada'] !== '' && $ultimaSaidaTs !== null) {
                $entradaDt = new \DateTimeImmutable($row['chaveDia'] . ' ' . $row['entrada']);
                $diffMin = (int)(($entradaDt->getTimestamp() - $ultimaSaidaTs) / 60);
                if ($diffMin < $minimoInterjornada) {
                    $interjornadaConforme = false;
                }
            }

            if ($row['saida'] !== '') {
                $saidaDt = new \DateTimeImmutable($row['chaveDia'] . ' ' . $row['saida']);
                $ultimaSaidaTs = $saidaDt->getTimestamp();
            }

            if ($row['saldoAcumulado'] !== null) {
                $saldoDoMesMinutos = $row['saldoAcumulado'];
            }
        }

        // O banco de horas NÃO zera mais em 1º de janeiro (E17, spec ponto-desenho-0811, P51 a; até então zerava, regra
        // do dono de 2026-07-31). O "anterior" é o banco até o fim do mês passado, atravessando o ano: em janeiro, até
        // dezembro do ano anterior. `calcularSaldoAteMes` soma as mesmas competências dos lotes da aba "Banco de Horas",
        // então o papel assinado e a tela dão o mesmo número.
        // Guarda própria, não herdada: hoje os dois chamadores (`:789` e `:873`) já fazem
        // `max(1, min(12, ...))`, mas o mês anterior abaixo depende disso silenciosamente. Um chamador
        // novo que esquecesse o clamp levaria `mes = 0` a somar período vazio e devolver ZERO SEM
        // BARULHO — número errado num documento assinado —, e `mes = 13` a estourar 500 lá dentro.
        if ($mes < 1 || $mes > 12) {
            throw new \InvalidArgumentException(sprintf('montarDadosFolha: mês fora de 1..12 (%d)', $mes));
        }

        // NÃO condiciona em $jornada de propósito: `calcularSaldoAteMes` monta a jornada sozinho, a mesma da
        // folha da /ponto (`FolhaPontoBuilder::jornadaDaFolha`, E17: a padrão do escritório para quem não tem
        // JornadaColaborador), e curto-circuitar ali apagaria o banco de quem não tem jornada pessoal: o
        // painel /ponto mostraria -10h00 e o espelho do mês seguinte "Saldo anterior: 0h00", para o mesmo
        // colaborador.
        $mesAnterior = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))->modify('-1 month');
        $saldoBancoAnteriorMinutos = $this->builder->calcularSaldoAteMes(
            $targetUser,
            (int) $mesAnterior->format('Y'),
            (int) $mesAnterior->format('n'),
            $feriados,
            $jornadaTenant,
            $inicioContagem,
            $tenant,
        );

        $horasPagasMinutos = $this->builder->somarHorasPagasDaCompetencia($targetUser, $tenant, $ano, $mes);

        // DECISÃO DO DONO (2026-07-31, após o smoke): "Saldo do Banco de Horas Atual" passa a ser o
        // banco ACUMULADO de verdade — anterior + saldo do mês + horas pagas da competência — e o
        // bloco assinado deixa de ter linha própria "Horas pagas".
        //
        // Até aqui este campo era o `saldoAcumulado` da última linha da tabela, e `buildRows` faz
        // esse acumulado NASCER EM ZERO no dia 1º do intervalo pedido: apesar do rótulo, o número era
        // o saldo DAQUELE MÊS. Ficava ao lado de "Saldo do Banco de Horas Anterior", que sempre foi
        // acumulado (`calcularSaldoAteMes`) — dois rótulos irmãos medindo coisas diferentes.
        //
        // É essa base errada que tornava a soma perigosa: colaborador acumula 100h, recebe as 100h em
        // dinheiro, o admin lança -6000 em agosto, agosto é trabalhado na jornada exata (saldo do mês
        // = 0) — somar o lançamento a um saldo MENSAL dizia "Horas a Compensar: 100:00", cobrando de
        // volta o que acabou de ser pago. Com a base acumulada o mesmo caso fecha em zero:
        // +100h (anterior) + 0 (mês) - 100h (pago) = 0.
        //
        // Sem contagem dupla: `calcularSaldoAteMes` soma os lançamentos das competências ANTERIORES;
        // `somarHorasPagasDaCompetencia` soma os DESTA. As faixas não se sobrepõem.
        //
        // ⚠️ Muda o PDF de TODOS os escritórios, inclusive os que nunca usaram horas pagas — o campo
        // deixa de ser mensal e passa a ser acumulado. Consequência aceita e explícita da decisão.
        //
        // `$saldoDoMesMinutos` é null quando NENHUMA linha teve saldo apurado. NÃO confundir com "mês
        // sem batida": dia útil sem batida dentro da contagem recebe `saldoDia` negativo normalmente
        // (`CalculadoraJornada::calcularSaldoDia` devolve `trabalhado - carga`), então um mês inteiro
        // de faltas sai como número, não como null. Os gatilhos reais são: colaborador sem
        // `JornadaColaborador` (`buildRows` não calcula saldo em dia nenhum), mês inteiramente FUTURO,
        // mês inteiro anterior ao início da contagem, e o caso "só hoje, ainda sem saída".
        //
        // Nesses casos o mês é DESCONHECIDO, não zero, e o documento assinado não pode afirmar "+0:00"
        // a partir do nada: mantém o '–' de antes. Mas só quando não há mais nada a reportar — havendo
        // saldo anterior ou lançamento, o total sai, porque perder horas em silêncio é pior (spec §4.3).
        $nadaApurado = $saldoDoMesMinutos === null && $saldoBancoAnteriorMinutos === 0 && $horasPagasMinutos === 0;

        $saldoBancoAtualMinutos = $nadaApurado
            ? null
            : $saldoBancoAnteriorMinutos + ($saldoDoMesMinutos ?? 0) + $horasPagasMinutos;

        $enderecoPartes = array_filter([
            $tenant->getLogradouro(),
            $tenant->getNumero() ? ', ' . $tenant->getNumero() : null,
            $tenant->getComplemento() ? ', ' . $tenant->getComplemento() : null,
        ]);
        $cidadeEstado = trim(
            ($tenant->getBairro() ? $tenant->getBairro() . ', ' : '')
            . ($tenant->getCidade() ?? '')
            . ($tenant->getEstado() ? '/' . $tenant->getEstado() : '')
        );
        $cep = $tenant->getCep() ? ' CEP ' . $tenant->getCep() : '';

        $distribuicao = $this->gerarDescricaoDiasExtenso($jornada, $jornadaTenant);
        $descrJornada = $this->gerarDescrJornada($jornada, $jornadaTenant);

        return [
            'folhaRows'               => $rowsProcessados,
            'mes'                     => $mes,
            'ano'                     => $ano,
            'anoAssinatura'           => $ano,
            'inicioMes'               => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))->format('d/m/Y'),
            'fimMes'                  => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))->modify('last day of this month')->format('d/m/Y'),
            'nomeUsuario'             => mb_strtoupper($nomeUsuario),
            'nomeEmpresa'             => mb_strtoupper($tenant->getName() ?? ''),
            'cnpj'                    => $this->formatarCnpj($tenant->getCnpj() ?? ''),
            'enderecoLinha1'          => implode('', $enderecoPartes),
            'enderecoLinha2'          => trim($cidadeEstado . $cep),
            'codigoFuncionario'       => $userTenant?->getCodigoFuncionario()
                ?? $this->gerarCodigo->executar($tenant),
            'cpf'                     => $this->formatarCpf($profile?->getCpf() ?? ''),
            'cargo'                   => mb_strtoupper($cargo?->getNome() ?? ''),
            'ctps'                    => $profile?->getCtps() ?? '',
            'serie'                   => $profile?->getSerie() ?? '',
            'lotacao'                 => ($lotacao !== null ? mb_strtoupper($lotacao->getNome()) : ''),
            'dataAdmissao'            => $userTenant?->getDataAdmissao()?->format('d/m/Y') ?? '',
            'jornadaSemanal'          => $horasSemana . 'h',
            'jornadaSemanalTexto'     => $horasSemana . 'h semanais',
            'horarioContratual'       => $horarios['entrada'] . ' às ' . $horarios['saida'],
            'intervaloTexto'          => $horarios['repouso'] . ' às ' . $horarios['retorno'],
            'distribuicaoJornada'     => $distribuicao,
            'escalaDescricao'         => $distribuicao . ($descrJornada !== '' ? ' (' . $descrJornada . ')' : ''),
            'minimoRepousoTexto'      => $this->formatarDuracaoHora($minimoRepouso),
            'minimoInterjornadaTexto' => $this->formatarDuracaoHora($minimoInterjornada),
            'totalHorasTrabalhadas'   => $this->formatarMinutos($totalMinutosTrabalhados ?: null),
            'totalHorasExtras'        => $this->formatarMinutos($totalMinutosExtras ?: null),
            'saldoBancoAnterior'      => $this->formatarSaldo($saldoBancoAnteriorMinutos),
            'saldoBancoAtual'         => $this->formatarSaldo($saldoBancoAtualMinutos),
            'horasACompensar'         => match (true) {
                $saldoBancoAtualMinutos === null => 'Sem dado',
                $saldoBancoAtualMinutos < 0      => $this->formatarMinutos(abs($saldoBancoAtualMinutos)),
                default                          => '0:00',
            },
            'feriadosTrabalhados'     => $feriadosTrabalhados,
            'finaisSemanasTrabalhados' => $finaisSemanasTrabalhados,
            'intrajornadaConforme'    => $intrajornadaConforme,
            'interjornadaConforme'    => $interjornadaConforme,
            // E11 (desenho L693): conforme até 6 dias seguidos com registro no mês; antes era "Conforme" fixo.
            'descansoSemanalConforme' => self::maiorSequenciaDeDiasComRegistro($folhaRows) <= self::DIAS_SEGUIDOS_SEM_DESCANSO,
            'responsavelAssinatura'   => $tenant->getResponsavelAssinatura() ?? '',
            // retrocompatibilidade com campos usados pela visualização da folha na tela
            'numeroTrabalhador' => $userTenant?->getCodigoFuncionario()
                ?? $this->gerarCodigo->executar($tenant),
            'descrJornada'  => $descrJornada,
            'horaEntrada'   => $horarios['entrada'] . ' h',
            'horaRepouso'   => $horarios['repouso'] . ' h',
            'horaRetorno'   => $horarios['retorno'] . ' h',
            'horaSaida'     => $horarios['saida'] . ' h',
        ];
    }

    private function formatarMinutos(int|null $minutos): string
    {
        // Zero hora é dado (nada a compensar, nenhuma extra), não ausência: sai como 0:00 na folha.
        if ($minutos === null || $minutos === 0) {
            return '0:00';
        }

        $abs = abs($minutos);

        return sprintf('%d:%02d', intdiv($abs, 60), $abs % 60);
    }

    private function formatarSaldo(int|null $minutos): string
    {
        if ($minutos === null) {
            return 'Sem dado';
        }

        $abs  = abs($minutos);
        $hhmm = sprintf('%d:%02d', intdiv($abs, 60), $abs % 60);

        return $minutos >= 0 ? '+' . $hhmm : '-' . $hhmm;
    }

    private function formatarCnpj(string $cnpj): string
    {
        $raw = preg_replace('/\D/', '', $cnpj) ?? '';
        if (strlen($raw) === 14) {
            return sprintf('%s.%s.%s/%s-%s', substr($raw, 0, 2), substr($raw, 2, 3), substr($raw, 5, 3), substr($raw, 8, 4), substr($raw, 12, 2));
        }

        return $cnpj;
    }

    private function formatarCpf(string $cpf): string
    {
        $raw = preg_replace('/\D/', '', $cpf) ?? '';
        if (strlen($raw) === 11) {
            return sprintf('%s.%s.%s-%s', substr($raw, 0, 3), substr($raw, 3, 3), substr($raw, 6, 3), substr($raw, 9, 2));
        }

        return $cpf;
    }

    private function formatarDuracaoHora(int $minutos): string
    {
        $horas = $minutos / 60;
        if ($horas === floor($horas)) {
            return ((int) $horas) . 'h';
        }

        return sprintf('%dh%02d', intdiv($minutos, 60), $minutos % 60);
    }

    private function gerarDescricaoDiasExtenso(
        ?JornadaColaborador $jornada,
        ?JornadaTenant $jornadaTenant,
    ): string {
        $mapa = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $dias = $this->getDiasSemana($jornada, $jornadaTenant);

        if (empty($dias)) {
            return '';
        }

        sort($dias);

        if (count($dias) === 1) {
            return $mapa[$dias[0]] ?? '';
        }

        $isConsecutivo = true;
        for ($i = 1; $i < count($dias); $i++) {
            if ($dias[$i] - $dias[$i - 1] !== 1) {
                $isConsecutivo = false;
                break;
            }
        }

        if ($isConsecutivo) {
            return ($mapa[$dias[0]] ?? '') . ' a ' . ($mapa[$dias[count($dias) - 1]] ?? '');
        }

        return implode(', ', array_map(static fn (int $d) => $mapa[$d] ?? '', $dias));
    }

    /** @return int[] */
    private function getDiasSemana(
        ?JornadaColaborador $jornada,
        ?JornadaTenant $jornadaTenant,
    ): array {
        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornada->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }

            return array_keys($dias);
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }

            return array_keys($dias);
        }

        if ($jornada !== null) {
            return $jornada->getDiasSemana();
        }

        return [];
    }

    /**
     * Resolve os horários de jornada usando a mesma hierarquia do JornadaResolver:
     * 1. Blocos do usuário (primeiro dia útil encontrado)
     * 2. Blocos do tenant (primeiro dia útil encontrado)
     * 3. Campos planos da JornadaColaborador (legado)
     * 4. Defaults fixos
     *
     * @return array{entrada: string, repouso: string, retorno: string, saida: string}
     */
    private function resolverHorariosJornada(
        ?JornadaColaborador $jornada,
        ?JornadaTenant $jornadaTenant,
    ): array {
        $diasUteis = [1, 2, 3, 4, 5];

        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            foreach ($jornada->getBlocos() as $bloco) {
                foreach ($diasUteis as $dia) {
                    if (in_array($dia, $bloco->getDiasSemana(), true)) {
                        return [
                            'entrada' => $bloco->getEntrada(),
                            'repouso' => $bloco->getRepouso() ?? '',
                            'retorno' => $bloco->getRetorno() ?? '',
                            'saida'   => $bloco->getSaida(),
                        ];
                    }
                }
            }
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                foreach ($diasUteis as $dia) {
                    if (in_array($dia, $bloco->getDiasSemana(), true)) {
                        return [
                            'entrada' => $bloco->getEntrada(),
                            'repouso' => $bloco->getRepouso() ?? '',
                            'retorno' => $bloco->getRetorno() ?? '',
                            'saida'   => $bloco->getSaida(),
                        ];
                    }
                }
            }
        }

        if ($jornada !== null) {
            return [
                'entrada' => $jornada->getEntrada1() ?? '08:00',
                'repouso' => $jornada->getSaida1()   ?? '12:00',
                'retorno' => $jornada->getEntrada2() ?? '13:00',
                'saida'   => $jornada->getSaida2()   ?? '17:00',
            ];
        }

        return ['entrada' => '08:00', 'repouso' => '12:00', 'retorno' => '13:00', 'saida' => '17:00'];
    }

    /**
     * Gera a descrição da jornada (ex: "STQQS") a partir dos dias de trabalho.
     * Usa a mesma hierarquia do JornadaResolver: blocos do colaborador > blocos do tenant > jornada plana.
     */
    private function gerarDescrJornada(
        ?JornadaColaborador $jornada,
        ?JornadaTenant $jornadaTenant,
    ): string {
        $mapa = [1 => 'S', 2 => 'T', 3 => 'Q', 4 => 'Q', 5 => 'S', 6 => 'S', 7 => 'D'];

        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornada->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }
            ksort($dias);

            return implode('', array_map(static fn (int $d) => $mapa[$d] ?? '', array_keys($dias)));
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }
            ksort($dias);

            return implode('', array_map(static fn (int $d) => $mapa[$d] ?? '', array_keys($dias)));
        }

        if ($jornada !== null) {
            $dias = $jornada->getDiasSemana();
            sort($dias);

            return implode('', array_map(static fn (int $d) => $mapa[$d] ?? '', $dias));
        }

        return '';
    }

    /**
     * A maior sequência de dias seguidos com algum registro na folha do mês (desenho L626: `run = algum ? run + 1 : 0`),
     * para o "Descanso Semanal Remunerado" do XLSX.
     *
     * @param array<int, array<string, mixed>> $linhas as linhas da folha, um dia por linha
     */
    private static function maiorSequenciaDeDiasComRegistro(array $linhas): int
    {
        $maior = 0;
        $seguidos = 0;
        foreach ($linhas as $linha) {
            $algum = array_filter([$linha['entrada'] ?? '', $linha['repouso'] ?? '', $linha['retorno'] ?? '', $linha['saida'] ?? ''], static fn ($h): bool => $h !== '') !== [];
            $seguidos = $algum ? $seguidos + 1 : 0;
            $maior = max($maior, $seguidos);
        }

        return $maior;
    }
}
