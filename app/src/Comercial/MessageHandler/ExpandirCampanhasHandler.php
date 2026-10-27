<?php

declare(strict_types=1);

namespace App\Comercial\MessageHandler;

use App\Comercial\Campanha\ExpansorDeCampanhas;
use App\Comercial\Message\ExpandirCampanhas;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Uma volta do expansor de campanhas (spec `comercial-e-emails.md` §8.5), no molde do {@see VarrerAutomacoesHandler}.
 * Quem a dispara, a cada minuto, é o schedule `cadeias` ({@see \App\Shared\Scheduler\AgendaDasCadeias}); o handler não
 * se reagenda. Sem nenhuma campanha ativa, sai sem tocar em nada. Com alguma, reivindica a vez na linha 1 de
 * `comercial_expansor` (zero linhas = outra volta acabou de passar, e esta não faz nada) e roda a volta do
 * {@see ExpansorDeCampanhas}.
 */
#[AsMessageHandler]
final class ExpandirCampanhasHandler
{
    /** De quanto em quanto o schedule `cadeias` dispara o expansor. */
    public const INTERVALO_SEGUNDOS = 60;

    /**
     * Janela da vez: metade do intervalo, pelo mesmo motivo da varredura ({@see VarrerAutomacoesHandler::VEZ_SEGUNDOS}).
     * A vazão das campanhas (um lote por minuto) depende de nenhuma volta legítima ser barrada.
     */
    public const VEZ_SEGUNDOS = 30;

    public function __construct(
        private readonly Connection $conexao,
        private readonly ExpansorDeCampanhas $expansor,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExpandirCampanhas $mensagem): void
    {
        // Uma falha na conferência (null) segue a volta: melhor conferir a chave à toa do que pular uma campanha.
        if ($this->passo('ativas', fn (): bool => $this->expansor->haAtivas()) === false) {
            return;
        }
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
    }

    /** Cada passo isolado; uma falha vira null. */
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
            ['agora' => $agora->format('Y-m-d H:i:s'), 'proxima' => $agora->modify('+' . self::VEZ_SEGUNDOS . ' seconds')->format('Y-m-d H:i:s')],
        ) === 1;
    }
}
