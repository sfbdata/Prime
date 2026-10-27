<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\RetratoDoEngajamento;

/**
 * Os textos do banner, do lembrete diário e das boas-vindas pelo link (desenho 06 l.88 a 96, 1013 a 1023,
 * 1054 a 1061, 1105 a 1111; spec VS 18.10.26 §4.7), a partir do retrato já recortado. Classe pura: sem
 * banco, sem sessão. O horário é o de Brasília.
 */
final class EngajamentoDoTrabalhoExterno
{
    public const FUSO = 'America/Sao_Paulo';
    /** Currículo sem atualização há mais que isso pede revisão (l.1059). */
    private const DIAS_PARA_REVISAR = 30;

    /** "Bom dia/Boa tarde/Boa noite, {primeiro nome}" (`sau`, l.1020). */
    public function saudacao(string $nome, \DateTimeImmutable $agora): string
    {
        $hora = (int) $agora->setTimezone(new \DateTimeZone(self::FUSO))->format('G');
        $primeiro = explode(' ', trim($nome))[0];
        $cumprimento = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');

        return $primeiro === '' ? $cumprimento : $cumprimento . ', ' . $primeiro;
    }

    /**
     * A média, em minutos, do início da rodada ao primeiro aceite de cada oferta (`tMed`, l.1016 e 1018);
     * null sem aceite. O chip do banner e o KPI "OFERTAS COM TEMPO" do painel usam a mesma conta.
     *
     * @param list<array{inicio: \DateTimeImmutable, aceite: \DateTimeImmutable}> $aceites
     */
    public static function minutosAteOAceite(array $aceites): ?float
    {
        if ($aceites === []) {
            return null;
        }
        $segundos = array_map(static fn (array $a): int => max(0, $a['aceite']->getTimestamp() - $a['inicio']->getTimestamp()), $aceites);

        return array_sum($segundos) / count($segundos) / 60;
    }

    /** "2,5" de 2,46 minutos: uma casa, vírgula, sem o ",0" (`String(tMed).replace('.', ',')`). */
    public static function minutos(float $minutos): string
    {
        return str_replace('.', ',', (string) round($minutos, 1));
    }

    public function subtitulo(bool $interno): string
    {
        return $interno ? 'Veja o que aconteceu na Central desde a sua última visita.' : 'Oportunidades para você na rede BlueJus.';
    }

    /**
     * Os chips do banner (`chipsI`/`chipsE`, l.1021 e 1022). `ir` diz à tela que aba abrir, com filtro
     * ou ação; null quando o chip só informa.
     *
     * @return list<array{ic: string, cor: string, txt: string, ir: array<string, string>|null}>
     */
    public function chips(RetratoDoEngajamento $r): array
    {
        return array_values(array_filter($r->interno ? $this->chipsDoInterno($r) : $this->chipsDoExterno($r)));
    }

    /** @return list<array<string, mixed>|null> */
    private function chipsDoInterno(RetratoDoEngajamento $r): array
    {
        $aoVivo = count($r->ofertasAoVivo);

        return [
            $r->talentos && $r->pendentes > 0 ? self::chip('bi-inbox', '#9a5b00', $r->pendentes . ' pedido(s) de divulgação de vaga para aprovar', ['mod' => 'vagas', 'filtro' => 'pendente']) : null,
            $aoVivo > 0 ? self::chip('bi-broadcast', '#1e7b45', $aoVivo . ' oferta(s) ao vivo · a próxima termina em ' . self::mmss($r->ofertasAoVivo[0], $r->agora), ['mod' => 'vagas', 'filtro' => 'aberta']) : null,
            $r->curriculosNaSemana > 0 ? self::chip('bi-person-plus', '#0a6fa0', $r->curriculosNaSemana . ' currículo(s) novo(s) nesta semana', ['mod' => 'cv', 'filtro' => 'novo']) : null,
            $r->candidaturasNaSemana > 0 ? self::chip('bi-send-check', '#5b3f9e', $r->candidaturasNaSemana . ' candidatura(s) e aceite(s) na semana', ['mod' => 'vagas', 'filtro' => 'todas']) : null,
            $r->minutosAteOAceite !== null ? self::chip('bi-stopwatch', '#c76a12', 'Ofertas são aceitas em ' . self::minutos($r->minutosAteOAceite) . ' min, em média', null) : null,
            $r->totalVagas === 0 ? self::chip('bi-megaphone', '#0a6fa0', 'Publique a primeira vaga ou oferta com tempo', $r->podeCriar ? ['mod' => 'vagas', 'acao' => 'novaVaga'] : null) : null,
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function chipsDoExterno(RetratoDoEngajamento $r): array
    {
        $aoVivo = count($r->ofertasAoVivo);
        $p = $r->percentual;

        return [
            $r->termoParaAceitar ? self::chip('bi-file-earmark-check', '#9a5b00', 'Você tem um termo de voluntariado para aceitar', ['mod' => 'cv', 'acao' => 'abrirTermo']) : null,
            $aoVivo > 0 ? self::chip('bi-broadcast', '#1e7b45', $aoVivo . ' vaga(s) disputada(s) disponível(is) agora', ['mod' => 'vagas']) : null,
            $r->novasDesdeAVisita > 0 ? self::chip('bi-stars', '#0a6fa0', $r->novasDesdeAVisita . ' vaga(s) nova(s) desde sua última visita', ['mod' => 'vagas']) : null,
            match (true) {
                $p === null => self::chip('bi-person-vcard', '#c76a12', 'Cadastre seu currículo para aceitar ofertas em 1 clique', ['mod' => 'cv', 'acao' => 'editarCv']),
                $p['pct'] < 100 => self::chip('bi-graph-up-arrow', '#c76a12', 'Currículo ' . $p['pct'] . '% completo · falta ' . implode(' e ', array_slice($p['faltas'], 0, 2)), ['mod' => 'cv', 'acao' => 'editarCv']),
                default => self::chip('bi-patch-check', '#1e7b45', 'Currículo 100% completo · você aparece primeiro nas buscas', null),
            },
        ];
    }

    /**
     * O lembrete diário do externo (`dia`, l.1057 a 1060): cadastrar, completar, revisar (mais de 30 dias
     * sem atualizar) ou em dia. Os textos são da tela; aqui vão o estado e os números.
     *
     * @return array{estado: string, pct: int, faltas: list<string>, dias: int, vagasAbertas: int}
     */
    public function lembrete(RetratoDoEngajamento $r): array
    {
        $p = $r->percentual;
        $dias = $r->atualizadoEm !== null ? (int) round(($r->agora->getTimestamp() - $r->atualizadoEm->getTimestamp()) / 86400) : 0;
        $estado = match (true) {
            $p === null => 'cadastrar',
            $p['pct'] < 100 => 'completar',
            $dias > self::DIAS_PARA_REVISAR => 'revisar',
            default => 'emDia',
        };

        return ['estado' => $estado, 'pct' => $p['pct'] ?? 0, 'faltas' => $p['faltas'] ?? [], 'dias' => $dias, 'vagasAbertas' => $r->abertas];
    }

    /**
     * As boas-vindas de quem chegou pelo link de vagas (`bvTit`, `bvTxt`, `bvPassos`, `bvBtn`, `bvIr`,
     * l.1111). `$indicadas` são os códigos do link que continuam abertos para a pessoa.
     *
     * @param list<string> $indicadas
     *
     * @return array{titulo: string, texto: string, passos: list<array{rot: string, ic: string, feito: bool}>, botao: array<string, string>}
     */
    public function boasVindas(RetratoDoEngajamento $r, bool $cadastro, array $indicadas): array
    {
        $temCv = $r->percentual !== null;

        return [
            'titulo' => $cadastro ? 'Bem-vindo ao BlueJus!' : 'Que bom ter você de volta!',
            'texto' => $this->textoDasBoasVindas(count($indicadas), $r->abertas > 0),
            'passos' => [
                ['rot' => 'Cadastre seu currículo', 'ic' => 'bi-person-vcard', 'feito' => $temCv],
                ['rot' => 'Candidate-se às vagas', 'ic' => 'bi-send', 'feito' => $temCv && $r->candidatou],
                ['rot' => 'Ative os avisos de vaga', 'ic' => 'bi-bell', 'feito' => $r->alertaLigado],
            ],
            'botao' => $this->botaoDasBoasVindas($temCv, $indicadas, $r->abertas > 0),
        ];
    }

    private function textoDasBoasVindas(int $indicadas, bool $haAbertas): string
    {
        if ($indicadas > 0) {
            return ($indicadas === 1 ? 'A vaga que indicaram para você continua aberta.' : $indicadas . ' vagas que indicaram para você continuam abertas.')
                . ' Cadastre seu currículo uma vez e candidate-se em 1 clique.';
        }

        return $haAbertas
            ? 'As vagas que te indicaram já foram preenchidas, mas há outras abertas agora. Cadastre seu currículo e ative os avisos para não perder as próximas.'
            : 'As vagas que te indicaram já foram preenchidas. Cadastre seu currículo e ative os avisos para receber as próximas.';
    }

    /**
     * @param list<string> $indicadas
     *
     * @return array<string, string> `acao`: cadastrarCv (com a vaga a candidatar depois), abrirVaga ou verVagas
     */
    private function botaoDasBoasVindas(bool $temCv, array $indicadas, bool $haAbertas): array
    {
        $primeira = $indicadas[0] ?? null;
        if (!$temCv) {
            $botao = ['rot' => $primeira !== null || $haAbertas ? 'Cadastrar currículo e me candidatar' : 'Cadastrar meu currículo', 'acao' => 'cadastrarCv'];

            return $primeira !== null ? $botao + ['codigo' => $primeira] : $botao;
        }
        if ($primeira !== null) {
            return ['rot' => 'Ver a vaga indicada', 'acao' => 'abrirVaga', 'codigo' => $primeira];
        }

        return ['rot' => $haAbertas ? 'Ver vagas abertas' : 'Ver a Central de vagas', 'acao' => 'verVagas'];
    }

    /** @param array<string, string>|null $ir */
    private static function chip(string $ic, string $cor, string $txt, ?array $ir): array
    {
        return ['ic' => $ic, 'cor' => $cor, 'txt' => $txt, 'ir' => $ir];
    }

    /** `Component.mmss` (l.842): o que falta, em minutos e segundos, com os segundos arredondados para cima. */
    private static function mmss(\DateTimeImmutable $fim, \DateTimeImmutable $agora): string
    {
        $t = max(0, (int) ceil((float) $fim->format('U.u') - (float) $agora->format('U.u')));

        return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
    }
}
