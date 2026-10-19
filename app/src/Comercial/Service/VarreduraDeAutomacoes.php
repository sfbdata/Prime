<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Enum\Automacao;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use App\Comercial\Repository\CicloDaContaRepository;
use App\Repository\UserRepository;

/**
 * As automações por tempo (spec §3.5): a `dica` (conta confirmada há 3/7/15 dias, uma vez). Só fatos depois do
 * `ligada_em`: nada para o passado. Lotes de 50 por passo; o que sobra vai no próximo passo da cadeia.
 *
 * O `lemb` ("E-mail não confirmado") passou para a frente do Login (9b, 19/10): com o cadastro que cria a conta direto,
 * o lembrete é sobre o User com e-mail não confirmado. Ele entra aqui quando a 9b entregar a consulta e o caso de uso
 * de reemissão; até lá a linha aparece na tela e não dispara.
 */
final class VarreduraDeAutomacoes
{
    public const LOTE = 50;

    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly CicloDaContaRepository $ciclos,
        private readonly UserRepository $usuarios,
        private readonly DisparadorDeAutomacao $disparador,
    ) {
    }

    public function mandarDicas(\DateTimeImmutable $agora): void
    {
        $estado = $this->automacoes->estado(Automacao::Dica);
        if (!$estado->ligada || $estado->ligadaEm === null) {
            return;
        }
        $dias = (int) rtrim((string) $estado->atraso, 'd');
        foreach ($this->ciclos->paraDica($estado->ligadaEm, $agora->modify(sprintf('-%d days', $dias)), self::LOTE) as $conta) {
            if (!$this->ciclos->marcarDica($conta['id'], $agora)) {
                continue;
            }
            $user = $this->usuarios->find($conta['user_id']);
            if ($user !== null && $user->getEmail() !== null) {
                $this->disparador->disparar(Automacao::Dica, (string) $user->getEmail(), $user->getFullName());
            }
        }
    }
}
