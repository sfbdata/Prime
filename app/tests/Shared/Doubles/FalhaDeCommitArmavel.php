<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as ConexaoDoDriver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;

/**
 * Faz o PRÓXIMO `commit()` real da aplicação falhar — sob o DAMA, onde nenhum COMMIT falha (E2.5).
 *
 * ## Por que existe
 *
 * No teste, a transação de verdade é do DAMA: o `commit()` da aplicação vira
 * `RELEASE SAVEPOINT DAMA_TEST`, que não confere nada e nunca falha. Sem isto, o ramo "o COMMIT
 * lançou" de `TransacaoComArquivoNovo` só existiria em teste unitário com mock — e o que se quer
 * provar é o controller inteiro, com o banco e o disco reais.
 *
 * Registrado só em `when@test` (`config/services.yaml`) como middleware do DBAL com prioridade
 * menor que a do DAMA (100), então fica POR FORA dele: é o primeiro a receber o `commit()`.
 * Inerte até ser armado; o disparo é de uma vez só.
 *
 * **Mira só a transação que interessa.** Armado, ele espera a leitura de `pg_current_xact_id()` —
 * a última coisa que `TransacaoComArquivoNovo` faz antes do COMMIT — e só o COMMIT seguinte
 * falha. Sem isso, qualquer `flush` anterior na mesma requisição (sessão, auditoria) consumiria o
 * disparo e o teste provaria outra coisa.
 *
 * ## Os dois modos, que são os dois lados da ambiguidade medida na investigação
 *
 *  - {@see recusarProximoCommit()} — o banco recusa: desfaz o savepoint e lança. As linhas
 *    somem (o destino real seria `aborted`);
 *  - {@see perderRespostaDoProximoCommit()} — o COMMIT chega e a resposta se perde: confirma o
 *    savepoint e lança. As linhas FICAM (o destino real seria `committed`).
 *
 * A exceção é uma `Driver\Exception` sem SQLSTATE: o DBAL a converte na mesma `DriverException`
 * genérica que a queda de rede do cliente produz em produção.
 */
final class FalhaDeCommitArmavel implements Middleware
{
    /** Armado e esperando a leitura do xid. */
    private static ?string $armado = null;

    /** O xid foi lido: o próximo COMMIT falha deste jeito. */
    private static ?string $modo = null;

    public static int $disparos = 0;

    public static function recusarProximoCommit(): void
    {
        self::$armado = 'recusado';
    }

    public static function perderRespostaDoProximoCommit(): void
    {
        self::$armado = 'resposta_perdida';
    }

    public static function desarmar(): void
    {
        self::$armado   = null;
        self::$modo     = null;
        self::$disparos = 0;
    }

    /** @internal usado pela conexão embrulhada */
    public static function viuConsulta(string $sql): void
    {
        if (self::$armado !== null && str_contains($sql, 'pg_current_xact_id()')) {
            self::$modo   = self::$armado;
            self::$armado = null;
        }
    }

    /** @internal usado pela conexão embrulhada */
    public static function consumir(): ?string
    {
        $modo       = self::$modo;
        self::$modo = null;

        if ($modo !== null) {
            ++self::$disparos;
        }

        return $modo;
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): ConexaoDoDriver
            {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function query(string $sql): Result
                    {
                        FalhaDeCommitArmavel::viuConsulta($sql);

                        return parent::query($sql);
                    }

                    public function commit(): void
                    {
                        $modo = FalhaDeCommitArmavel::consumir();

                        if ($modo === null) {
                            parent::commit();

                            return;
                        }

                        $modo === 'recusado' ? parent::rollBack() : parent::commit();

                        throw new class ('COMMIT simulado: ' . $modo) extends AbstractException {
                        };
                    }
                };
            }
        };
    }
}
