<?php

declare(strict_types=1);

namespace App\Comercial\MessageHandler;

use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\Service\RecuperacaoDeEnvios;
use App\Comercial\Service\VarreduraDeAutomacoes;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Um passo da cadeia de varredura (spec comercial-e-emails.md §3.4): reivindica a vez na linha 1 de
 * `comercial_varredura` (zero linhas = outra cadeia já passou, e esta morre sem se reagendar), roda o `lemb`, a
 * `dica` e a recuperação de envios parados, e se reagenda em 15 min.
 */
#[AsMessageHandler]
final class VarrerAutomacoesHandler
{
    public const INTERVALO_SEGUNDOS = 900;

    public function __construct(
        private readonly Connection $conexao,
        private readonly VarreduraDeAutomacoes $varredura,
        private readonly RecuperacaoDeEnvios $recuperacao,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(VarrerAutomacoes $mensagem): void
    {
        $agora = $this->clock->now();
        if (!$this->reivindicar($agora)) {
            return;
        }
        try {
            $this->varredura->lembrarCadastrosNaoConfirmados($agora);
            $this->varredura->mandarDicas($agora);
            $this->recuperacao->recuperar($agora);
        } finally {
            $this->bus->dispatch(new VarrerAutomacoes(), [new DelayStamp(self::INTERVALO_SEGUNDOS * 1000)]);
        }
    }

    private function reivindicar(\DateTimeImmutable $agora): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE comercial_varredura SET executada_em = :agora, proxima_em = :proxima WHERE id = 1 AND proxima_em <= :agora',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'proxima' => $agora->modify('+' . (self::INTERVALO_SEGUNDOS - 60) . ' seconds')->format('Y-m-d H:i:s')],
        ) === 1;
    }
}
