<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\DTO\ConferenciaDaTrilhaDoPontoOutput;

/**
 * A conta da conferência da trilha do Ponto (E9), sem banco: percorre a cadeia em ordem de sequência e para no
 * primeiro evento que não confere.
 *
 * ## O elo
 *
 * Toda linha guarda em `selo_anterior` o ÚLTIMO SELO da cadeia antes dela (o da linha de antes, ou, se ela entrou sem
 * selo, o último que existia), exatamente como a {@see TrilhaDoPonto} grava. Assim a primeira linha selada depois de um
 * trecho sem selo se prende à última selada antes dele, e o trecho não solta a cadeia.
 *
 * ## Alterada
 *
 * Um evento não confere quando: falta a sequência esperada (linha apagada ou fora de ordem); o `selo_anterior` não é o
 * último selo (linha trocada, reordenada ou selo apagado no meio); a versão da chave gravada não tem chave (versão
 * desconhecida); ou o selo recalculado com a chave da versão GRAVADA difere do guardado (qualquer coluna mexida). Pela
 * versão gravada, a troca da chave atual não invalida o que a anterior selou. Quando o elo quebra logo depois de linhas
 * sem selo, o evento apontado é o primeiro delas: é ali que a cadeia deixou de conferir (o selo apagado de uma linha
 * selada aparece assim).
 *
 * ## Trecho sem selo
 *
 * Linhas sem selo (gravadas antes de o segredo existir, ou numa falta temporária dele) não são alteração: o elo delas
 * confere e a conferência devolve "trecho sem selo" com as faixas dos números, nunca "íntegra". Limite aceito (não há
 * âncora fora do banco): um fim de cadeia com o selo tirado (e o conteúdo trocado), ou um evento sem selo acrescentado
 * no fim, também aparece assim, e continua assim depois dos eventos seguintes. Por isso o texto não afirma a origem.
 * O meio selado continua protegido: alteração, remoção e inserção dão "alterada".
 */
final class ConferenciaDaCadeiaDoPonto
{
    /**
     * @param iterable<array{sequencia: int, tenant_id: int, em: string, autor_id: ?int, autor_nome: ?string,
     *     acao: string, detalhe: array<string, mixed>, selo_anterior: ?string, selo: ?string,
     *     versao_da_chave: ?int}> $cadeia em ordem de sequência
     * @param \Closure(int): ?string $chaveDaVersao a chave da trilha na versão pedida; nula se desconhecida
     */
    public static function conferir(iterable $cadeia, \Closure $chaveDaVersao): ConferenciaDaTrilhaDoPontoOutput
    {
        $esperada   = 1;
        $ultimoSelo = null;
        $aberto     = null;   // o trecho sem selo em curso: [primeiro, último]
        $trechos    = [];
        $seladas    = 0;

        foreach ($cadeia as $linha) {
            $sequencia = $linha['sequencia'];
            if ($sequencia !== $esperada) {
                return ConferenciaDaTrilhaDoPontoOutput::alterada(min($sequencia, $esperada));
            }
            ++$esperada;
            if ($linha['selo_anterior'] !== $ultimoSelo) {
                return ConferenciaDaTrilhaDoPontoOutput::alterada($aberto[0] ?? $sequencia);
            }
            if ($linha['selo'] === null) {
                $aberto = [$aberto[0] ?? $sequencia, $sequencia];

                continue;
            }
            if (!self::seloConfere($linha, $chaveDaVersao)) {
                return ConferenciaDaTrilhaDoPontoOutput::alterada($sequencia);
            }
            if ($aberto !== null) {
                $trechos[] = $aberto;
                $aberto    = null;
            }
            $ultimoSelo = $linha['selo'];
            ++$seladas;
        }

        if ($aberto !== null) {
            $trechos[] = $aberto;
        }

        return $trechos === [] ? ConferenciaDaTrilhaDoPontoOutput::integra() : ConferenciaDaTrilhaDoPontoOutput::trechoSemSelo($trechos, $seladas > 0);
    }

    /**
     * @param array{sequencia: int, tenant_id: int, em: string, autor_id: ?int, autor_nome: ?string, acao: string,
     *     detalhe: array<string, mixed>, selo_anterior: ?string, selo: ?string, versao_da_chave: ?int} $linha
     * @param \Closure(int): ?string $chaveDaVersao
     */
    private static function seloConfere(array $linha, \Closure $chaveDaVersao): bool
    {
        $versao = $linha['versao_da_chave'];
        $chave  = $versao === null ? null : $chaveDaVersao($versao);
        if ($chave === null || $linha['selo'] === null) {
            return false;
        }

        return hash_equals($linha['selo'], SeloDaTrilhaDoPonto::calcular($chave, $linha));
    }
}
