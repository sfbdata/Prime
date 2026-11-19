<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * As fases a que quem recebeu a pasta de outra conta pode levá-la (spec acesso-restrito-e-compartilhar §4.5.1, fatia
 * 9b). A mesma regra monta a troca de fase da tela e confere o pedido no servidor:
 *
 *  - nunca cruza a fase Contrato, nem para frente nem para trás; sair da própria fase Contrato também conta como
 *    cruzar (o contrato assinado é dado do Financeiro, que fica fora para quem recebeu);
 *  - nunca finaliza nem reativa: nenhuma "Finalizada" (a da trilha nem a de uma reativação anterior) nem "Reativada"
 *    entra, e a pasta finalizada não sai da Finalizada (as duas coisas mexem em "Pastas concluídas" da conta da pasta).
 */
final class FasesDaPastaRecebida
{
    private const FORA = ['Finalizada', 'Reativada'];

    /**
     * @param array{fases: list<array{0: string, 1: string}>, atual: int} $estado o de {@see AndamentoDaPasta::estado()}
     *
     * @return list<int> os índices permitidos, em ordem
     */
    public static function aoAlcance(array $estado): array
    {
        $contrato = AndamentoDaPasta::IDX_CONTRATO;
        $atual = $estado['atual'];
        $ultima = \count($estado['fases']) - 1;
        if ($atual === $contrato || $atual >= $ultima) {
            return [];
        }

        $permitidas = [];
        foreach ($estado['fases'] as $k => $fase) {
            if ($k !== $atual && $k !== $contrato && $k < $ultima && ($k < $contrato) === ($atual < $contrato) && !\in_array($fase[0], self::FORA, true)) {
                $permitidas[] = $k;
            }
        }

        return $permitidas;
    }
}
