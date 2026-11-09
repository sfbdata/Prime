<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\DTO\ConferenciaDaTrilhaDoPontoOutput;

/**
 * A conta da conferência da trilha do Ponto (E9), sem banco: percorre a cadeia em ordem de sequência e para no
 * primeiro evento que não confere.
 *
 * Um evento não confere quando: falta a sequência esperada (linha apagada ou fora de ordem); o `selo_anterior` não é o
 * selo da linha de antes (linha trocada ou reordenada); a linha não tem selo depois de uma selada (selo apagado); a
 * versão da chave gravada não tem chave (versão desconhecida); ou o selo recalculado com a chave da versão GRAVADA
 * difere do guardado (qualquer coluna mexida). Pela versão gravada, a troca da chave atual não invalida o que a
 * anterior selou.
 *
 * O trecho inicial sem selo (eventos gravados antes de o segredo existir) não é alteração: a conferência devolve "não
 * selada" até o último deles, e confere os selados dali em diante.
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
        $esperada      = 1;
        $seloAnterior  = null;
        $jaSelada      = false;
        $ultimaSemSelo = null;

        foreach ($cadeia as $linha) {
            $sequencia = $linha['sequencia'];
            if ($sequencia !== $esperada) {
                return ConferenciaDaTrilhaDoPontoOutput::alterada(min($sequencia, $esperada));
            }
            if ($linha['selo_anterior'] !== $seloAnterior) {
                return ConferenciaDaTrilhaDoPontoOutput::alterada($sequencia);
            }

            ++$esperada;
            $seloAnterior = $linha['selo'];
            if ($linha['selo'] === null) {
                if ($jaSelada) {
                    return ConferenciaDaTrilhaDoPontoOutput::alterada($sequencia);
                }
                $ultimaSemSelo = $sequencia;

                continue;
            }
            if (!self::seloConfere($linha, $chaveDaVersao)) {
                return ConferenciaDaTrilhaDoPontoOutput::alterada($sequencia);
            }
            $jaSelada = true;
        }

        return $ultimaSemSelo === null
            ? ConferenciaDaTrilhaDoPontoOutput::integra()
            : ConferenciaDaTrilhaDoPontoOutput::trechoInicial($ultimaSemSelo);
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
