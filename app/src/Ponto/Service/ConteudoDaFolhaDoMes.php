<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\CabecalhoDaFolha;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\ResumoDoMesDaFolha;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\UserTenantRepository;

/**
 * Monta a folha do mês da pessoa NESTE escritório, no texto que o PDF da E16 desenha ({@see ConteudoDaFolha}), sem
 * carimbo e sem validação (quem emite põe os dois). Os passos são os da exportação de hoje
 * (`PontoController::exportarFolhaPdf`): as batidas, os feriados e as justificativas do mês, a jornada da folha (E17),
 * o início da contagem e o {@see DadosDaFolhaExportada}; tudo com o escritório escrito à mão, nunca o da sessão.
 *
 * `$hoje` é o dia de referência (o relógio de quem chama): o dia depois dele não tem conta e sai com as horas vazias.
 */
final class ConteudoDaFolhaDoMes
{
    /** Os mínimos de quem não tem jornada do escritório, os mesmos de {@see DadosDaFolhaExportada}. */
    private const MINIMO_INTRAJORNADA = 60;
    private const MINIMO_INTERJORNADA = 660;

    public function __construct(
        private readonly RegistroPontoRepository $registros,
        private readonly FeriadoRepository $feriados,
        private readonly JustificativaPontoRepository $justificativas,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly UserTenantRepository $vinculos,
        private readonly InicioContagemResolver $inicioDaContagem,
        private readonly FolhaPontoBuilder $builder,
        private readonly DadosDaFolhaExportada $dadosDaFolha,
        private readonly LinhasDaFolhaImpressa $impressao,
    ) {
    }

    public function montar(Tenant $escritorio, User $pessoa, int $ano, int $mes, \DateTimeImmutable $hoje): ConteudoDaFolha
    {
        $inicioDoMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimDoMes = $inicioDoMes->modify('last day of this month')->setTime(23, 59, 59);
        $feriados = $this->feriados->findByTenant($escritorio);
        $justificativas = $this->justificativas->findByUserAndCompetencia($pessoa, $escritorio, $ano, $mes);
        $inicioDaContagem = $this->inicioDaContagem->resolver($pessoa, $escritorio);
        $jornadaDoEscritorio = $escritorio->getJornadaTenant();

        $linhas = $this->builder->buildRows(
            $inicioDoMes,
            $fimDoMes,
            $this->registros->findByUserAndCompetencia($pessoa, $escritorio, $ano, $mes),
            true,
            false,
            $this->builder->jornadaDaFolha($pessoa, $escritorio),
            $feriados,
            $this->justificativas->indexarPorDia($justificativas),
            $jornadaDoEscritorio,
            $inicioDaContagem,
            $hoje,
        );
        $jornadaDoCabecalho = $this->jornadas->historicoDe($pessoa, $escritorio)?->vigenteEm($fimDoMes);
        $dados = $this->dadosDaFolha->montar($pessoa, $escritorio, $ano, $mes, $linhas, $feriados, $jornadaDoCabecalho, $jornadaDoEscritorio, $inicioDaContagem);

        return $this->conteudo($escritorio, $pessoa, $ano, $mes, $linhas, $justificativas, $dados);
    }

    /**
     * @param array<int, array<string, mixed>>         $linhas
     * @param \App\Ponto\Entity\JustificativaPonto[] $justificativas
     * @param array<string, mixed>                     $dados
     */
    private function conteudo(Tenant $escritorio, User $pessoa, int $ano, int $mes, array $linhas, array $justificativas, array $dados): ConteudoDaFolha
    {
        $jornadaDoEscritorio = $escritorio->getJornadaTenant();
        $minimoIntrajornada = $jornadaDoEscritorio?->getMinimoMinutosRepouso() ?? self::MINIMO_INTRAJORNADA;
        $minimoInterjornada = $jornadaDoEscritorio?->getMinimoMinutosInterjornada() ?? self::MINIMO_INTERJORNADA;
        $impressas = $this->impressao->imprimir($linhas, $justificativas);

        return new ConteudoDaFolha(
            $ano,
            $mes,
            CabecalhoDaFolha::daFolhaExportada($dados, $this->codigoDoFuncionario($escritorio, $pessoa), $minimoIntrajornada, $minimoInterjornada),
            $impressas,
            ResumoDoMesDaFolha::daFolhaExportada($dados, $impressas),
            $this->impressao->intervalos($linhas, $minimoIntrajornada, $minimoInterjornada),
        );
    }

    /** O código do vínculo; sem ele, vazio (decisão 9: nunca o próximo código livre do escritório). */
    private function codigoDoFuncionario(Tenant $escritorio, User $pessoa): string
    {
        return (string) $this->vinculos->findAtivoPorUserETenant($pessoa, $escritorio)?->getCodigoFuncionario();
    }
}
