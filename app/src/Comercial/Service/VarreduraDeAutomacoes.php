<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Enum\Automacao;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use App\Comercial\Repository\CicloDaContaRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * As automações por tempo (spec §3.5): a `dica` (conta confirmada há 3/7/15 dias, uma vez) e os dias 2 a 7 do plano de
 * ativação ({@see self::mandarAtivacao()}). Só fatos depois do
 * `ligada_em`: nada para o passado. Lotes de 50 por passo; o que sobra vai no próximo passo da cadeia.
 *
 * O `lemb` ("E-mail não confirmado") passou para a frente do Login (9b, 19/10): com o cadastro que cria a conta direto,
 * o lembrete é sobre o User com e-mail não confirmado. Ele entra aqui quando a 9b entregar a consulta e o caso de uso
 * de reemissão; até lá a linha aparece na tela e não dispara.
 */
final class VarreduraDeAutomacoes
{
    public const LOTE = 50;

    /** Quantos dias um passo da ativação ainda pode sair atrasado (worker parado). */
    public const TOLERANCIA_DA_ATIVACAO_DIAS = 2;

    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly CicloDaContaRepository $ciclos,
        private readonly UserRepository $usuarios,
        private readonly DisparadorDeAutomacao $disparador,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * O plano de ativação de 7 dias (pedido do Farlei de 26/11/2026): o dia 1 é a boas-vindas do `val`, na
     * confirmação; o passo do dia N sai quando a confirmação faz N-1 dias. Com o worker parado, um passo atrasado ainda
     * sai até {@see self::TOLERANCIA_DA_ATIVACAO_DIAS} dias depois; mais que isso, a pessoa recebe só o passo do dia em
     * que está. Do dia 7 para o 2, e cada passo barra os anteriores: nunca saem dois de uma vez, nem um passo depois de
     * um posterior. Só quem confirmou depois que o passo foi ligado (nada para o passado). O dia 6 só vai a quem está
     * num escritório que ainda não criou nenhuma pasta.
     */
    public function mandarAtivacao(\DateTimeImmutable $agora): void
    {
        foreach (array_reverse(Automacao::daAtivacao()) as $passo) {
            $estado = $this->automacoes->estado($passo);
            if (!$estado->ligada || $estado->ligadaEm === null) {
                continue;
            }
            $dia = (int) $passo->diaDaAtivacao();
            $ate = $agora->modify(sprintf('-%d days', $dia - 1));
            $usuarios = $this->ciclos->paraAtivacao(
                $estado->ligadaEm,
                $ate->modify(sprintf('-%d days', self::TOLERANCIA_DA_ATIVACAO_DIAS)),
                $ate,
                self::origensDesde($dia),
                $passo === Automacao::At6,
                self::LOTE,
            );
            foreach ($usuarios as $userId) {
                $this->mandarPasso($passo, $userId);
            }
        }
    }

    /** Uma falha não para o lote: sem a linha do envio, a pessoa entra de novo no próximo passo da cadeia. */
    private function mandarPasso(Automacao $passo, int $userId): void
    {
        $user = $this->usuarios->find($userId);
        if ($user === null || $user->getEmail() === null) {
            return;
        }
        try {
            $this->disparador->disparar($passo, (string) $user->getEmail(), $user->getFullName());
        } catch (\Throwable $erro) {
            $this->logger->warning('Comercial: passo da ativação não enfileirado.', ['automacao' => $passo->value, 'userId' => $userId, 'erro' => $erro::class]);
        }
    }

    /** @return list<string> as origens do passo do dia e dos seguintes */
    private static function origensDesde(int $dia): array
    {
        return array_values(array_map(
            static fn (Automacao $a): string => $a->origem(),
            array_filter(Automacao::daAtivacao(), static fn (Automacao $a): bool => $a->diaDaAtivacao() >= $dia),
        ));
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
            if ($user === null || $user->getEmail() === null) {
                continue;
            }
            try {
                $this->disparador->disparar(Automacao::Dica, (string) $user->getEmail(), $user->getFullName());
            } catch (\Throwable $erro) {
                $this->ciclos->desmarcarDica($conta['id']);   // tenta de novo no próximo passo; não para o lote
                $this->logger->warning('Comercial: dica não enfileirada.', ['cicloId' => $conta['id'], 'erro' => $erro::class]);
            }
        }
    }
}
