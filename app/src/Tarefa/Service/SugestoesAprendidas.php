<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Tarefa\Entity\AprendizadoDaPendencia;
use App\Tarefa\Entity\AprendizadoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\NaturezaDoDespacho;

/**
 * As contas do aprendizado, copiadas da lógica do protótipo (desenho 04B de 22/10, L151-158), sobre os registros de
 * UMA pessoa num escritório, do mais novo para o mais antigo:
 *
 * - Frases (L151-153): a base é o que ela despachou nesta etapa, se houver pelo menos 2; senão, tudo o que ela
 *   despachou. Com uma natureza marcada, só os dessa natureza. Agrupa pelos 90 primeiros caracteres sem diferença de
 *   maiúsculas (o texto e a natureza que ficam são os do registro mais novo do grupo) e devolve as 3 mais usadas; no
 *   empate, a mais recente primeiro.
 * - Natureza preferida (L153): a mais usada por ela nesta etapa.
 * - Prazo usual (L153): a mediana dos dias úteis dos prazos dela nesta etapa (sem os despachos sem prazo), com pelo
 *   menos 2 amostras; o elemento do meio da lista ordenada, como no protótipo. Zero quando não há.
 * - "Você costuma pedir" (L157-158): as 5 linhas de pendência mais pedidas por ela para a etapa destino, sem diferença
 *   de maiúsculas.
 */
final class SugestoesAprendidas
{
    public const FRASES = 3;
    public const PEDIDOS = 5;
    public const AMOSTRAS_MINIMAS = 2;
    public const TAMANHO_DA_CHAVE = 90;
    public const TAMANHO_DO_ROTULO = 110;

    /**
     * @param list<AprendizadoDoDespacho> $registros
     *
     * @return list<array{texto: string, rotulo: string, vezes: int, natureza: ?string}>
     */
    public static function frases(array $registros, EtapaDaPasta $etapa, ?NaturezaDoDespacho $natureza): array
    {
        $daEtapa = self::daEtapa($registros, $etapa);
        $base = count($daEtapa) >= self::AMOSTRAS_MINIMAS ? $daEtapa : $registros;
        $grupos = [];
        foreach ($base as $r) {
            if ($natureza !== null && $r->getNatureza() !== $natureza) {
                continue;
            }
            $chave = mb_strtolower(mb_substr($r->getTexto(), 0, self::TAMANHO_DA_CHAVE));
            $grupos[$chave] ??= ['texto' => $r->getTexto(), 'vezes' => 0, 'natureza' => $r->getNatureza()?->value];
            ++$grupos[$chave]['vezes'];
        }
        $grupos = array_values($grupos);
        usort($grupos, static fn (array $a, array $b): int => $b['vezes'] <=> $a['vezes']);

        return array_map(static fn (array $g): array => [
            'texto'    => $g['texto'],
            'rotulo'   => self::rotulo($g['texto']),
            'vezes'    => $g['vezes'],
            'natureza' => $g['natureza'],
        ], array_slice($grupos, 0, self::FRASES));
    }

    /** @param list<AprendizadoDoDespacho> $registros */
    public static function naturezaPreferida(array $registros, EtapaDaPasta $etapa): ?NaturezaDoDespacho
    {
        $contagem = [];
        foreach (self::daEtapa($registros, $etapa) as $r) {
            if ($r->getNatureza() !== null) {
                $contagem[$r->getNatureza()->value] = ($contagem[$r->getNatureza()->value] ?? 0) + 1;
            }
        }
        if ($contagem === []) {
            return null;
        }
        $maior = max($contagem);

        return NaturezaDoDespacho::from((string) array_search($maior, $contagem, true));
    }

    /** @param list<AprendizadoDoDespacho> $registros */
    public static function prazoUsual(array $registros, EtapaDaPasta $etapa): int
    {
        $dias = array_values(array_filter(
            array_map(static fn (AprendizadoDoDespacho $r): int => $r->getDiasUteis(), self::daEtapa($registros, $etapa)),
            static fn (int $d): bool => $d > 0,
        ));
        if (count($dias) < self::AMOSTRAS_MINIMAS) {
            return 0;
        }
        sort($dias);

        return $dias[intdiv(count($dias), 2)];
    }

    /**
     * @param list<AprendizadoDaPendencia> $registros
     *
     * @return list<string>
     */
    public static function pedidos(array $registros, EtapaDaPasta $destino): array
    {
        $grupos = [];
        foreach ($registros as $r) {
            $linha = trim($r->getLinha());
            if ($r->getEtapaDestino() !== $destino || $linha === '') {
                continue;
            }
            $chave = mb_strtolower($linha);
            $grupos[$chave] ??= ['linha' => $linha, 'vezes' => 0];
            ++$grupos[$chave]['vezes'];
        }
        $grupos = array_values($grupos);
        usort($grupos, static fn (array $a, array $b): int => $b['vezes'] <=> $a['vezes']);

        return array_map(static fn (array $g): string => $g['linha'], array_slice($grupos, 0, self::PEDIDOS));
    }

    /** O texto do botão (L156): até 110 caracteres; acima, 108 e reticências. */
    public static function rotulo(string $texto): string
    {
        return mb_strlen($texto) > self::TAMANHO_DO_ROTULO ? mb_substr($texto, 0, self::TAMANHO_DO_ROTULO - 2) . '…' : $texto;
    }

    /**
     * @param list<AprendizadoDoDespacho> $registros
     *
     * @return list<AprendizadoDoDespacho>
     */
    private static function daEtapa(array $registros, EtapaDaPasta $etapa): array
    {
        return array_values(array_filter($registros, static fn (AprendizadoDoDespacho $r): bool => $r->getEtapa() === $etapa));
    }
}
