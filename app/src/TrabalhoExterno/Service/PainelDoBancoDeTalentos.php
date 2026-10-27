<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\RetratoDoPainel;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoAcademica;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Enum\StatusCurriculo;

/**
 * O painel do Master no banco de talentos (desenho 06 l.171 a 194 e 1120 a 1146; spec VS 18.10.26
 * §4.9): os 8 KPIs, os 6 gráficos de barras e as contagens do crescimento, a partir do retrato. Classe
 * pura: sem banco, sem sessão. Rótulos, ícones, cores e subtítulos são os do desenho, com as correções
 * da §4.9: "ativos" (status diferente de arquivado) é a base de todos os KPIs e barras de currículo
 * (lacuna 2); COM OAB conta e filtra advogado(a) e estagiário(a) com OAB (lacuna 1).
 *
 * `ir` diz à tela para onde o clique leva: `{mod: "cv", filtro: {cf?, cs?, ca?, cu?, busca?}}` (a Lista
 * com o filtro), `{mod: "vagas", vf}` ou `{acao: "campanha"}` (o modal da campanha por e-mail, §4.10).
 */
final class PainelDoBancoDeTalentos
{
    /** Subtítulo de ALERTAS POR E-MAIL, exato do desenho (l.1146): o clique abre a campanha (§4.10). */
    public const SUB_DOS_ALERTAS = '%d visita(s) pelo link · %d clique(s) em criar conta · clique para campanha';
    /** Subtítulo de VOLUNTÁRIOS (l.1146 e 1220): os termos mais recentes aceitos e aguardando aceite. */
    public const SUB_DOS_TERMOS = '%d termo(s) aceito(s) · %d aguardando';
    private const TOPO = 8;
    private const FUNIL = [StatusCandidatura::Recebida, StatusCandidatura::Analise, StatusCandidatura::Entrevista, StatusCandidatura::Aprovado];
    private const VOLUNTARIADO = [['sim', 'Aceita voluntário', '#a3235f'], ['talvez', 'Talvez', '#c76a12'], ['nao', 'Não aceita', '#6b8494']];

    /** @return array{kpis: list<array<string, mixed>>, barras: list<array<string, mixed>>, crescimento: array<string, array<string, int>|\stdClass>, inscritos: int} */
    public function montar(RetratoDoPainel $r): array
    {
        $ativos = array_values(array_filter($r->curriculos, static fn (Curriculo $cv): bool => $cv->getStatus() !== StatusCurriculo::Arquivado));

        return [
            'kpis' => array_merge($this->kpisDoBanco($r, $ativos), $this->kpisDasVagas($r)),
            'barras' => $this->barras($r, $ativos),
            'crescimento' => $this->crescimento($r),
            'inscritos' => $r->inscritos,
        ];
    }

    /**
     * @param list<Curriculo> $ativos
     *
     * @return list<array<string, mixed>>
     */
    private function kpisDoBanco(RetratoDoPainel $r, array $ativos): array
    {
        $desde = static fn (string $intervalo): int => count(array_filter($ativos, static fn (Curriculo $cv): bool => $cv->getRecebidoEm() > $r->agora->modify($intervalo)));
        $em = static fn (SituacaoAcademica ...$s): int => count(array_filter($ativos, static fn (Curriculo $cv): bool => in_array($cv->getSituacao(), $s, true)));
        $novos = count(array_filter($ativos, static fn (Curriculo $cv): bool => $cv->getStatus() === StatusCurriculo::Novo));
        $voluntarios = count(array_filter($ativos, static fn (Curriculo $cv): bool => $cv->texto('vol') === 'sim'));

        return [
            self::kpi('ativos', 'CURRÍCULOS ATIVOS', 'bi-person-vcard', '#0a6fa0', count($ativos), $desde('-30 days') . ' nos últimos 30 dias', self::lista(['cf' => 'ativos'])),
            self::kpi('novos', 'NOVOS NA SEMANA', 'bi-person-plus', '#0a6fa0', $desde('-7 days'), $novos . ' ainda não analisados', self::lista(['cf' => 'novo'])),
            self::kpi('oab', 'COM OAB', 'bi-award', '#5b3f9e', $em(SituacaoAcademica::Adv, SituacaoAcademica::EstOab), $em(SituacaoAcademica::Est) . ' estudantes · ' . $em(SituacaoAcademica::Bach) . ' bacharéis', self::lista(['cs' => FiltroDeCurriculos::COM_OAB])),
            self::kpi('vol', 'VOLUNTÁRIOS', 'bi-heart', '#a3235f', $voluntarios, sprintf(self::SUB_DOS_TERMOS, $this->termosEm($r, 'aceito'), $this->termosEm($r, 'enviado')), self::lista(['cf' => 'vol'])),
        ];
    }

    /** Quantos currículos têm o termo mais recente neste estado (nunca CPF, RG nem nome). */
    private function termosEm(RetratoDoPainel $r, string $estado): int
    {
        return count(array_filter($r->termos, static fn (string $e): bool => $e === $estado));
    }

    /** @return list<array<string, mixed>> */
    private function kpisDasVagas(RetratoDoPainel $r): array
    {
        $abertas = count(array_filter($r->vagas, static fn (Vaga $v): bool => $v->situacaoEm($r->agora) === SituacaoVaga::Aberta));
        $divulgadas = array_filter($r->vagas, static fn (Vaga $v): bool => $v->getDivulgacoes() > 0);
        $divulgacoes = array_sum(array_map(static fn (Vaga $v): int => $v->getDivulgacoes(), $r->vagas));

        return [
            self::kpi('vagas', 'VAGAS ABERTAS', 'bi-megaphone', '#1e7b45', $abertas, count($r->candidaturas) . ' candidatura(s) no total', ['mod' => 'vagas', 'vf' => 'aberta']),
            self::kpi('alertas', 'ALERTAS POR E-MAIL', 'bi-envelope-paper', '#0a6fa0', $r->inscritos, sprintf(self::SUB_DOS_ALERTAS, $r->visitas, $r->cliques), ['acao' => 'campanha']),
            self::kpi('compartilhadas', 'VAGAS COMPARTILHADAS', 'bi-share', '#1e7b45', $divulgacoes, count($divulgadas) . ' vaga(s) divulgada(s)', ['mod' => 'vagas', 'vf' => 'todas']),
            $this->kpiDasOfertas($r),
        ];
    }

    /** OFERTAS COM TEMPO: % com aceite na rodada atual e a média até o primeiro aceite (`tMed`, l.1016). */
    private function kpiDasOfertas(RetratoDoPainel $r): array
    {
        $ofertas = array_values(array_filter($r->vagas, static fn (Vaga $v): bool => $v->isOferta()));
        $aceitas = count(array_filter($ofertas, static fn (Vaga $v): bool => ($r->aceitesPorVaga[(int) $v->getId()] ?? 0) > 0));
        $media = $r->minutosAteOAceite !== null ? ' · ' . EngajamentoDoTrabalhoExterno::minutos($r->minutosAteOAceite) . ' min em média' : '';
        $sub = $ofertas === [] ? 'nenhuma publicada' : (int) round($aceitas / count($ofertas) * 100) . '% aceitas' . $media;

        return self::kpi('ofertas', 'OFERTAS COM TEMPO', 'bi-stopwatch', '#c76a12', count($ofertas), $sub, ['mod' => 'vagas', 'vf' => 'todas']);
    }

    /**
     * Os 6 gráficos (`graf`, l.1123): top 8 por quantidade, "clique para filtrar".
     *
     * @param list<Curriculo> $ativos
     *
     * @return list<array<string, mixed>>
     */
    private function barras(RetratoDoPainel $r, array $ativos): array
    {
        return [
            ['tit' => 'Por área de atuação', 'ic' => 'bi-bookmark', 'cor' => '#0a6fa0', 'itens' => self::agrupar($ativos, static fn (Curriculo $cv): array => $cv->getAreas(), 'ca', '#0a6fa0')],
            ['tit' => 'Por situação acadêmica e OAB', 'ic' => 'bi-mortarboard', 'cor' => '#5b3f9e', 'itens' => self::agrupar($ativos, static fn (Curriculo $cv): array => [$cv->getSituacao()->value], 'cs', '#5b3f9e', static fn (string $k): string => SituacaoAcademica::from($k)->rotuloCurto())],
            ['tit' => 'Por estado (UF)', 'ic' => 'bi-geo-alt', 'cor' => '#1e7b45', 'itens' => self::agrupar($ativos, static fn (Curriculo $cv): array => [$cv->texto('uf')], 'cu', '#1e7b45')],
            ['tit' => 'Por cargo pretendido', 'ic' => 'bi-person-badge', 'cor' => '#c76a12', 'itens' => self::agrupar($ativos, static fn (Curriculo $cv): array => [$cv->texto('cargo')], 'busca', '#c76a12')],
            ['tit' => 'Funil das candidaturas', 'ic' => 'bi-funnel', 'itens' => $this->funil($r)],
            ['tit' => 'Voluntariado', 'ic' => 'bi-heart', 'itens' => $this->voluntariado($ativos)],
        ];
    }

    /**
     * `grp` (l.1121): conta cada chave (um currículo soma em todas as áreas dele), ignora a vazia, do
     * maior para o menor (o empate fica na ordem do banco) e corta no top 8.
     *
     * @param list<Curriculo>                      $ativos
     * @param callable(Curriculo): list<string>    $chaves
     * @param (callable(string): string)|null      $rotulo
     *
     * @return list<array{rot: string, n: int, cor: string, ir: array<string, mixed>}>
     */
    private static function agrupar(array $ativos, callable $chaves, string $filtro, string $cor, ?callable $rotulo = null): array
    {
        $contagem = [];
        foreach ($ativos as $cv) {
            foreach (array_unique(array_filter($chaves($cv), static fn (string $k): bool => $k !== '')) as $k) {
                $contagem[$k] = ($contagem[$k] ?? 0) + 1;
            }
        }
        arsort($contagem);
        $itens = [];
        foreach (array_slice($contagem, 0, self::TOPO, true) as $k => $n) {
            $k = (string) $k;
            $itens[] = ['rot' => $rotulo !== null ? $rotulo($k) : $k, 'n' => $n, 'cor' => $cor, 'ir' => self::lista([$filtro => $k])];
        }

        return $itens;
    }

    /** @return list<array{rot: string, n: int, cor: string, ir: array<string, string>}> */
    private function funil(RetratoDoPainel $r): array
    {
        $porStatus = array_count_values(array_column($r->candidaturas, 'status'));

        return array_map(static fn (StatusCandidatura $s): array => [
            'rot' => $s->rotulo(), 'n' => $porStatus[$s->value] ?? 0, 'cor' => $s->cor(), 'ir' => ['mod' => 'vagas', 'vf' => 'todas'],
        ], self::FUNIL);
    }

    /**
     * @param list<Curriculo> $ativos
     *
     * @return list<array{rot: string, n: int, cor: string, ir: array<string, mixed>}>
     */
    private function voluntariado(array $ativos): array
    {
        return array_map(static fn (array $v): array => [
            'rot' => $v[1],
            'n' => count(array_filter($ativos, static fn (Curriculo $cv): bool => $cv->texto('vol') === $v[0])),
            'cor' => $v[2],
            'ir' => $v[0] === 'sim' ? self::lista(['cf' => 'vol']) : ['mod' => 'cv'],
        ], self::VOLUNTARIADO);
    }

    /**
     * As contagens por mês ("AAAA-MM", horário de Brasília) das três séries do gráfico (l.1127): todos os
     * currículos recebidos (arquivados inclusive: é a história do banco), as candidaturas pelo envio e os
     * voluntários pelo recebimento. A tela monta Mensal, Anual e "Todos os anos" a partir delas.
     *
     * @return array{cvs: array<string, int>|\stdClass, cands: array<string, int>|\stdClass, vols: array<string, int>|\stdClass}
     */
    private function crescimento(RetratoDoPainel $r): array
    {
        $recebido = static fn (Curriculo $cv): \DateTimeImmutable => $cv->getRecebidoEm();

        return [
            'cvs' => self::porMes(array_map($recebido, $r->curriculos)),
            'cands' => self::porMes(array_column($r->candidaturas, 'enviadaEm')),
            'vols' => self::porMes(array_map($recebido, array_values(array_filter($r->curriculos, static fn (Curriculo $cv): bool => $cv->texto('vol') === 'sim')))),
        ];
    }

    /**
     * @param list<\DateTimeImmutable> $datas
     *
     * @return array<string, int>|\stdClass objeto vazio sem nenhuma data (no JSON, `{}` e não `[]`)
     */
    private static function porMes(array $datas): array|\stdClass
    {
        $fuso = new \DateTimeZone(EngajamentoDoTrabalhoExterno::FUSO);
        $meses = array_count_values(array_map(static fn (\DateTimeImmutable $d): string => $d->setTimezone($fuso)->format('Y-m'), $datas));
        ksort($meses);

        return $meses === [] ? new \stdClass() : $meses;
    }

    /** @param array<string, string> $filtro */
    private static function lista(array $filtro): array
    {
        return ['mod' => 'cv', 'filtro' => $filtro];
    }

    /** @param array<string, mixed> $ir */
    private static function kpi(string $k, string $rot, string $ic, string $cor, int $n, string $sub, array $ir): array
    {
        return ['k' => $k, 'rot' => $rot, 'ic' => $ic, 'cor' => $cor, 'n' => $n, 'sub' => $sub, 'ir' => $ir];
    }
}
