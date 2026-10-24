<?php

declare(strict_types=1);

namespace App\Comercial\MessageHandler;

use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\Service\DisparoDasDatasComemorativas;
use App\Comercial\Service\RecuperacaoDeEnvios;
use App\Comercial\Service\VarreduraDeAutomacoes;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Uma volta da varredura (spec comercial-e-emails.md §3.4). Quem a dispara, a cada 15 min, é o schedule `cadeias`
 * ({@see \App\Shared\Scheduler\AgendaDasCadeias}); o handler não se reagenda. Reivindica a vez na linha 1 de
 * `comercial_varredura` (zero linhas = outra volta acabou de passar, e esta não faz nada) e roda a `dica`, a
 * recuperação de envios parados e as datas comemorativas (§13, F4: a campanha do dia, depois das 9h, com a chave
 * geral ligada).
 */
#[AsMessageHandler]
final class VarrerAutomacoesHandler
{
    /** De quanto em quanto o schedule `cadeias` dispara a varredura. */
    public const INTERVALO_SEGUNDOS = 900;

    /**
     * Janela da vez: metade do intervalo. Barra a volta repetida (mensagem antiga da cadeia de antes, dois workers) e
     * deixa passar a seguinte mesmo com o worker atrasado ou com o relógio do Scheduler e o do redispatch desencontrados.
     */
    public const VEZ_SEGUNDOS = 450;

    public function __construct(
        private readonly Connection $conexao,
        private readonly VarreduraDeAutomacoes $varredura,
        private readonly RecuperacaoDeEnvios $recuperacao,
        private readonly DisparoDasDatasComemorativas $datas,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(VarrerAutomacoes $mensagem): void
    {
        $agora = $this->clock->now();
        if (!$this->reivindicar($agora)) {
            return;
        }
        // Cada passo isolado: uma falha na dica não pula a recuperação dos envios.
        $this->passo('dica', fn () => $this->varredura->mandarDicas($agora));
        $this->passo('recuperacao', fn () => $this->recuperacao->recuperar($agora));
        $this->passo('datas', fn () => $this->datas->disparar($agora));
    }

    private function passo(string $nome, callable $passo): void
    {
        try {
            $passo();
        } catch (\Throwable $erro) {
            $this->logger->error('Comercial: passo da varredura falhou.', ['passo' => $nome, 'erro' => $erro::class]);
        }
    }

    private function reivindicar(\DateTimeImmutable $agora): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE comercial_varredura SET executada_em = :agora, proxima_em = :proxima WHERE id = 1 AND proxima_em <= :agora',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'proxima' => $agora->modify('+' . self::VEZ_SEGUNDOS . ' seconds')->format('Y-m-d H:i:s')],
        ) === 1;
    }
}
