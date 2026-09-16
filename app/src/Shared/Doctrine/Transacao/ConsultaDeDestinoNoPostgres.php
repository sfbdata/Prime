<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Transacao;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * `pg_xact_status()` como prova do destino de uma transação (PG 13+; o projeto roda o 15).
 *
 * ## Por que só `aborted` e `committed` decidem
 *
 * No PG 15 (`xid8funcs.c`), `pg_xact_status` primeiro pergunta se a transação ainda está em
 * andamento e só depois consulta o CLOG: `aborted` quer dizer "não está em andamento e o CLOG não
 * diz committed" — definitivo. `in progress` pode virar `committed` segundos depois (medido na
 * investigação da E2.5: queda de rede do cliente durante o COMMIT, `in progress` e, 4 s depois,
 * `committed`). NULL (xid fora da janela do CLOG) e erro de consulta também não provam nada.
 *
 * ## Por que fecha a conexão antes de perguntar
 *
 * Quando a rede cai do lado do cliente, o DBAL recebe uma `DriverException` genérica e **não**
 * fecha a conexão: `isConnected()` continua true sobre um PDO quebrado, e a consulta falharia
 * ("no connection to the server"). `close()` força a reconexão preguiçosa com os mesmos
 * parâmetros. Quem chama já perdeu a transação e fechou o EntityManager; o `xid` é global do
 * cluster, então a sessão nova responde pelo destino da antiga.
 *
 * Nunca lança: na dúvida, {@see DestinoDaTransacao::Incerta}.
 */
final readonly class ConsultaDeDestinoNoPostgres implements ConsultaDeDestinoDaTransacao
{
    public function __construct(
        private Connection $conexao,
        private LoggerInterface $logger,
    ) {
    }

    public function destinoDe(string $xid): DestinoDaTransacao
    {
        // Só dígitos são xid. No PG 15 o CAST para xid8 não recusa lixo: 'abc' vira 0 (status NULL),
        // e '1abc', '1;SELECT 1' ou "1\n" viram o xid 1, que o PG relata como `committed` (medido na
        // E2.5). Sem esta guarda, um xid malformado passaria por COMMIT confirmado. `\z`, e não `$`:
        // o `$` aceita a quebra de linha final. 1 e 2 são xids especiais (bootstrap e congelado),
        // sempre `committed`, e nunca saem de `pg_current_xact_id()` — não provam nada.
        if (preg_match('/^[1-9][0-9]*\z/', $xid) !== 1 || $xid === '1' || $xid === '2') {
            return DestinoDaTransacao::Incerta;
        }

        try {
            $this->conexao->close();
            $status = $this->conexao->fetchOne('SELECT pg_xact_status(CAST(? AS xid8))', [$xid]);
        } catch (\Throwable $e) {
            $this->logger->warning('Não foi possível consultar o destino da transação.', [
                'xid'    => $xid,
                'erro'   => $e->getMessage(),
                'classe' => $e::class,
            ]);

            return DestinoDaTransacao::Incerta;
        }

        return match ($status) {
            'aborted'   => DestinoDaTransacao::NaoConfirmada,
            'committed' => DestinoDaTransacao::Confirmada,
            default     => DestinoDaTransacao::Incerta,
        };
    }
}
