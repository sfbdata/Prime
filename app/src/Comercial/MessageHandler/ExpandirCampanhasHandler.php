<?php

declare(strict_types=1);

namespace App\Comercial\MessageHandler;

use App\Comercial\Campanha\ExpansorDeCampanhas;
use App\Comercial\Message\ExpandirCampanhas;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Um passo da cadeia do expansor de campanhas (spec `comercial-e-emails.md` §8.5), no molde do
 * {@see VarrerAutomacoesHandler}: reivindica a vez na linha 1 de `comercial_expansor` (zero linhas = outra cadeia já
 * passou, e esta morre sem se reagendar), roda a volta do {@see ExpansorDeCampanhas} e, com alguma campanha ativa, se
 * reagenda em 60 s; sem nenhuma, a cadeia para (a confirmação e a varredura a acordam de novo).
 */
#[AsMessageHandler]
final class ExpandirCampanhasHandler
{
    public const INTERVALO_SEGUNDOS = 60;

    public function __construct(
        private readonly Connection $conexao,
        private readonly ExpansorDeCampanhas $expansor,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExpandirCampanhas $mensagem): void
    {
        $agora = $this->clock->now();
        if (!$this->reivindicar($agora)) {
            return;
        }
        // A chave primeiro; se ela falhar ou estiver desligada, nada mais roda nesta volta.
        if ($this->passo('chave', fn (): bool => $this->expansor->pararSeDesligada($agora)) === false) {
            $this->passo('materializar', fn (): int => $this->expansor->materializarDevidas($agora));
            $this->passo('lote', fn (): int => $this->expansor->liberarLote($agora));
            $this->passo('concluir', fn (): int => $this->expansor->concluir());
        }
        if ($this->passo('ativas', fn (): bool => $this->expansor->haAtivas()) !== false) {
            $this->bus->dispatch(new ExpandirCampanhas(), [new DelayStamp(self::INTERVALO_SEGUNDOS * 1000)]);
        }
    }

    /** Cada passo isolado; uma falha vira null (e, na conferência das ativas, a cadeia segue viva). */
    private function passo(string $nome, callable $passo): mixed
    {
        try {
            return $passo();
        } catch (\Throwable $erro) {
            $this->logger->error('Comercial: passo do expansor de campanhas falhou.', ['passo' => $nome, 'erro' => $erro::class]);

            return null;
        }
    }

    private function reivindicar(\DateTimeImmutable $agora): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE comercial_expansor SET executada_em = :agora, proxima_em = :proxima WHERE id = 1 AND proxima_em <= :agora',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'proxima' => $agora->modify('+' . (self::INTERVALO_SEGUNDOS - 5) . ' seconds')->format('Y-m-d H:i:s')],
        ) === 1;
    }
}
