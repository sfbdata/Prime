<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\FiltroDoPainel;
use App\Voto\DTO\GrupoDaEntidade;
use App\Voto\DTO\KpisDoPainel;
use App\Voto\DTO\LinhaDoKpi;
use App\Voto\DTO\PainelAgrupado;
use App\Voto\DTO\PautaNoPainel;
use Symfony\Component\String\UnicodeString;

/**
 * Regras do painel da Gestão eleitoral (desenho 18, A7 e A8), sem acesso a banco: agrupa as assembleias por entidade
 * (nome sem acento e CNPJ, como o `ekey` do desenho), filtra por busca, entidade e situação, ordena os grupos (com
 * votação em andamento primeiro) e calcula os KPIs.
 *
 * Mapa de termos: "votação" do desenho é a pauta; "evento" é a assembleia. A situação da assembleia vem de
 * {@see AssembleiaNoPainel::situacao()}: o sistema apura ao encerrar, então "Concluídas" são as encerradas e não há
 * "aguardando apuração".
 */
final class PainelPorEntidade
{
    /** Ordem do desenho (ST): aberta, publicada, encerrada, rascunho. */
    private const POSTO = ['aberta' => 0, 'publicada' => 1, 'rascunho' => 3, 'encerrada' => 4];

    /** Com mais grupos que isto, só abrem os que têm algo em andamento (desenho: `muitos = grs.length > 3`). */
    private const GRUPOS_SEM_RECOLHER = 3;

    /** @param list<AssembleiaNoPainel> $assembleias */
    public function agrupar(array $assembleias, FiltroDoPainel $filtro): PainelAgrupado
    {
        $entidades = [];
        $grupos = [];
        foreach ($assembleias as $assembleia) {
            $chave = self::chave($assembleia);
            $entidades[$chave] ??= ['chave' => $chave, 'nome' => (string) $assembleia->entidade, 'cnpj' => $assembleia->cnpjFormatado()];
            if ($this->casa($assembleia, $filtro)) {
                $grupos[$chave][] = $assembleia;
            }
        }

        $montados = [];
        foreach ($grupos as $chave => $lista) {
            $montados[] = [$chave, $lista, min(array_map(self::posto(...), $lista))];
        }
        usort($montados, static fn (array $a, array $b): int => [$a[2], self::nomeDoGrupo($a[1][0])] <=> [$b[2], self::nomeDoGrupo($b[1][0])]);

        $muitos = \count($montados) > self::GRUPOS_SEM_RECOLHER;
        $saida = [];
        foreach ($montados as [$chave, $lista]) {
            $ativo = false;
            foreach ($lista as $assembleia) {
                $ativo = $ativo || (!$assembleia->arquivada() && 'encerrada' !== $assembleia->situacao());
            }
            $saida[] = new GrupoDaEntidade(
                $chave,
                (string) $lista[0]->entidade,
                $lista[0]->cnpjFormatado(),
                $lista,
                self::resumo($lista),
                !$muitos || $ativo || '' !== $filtro->busca || '' !== $filtro->entidade,
            );
        }

        $opcoes = array_values($entidades);
        usort($opcoes, static fn (array $a, array $b): int => strcmp($a['nome'], $b['nome']));

        return new PainelAgrupado($saida, $opcoes, [] !== $assembleias);
    }

    /**
     * Votações abertas (pauta aberta, suspensa ou não) e cédulas depositadas (pautas que já saíram de "aguardando",
     * canceladas incluídas: as cédulas ficam lacradas). Retirada e assembleia arquivada ficam de fora, como em
     * Acompanhar e Resultados.
     *
     * @param list<AssembleiaNoPainel> $assembleias
     */
    public function kpis(array $assembleias, \DateTimeImmutable $agora): KpisDoPainel
    {
        $abertas = [];
        $cedulas = [];
        $total = 0;
        foreach ($assembleias as $assembleia) {
            if ($assembleia->arquivada() || !$assembleia->publicada()) {
                continue;
            }
            foreach ($assembleia->pautas as $pauta) {
                if ($pauta->retirada() || !($pauta->aberta() || $pauta->encerrada())) {
                    continue;
                }
                $situacao = self::situacaoDaPauta($pauta);
                $linha = new LinhaDoKpi($pauta->titulo, $assembleia->titulo, $pauta->votantes, $pauta->unidadesAptas, $pauta->aberta() ? $pauta->segundosRestantes($agora) : null, $situacao);
                $cedulas[] = $linha;
                $total += $pauta->votantes;
                if ($pauta->aberta()) {
                    $abertas[] = $linha;
                }
            }
        }

        return new KpisDoPainel($abertas, $cedulas, $total);
    }

    private static function situacaoDaPauta(PautaNoPainel $pauta): string
    {
        return match (true) {
            $pauta->cancelada() => 'cancelada',
            $pauta->suspensa() => 'suspensa',
            $pauta->aberta() => 'aberta',
            default => 'encerrada',
        };
    }

    /** Chave do grupo: nome sem acento em minúsculas, "|" e os dígitos do CNPJ ("Sem entidade" sem nome). */
    public static function chave(AssembleiaNoPainel $assembleia): string
    {
        $nome = self::normalizar('' === trim((string) $assembleia->entidade) ? 'Sem entidade' : (string) $assembleia->entidade);

        return $nome . '|' . (string) $assembleia->cnpj;
    }

    /** Minúsculas e sem acento, para a busca e a chave não dependerem de como se digitou. */
    public static function normalizar(string $texto): string
    {
        return (new UnicodeString($texto))->ascii()->lower()->trim()->toString();
    }

    private function casa(AssembleiaNoPainel $assembleia, FiltroDoPainel $filtro): bool
    {
        if ('' !== $filtro->entidade && self::chave($assembleia) !== $filtro->entidade) {
            return false;
        }
        $situacao = $assembleia->situacao();
        if ('and' === $filtro->situacao && !\in_array($situacao, ['publicada', 'aberta'], true)) {
            return false;
        }
        if ('ras' === $filtro->situacao && 'rascunho' !== $situacao) {
            return false;
        }
        if ('con' === $filtro->situacao && 'encerrada' !== $situacao) {
            return false;
        }
        if ('' === $filtro->busca) {
            return true;
        }
        $titulosDasPautas = array_map(static fn (PautaNoPainel $p): string => $p->titulo, $assembleia->pautas);
        $palheiro = self::normalizar(implode(' ', [$assembleia->titulo, (string) $assembleia->entidade, (string) $assembleia->cnpj, $assembleia->cnpjFormatado(), ...$titulosDasPautas]));
        $busca = self::normalizar($filtro->busca);
        if (str_contains($palheiro, $busca)) {
            return true;
        }
        $digitos = CnpjDaEntidade::somenteDigitos($filtro->busca);

        return '' !== $digitos && str_contains((string) $assembleia->cnpj, $digitos);
    }

    private static function posto(AssembleiaNoPainel $assembleia): int
    {
        return self::POSTO[$assembleia->situacao()];
    }

    private static function nomeDoGrupo(AssembleiaNoPainel $assembleia): string
    {
        return self::normalizar((string) $assembleia->entidade);
    }

    /**
     * Pautas (sem as retiradas) por situação. Assembleia ainda sem pauta conta como um rascunho, para o grupo nunca
     * ficar com o resumo em branco.
     *
     * @param list<AssembleiaNoPainel> $assembleias
     *
     * @return array{aberta: int, publicada: int, rascunho: int, encerrada: int}
     */
    private static function resumo(array $assembleias): array
    {
        $resumo = ['aberta' => 0, 'publicada' => 0, 'rascunho' => 0, 'encerrada' => 0];
        foreach ($assembleias as $assembleia) {
            $pautas = array_filter($assembleia->pautas, static fn (PautaNoPainel $p): bool => !$p->retirada());
            if ([] === $pautas) {
                ++$resumo['rascunho'];
            }
            foreach ($pautas as $pauta) {
                ++$resumo[$pauta->situacao()];
            }
        }

        return $resumo;
    }
}
