<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use App\Shared\Doctrine\Transacao\ConsultaDeDestinoNoPostgres;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `pg_xact_status()` respondendo de verdade (E2.5) — fora do DAMA.
 *
 * Sob o DAMA a transação do teste nunca termina, e o destino de qualquer xid lido pela aplicação é
 * sempre "em andamento". Por isso as conexões daqui são PRÓPRIAS (`DriverManager` com configuração
 * sem middleware), abertas no MESMO banco da conexão do kernel (o nome vem dela, não de uma cópia
 * da regra de sufixo), e só usam tabela TEMPORÁRIA: nada persiste, nada colide com a suíte.
 *
 * O caso que decide a política é o do COMMIT recusado pelo próprio servidor: o cliente recebe erro
 * e o banco prova `aborted` — o único destino que autoriza apagar o arquivo novo. O caso simétrico
 * (COMMIT que chega e perde a resposta, e termina `committed`) foi reproduzido na investigação com
 * SIGKILL no cliente; aqui ele aparece como `committed` depois de um COMMIT normal, que é o que o
 * servidor responde nos dois casos.
 */
#[CoversClass(ConsultaDeDestinoNoPostgres::class)]
final class ConsultaDeDestinoNoPostgresTest extends KernelTestCase
{
    private string $banco;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->banco = (string) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->getDatabase();
    }

    /** @var list<Connection> */
    private array $conexoes = [];

    protected function tearDown(): void
    {
        foreach ($this->conexoes as $conexao) {
            if ($conexao->isTransactionActive()) {
                try {
                    $conexao->rollBack();
                } catch (\Throwable) {
                }
            }

            $conexao->close();
        }

        parent::tearDown();
    }

    #[TestDox('transação confirmada → Confirmada')]
    public function testConfirmada(): void
    {
        $conexao = $this->conexao();
        $conexao->beginTransaction();
        $xid = $this->xid($conexao);
        $conexao->commit();

        self::assertSame(DestinoDaTransacao::Confirmada, $this->consulta()->destinoDe($xid));
    }

    #[TestDox('ROLLBACK → NaoConfirmada')]
    public function testDesfeita(): void
    {
        $conexao = $this->conexao();
        $conexao->beginTransaction();
        $xid = $this->xid($conexao);
        $conexao->rollBack();

        self::assertSame(DestinoDaTransacao::NaoConfirmada, $this->consulta()->destinoDe($xid));
    }

    /**
     * O cenário real da política: o COMMIT LANÇA para a aplicação, e só o banco sabe o que houve.
     * Uma UNIQUE deferida só é conferida no COMMIT — é a forma determinística de o servidor recusá-lo.
     */
    #[TestDox('COMMIT recusado pelo servidor → a aplicação vê exceção, e o banco prova NaoConfirmada')]
    public function testCommitRecusadoPeloServidor(): void
    {
        $conexao = $this->conexao();
        $conexao->executeStatement(
            'CREATE TEMP TABLE e25_commit_recusado (v int, CONSTRAINT e25_uq UNIQUE (v) DEFERRABLE INITIALLY DEFERRED)',
        );

        $conexao->beginTransaction();
        $conexao->executeStatement('INSERT INTO e25_commit_recusado VALUES (1), (1)');
        $xid = $this->xid($conexao);

        $falhou = false;
        try {
            $conexao->commit();
        } catch (\Doctrine\DBAL\Exception) {
            $falhou = true;
        }

        self::assertTrue($falhou, 'o COMMIT devia ter sido recusado pela constraint deferida');
        self::assertSame(DestinoDaTransacao::NaoConfirmada, $this->consulta()->destinoDe($xid));
    }

    /**
     * `in progress` não prova nada: medido na investigação, ele vira `committed` segundos depois
     * quando a rede do cliente cai durante o COMMIT.
     */
    #[TestDox('transação ainda em andamento (outra sessão) → Incerta')]
    public function testEmAndamento(): void
    {
        $outra = $this->conexao();
        $outra->beginTransaction();
        $xid = $this->xid($outra);

        self::assertSame(DestinoDaTransacao::Incerta, $this->consulta()->destinoDe($xid));
    }

    #[TestDox('xid que não é número → Incerta, sem ir ao banco ("1;SELECT 1" viraria o xid 1, que o PG dá como committed)')]
    public function testXidMalformado(): void
    {
        foreach (['', 'abc', '0', '-5', '12 ', '1;SELECT 1', "1\n"] as $xid) {
            self::assertSame(DestinoDaTransacao::Incerta, $this->consulta()->destinoDe($xid), json_encode($xid, \JSON_THROW_ON_ERROR));
        }
    }

    /** O PG responde `committed` para 1 e 2 sempre — o que não diz nada sobre transação nenhuma. */
    #[TestDox('xids especiais 1 (bootstrap) e 2 (congelado) → Incerta, sem ir ao banco')]
    public function testXidsEspeciais(): void
    {
        foreach (['1', '2'] as $xid) {
            self::assertSame(DestinoDaTransacao::Incerta, $this->consulta()->destinoDe($xid), $xid);
        }
    }

    #[TestDox('xid fora da janela do CLOG (NULL) → Incerta')]
    public function testXidAntigoDemais(): void
    {
        self::assertSame(DestinoDaTransacao::Incerta, $this->consulta()->destinoDe('3'));
    }

    #[TestDox('erro na consulta (xid no futuro) → Incerta, com registro, sem lançar')]
    public function testErroNaConsulta(): void
    {
        $logger   = new LoggerEmMemoria();
        $consulta = new ConsultaDeDestinoNoPostgres($this->conexao(), $logger);

        self::assertSame(DestinoDaTransacao::Incerta, $consulta->destinoDe('99999999999999'));
        self::assertCount(1, $logger->doNivel('warning'));
    }

    #[TestDox('a consulta reconecta: uma conexão quebrada sem o DBAL saber não vira "sem resposta"')]
    public function testReconectaAntesDePerguntar(): void
    {
        $conexao = $this->conexao();
        $conexao->beginTransaction();
        $xid = $this->xid($conexao);
        $conexao->rollBack();

        // Derruba o backend desta sessão por outra: o PDO fica quebrado e o DBAL não percebe.
        $pid = (int) $conexao->fetchOne('SELECT pg_backend_pid()');
        $this->conexao()->fetchOne('SELECT pg_terminate_backend(?)', [$pid]);

        $consulta = new ConsultaDeDestinoNoPostgres($conexao, new LoggerEmMemoria());

        self::assertSame(DestinoDaTransacao::NaoConfirmada, $consulta->destinoDe($xid));
    }

    // ------------------------------------------------------------ apoio

    private function consulta(): ConsultaDeDestinoNoPostgres
    {
        return new ConsultaDeDestinoNoPostgres($this->conexao(), new LoggerEmMemoria());
    }

    private function xid(Connection $conexao): string
    {
        return (string) $conexao->fetchOne('SELECT pg_current_xact_id()::text');
    }

    /** Conexão própria, fora do DAMA, no banco que o kernel de teste usa. */
    private function conexao(): Connection
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($url, 'DATABASE_URL ausente no ambiente de teste');

        $parametros           = (new DsnParser(['pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']))->parse($url);
        $parametros['dbname'] = $this->banco;

        $conexao          = DriverManager::getConnection($parametros, new Configuration());
        $this->conexoes[] = $conexao;

        return $conexao;
    }
}
