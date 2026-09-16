<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A decisão sobre o arquivo novo quando a transação falha (E2.5): só a ausência PROVADA de COMMIT
 * autoriza apagar.
 *
 * Conexão e EntityManager são dublês — exceção consciente à regra de `tests/CLAUDE.md` (não mockar
 * o EntityManager), porque o que se prova é justamente o uso da transação: a ORDEM das fases, o
 * nível desfeito e o repasse do destino, inclusive falhas que o banco real não produz sob demanda;
 * que o PostgreSQL responde `aborted`/`committed`/`in progress` de verdade é
 * `ConsultaDeDestinoNoPostgresTest`, e que o controller inteiro obedece é o funcional de cada ponto.
 */
#[CoversClass(TransacaoComArquivoNovo::class)]
#[CoversClass(DestinoDaTransacao::class)]
final class TransacaoComArquivoNovoTest extends TestCase
{
    private const XID = '11117396';

    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private ChaveDeArquivo $novo;

    /** @var list<string> */
    private array $passos = [];

    private int $nivel = 0;
    private int $nivelInicial = 0;

    private ?\Throwable $falhaNoFlush = null;
    private ?\Throwable $falhaNoXid = null;
    private ?\Throwable $falhaNoCommit = null;
    private ?\Throwable $falhaNoBegin = null;

    /** Falha ANTES de o DBAL subir o nível (o `connect()` que não conecta). */
    private ?\Throwable $falhaNoConnect = null;

    private ?\Throwable $falhaNaConsulta = null;

    /** @var list<string> */
    private array $consultados = [];

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger        = new LoggerEmMemoria();
        $this->novo          = new ChaveDeArquivo(EscopoDeArquivo::deTenant(3), CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, 'novo.pdf');
        $this->armazenamento->semear($this->novo);
    }

    #[TestDox('sucesso: trabalho, flush, xid e COMMIT, nessa ordem — e o arquivo novo fica')]
    public function testSucessoMantemOArquivo(): void
    {
        $retorno = $this->transacao(DestinoDaTransacao::NaoConfirmada)->executar(
            function (): int {
                $this->passos[] = 'trabalho';

                return 27;
            },
            fn (): array => throw new \LogicException('arquivosNovos não pode ser consultado no sucesso'),
            'teste',
        );

        self::assertSame(27, $retorno);
        self::assertSame(['begin', 'trabalho', 'flush', 'xid', 'commit'], $this->passos);
        self::assertTrue($this->armazenamento->existe($this->novo));
    }

    /** @return iterable<string, array{string}> */
    public static function falhasAntesDoCommit(): iterable
    {
        yield 'no trabalho'         => ['trabalho'];
        yield 'no flush'            => ['flush'];
        yield 'na consulta do xid'  => ['xid'];
        yield 'no BEGIN'            => ['begin'];
    }

    #[TestDox('falha $onde (antes do COMMIT): o arquivo novo sai e a exceção ORIGINAL sobe')]
    #[DataProvider('falhasAntesDoCommit')]
    public function testFalhaAntesDoCommitRemoveOArquivo(string $onde): void
    {
        $original = new \RuntimeException('falhou ' . $onde);
        match ($onde) {
            'flush' => $this->falhaNoFlush = $original,
            'xid'   => $this->falhaNoXid = $original,
            'begin' => $this->falhaNoBegin = $original,
            default => null,
        };

        $capturada = $this->executarEsperandoFalha(
            DestinoDaTransacao::Confirmada, // a consulta não pode ser usada: o COMMIT nem foi enviado
            function () use ($onde, $original): void {
                $this->passos[] = 'trabalho';
                if ($onde === 'trabalho') {
                    throw $original;
                }
            },
        );

        self::assertSame($original, $capturada, 'a exceção de quem chamou não pode ser trocada');
        self::assertFalse($this->armazenamento->existe($this->novo));
        self::assertSame([], $this->consultados, 'falha antes do COMMIT não pergunta nada ao banco');
        self::assertNotContains('commit', $this->passos);
        self::assertContains('close', $this->passos);
        self::assertContains('rollback', $this->passos);
    }

    /** @return iterable<string, array{DestinoDaTransacao, bool}> */
    public static function destinosDoCommit(): iterable
    {
        yield 'aborted → removido'      => [DestinoDaTransacao::NaoConfirmada, false];
        yield 'committed → preservado'  => [DestinoDaTransacao::Confirmada, true];
        yield 'incerto → preservado'    => [DestinoDaTransacao::Incerta, true];
    }

    #[TestDox('o próprio COMMIT falha e o banco responde $destino')]
    #[DataProvider('destinosDoCommit')]
    public function testFalhaNoCommitSegueODestino(DestinoDaTransacao $destino, bool $arquivoFica): void
    {
        $this->falhaNoCommit = new \RuntimeException('server closed the connection unexpectedly');

        $capturada = $this->executarEsperandoFalha($destino, static fn (): null => null);

        self::assertSame($this->falhaNoCommit, $capturada);
        self::assertSame([self::XID], $this->consultados, 'o destino é perguntado pelo xid lido ANTES do COMMIT');
        self::assertSame($arquivoFica, $this->armazenamento->existe($this->novo));
        self::assertContains('close', $this->passos, 'um commit() explícito que falha não fecha o EM sozinho');
        self::assertNotContains('rollback', $this->passos, 'depois do COMMIT não há o que desfazer');

        $erro = $this->logger->doNivel('error')[0];
        self::assertSame(self::XID, $erro['contexto']['xid']);
        self::assertSame($destino->value, $erro['contexto']['destino']);

        if ($arquivoFica) {
            self::assertSame($this->novo->comoTexto(), $this->logger->doNivel('warning')[0]['contexto']['chave']);
        }
    }

    #[TestDox('transação aberta por fora: qualquer falha é incerta, e o arquivo fica')]
    public function testTransacaoAninhadaEhSempreIncerta(): void
    {
        $this->nivelInicial = 1;

        $this->executarEsperandoFalha(
            DestinoDaTransacao::NaoConfirmada,
            static fn (): never => throw new \RuntimeException('falhou dentro'),
        );

        self::assertTrue($this->armazenamento->existe($this->novo));
        self::assertSame(1, $this->nivel, 'desfez só o nível que ela abriu, não o de fora');

        $this->passos        = [];
        $this->falhaNoCommit = new \RuntimeException('release falhou');
        $this->executarEsperandoFalha(DestinoDaTransacao::NaoConfirmada, static fn (): null => null);

        self::assertTrue($this->armazenamento->existe($this->novo));
        self::assertSame([], $this->consultados, 'aninhada: o destino é de quem abriu por fora');
    }

    /**
     * O caso que separa "desfaz o nível que abriu" de "desfaz se houver transação ativa" (a regra
     * do `wrapInTransaction`): o BEGIN falha antes de o nível subir, com uma transação aberta por
     * fora. A regra antiga desfaria o nível de QUEM ABRIU.
     */
    #[TestDox('BEGIN que falha antes de subir o nível, dentro de transação de fora: nada é desfeito')]
    public function testBeginQueFalhaNaoDesfazANivelDeFora(): void
    {
        $this->nivelInicial   = 1;
        $this->falhaNoConnect = new \RuntimeException('não conectou');

        $capturada = $this->executarEsperandoFalha(DestinoDaTransacao::NaoConfirmada, static fn (): null => null);

        self::assertSame($this->falhaNoConnect, $capturada);
        self::assertNotContains('rollback', $this->passos, 'o nível de fora não é desta transação');
        self::assertSame(1, $this->nivel);
        self::assertTrue($this->armazenamento->existe($this->novo), 'aninhada: incerta');
    }

    #[TestDox('a decisão que falha no meio (arquivosNovos lança) não troca a exceção original')]
    public function testDecisaoQueFalhaNaoMascara(): void
    {
        $original  = new \RuntimeException('o banco recusou');
        $capturada = null;

        try {
            $this->transacao(DestinoDaTransacao::NaoConfirmada)->executar(
                static fn (): never => throw $original,
                static fn (): never => throw new \LogicException('a lista de arquivos quebrou'),
                'teste',
            );
        } catch (\Throwable $e) {
            $capturada = $e;
        }

        self::assertSame($original, $capturada);
        self::assertSame('Falha ao decidir o destino do arquivo novo.', $this->logger->doNivel('error')[0]['mensagem']);
        self::assertTrue($this->armazenamento->existe($this->novo));
    }

    #[TestDox('a consulta do destino que lança é "incerta": o arquivo fica e a exceção original sobe')]
    public function testConsultaQueLancaEhIncerta(): void
    {
        $this->falhaNoCommit   = new \RuntimeException('conexão perdida no COMMIT');
        $this->falhaNaConsulta = new \LogicException('logger quebrado');

        $capturada = $this->executarEsperandoFalha(DestinoDaTransacao::NaoConfirmada, static fn (): null => null);

        self::assertSame($this->falhaNoCommit, $capturada);
        self::assertTrue($this->armazenamento->existe($this->novo));
        self::assertSame(DestinoDaTransacao::Incerta->value, $this->logger->doNivel('error')[0]['contexto']['destino']);
    }

    #[TestDox('a remoção que falha não mascara a exceção original')]
    public function testLimpezaQueFalhaNaoMascara(): void
    {
        $original = new \RuntimeException('o banco recusou');
        $this->armazenamento->falhaAoExcluir = static fn (): \Throwable => new \LogicException('disco recusou');

        $capturada = $this->executarEsperandoFalha(
            DestinoDaTransacao::NaoConfirmada,
            static fn (): never => throw $original,
        );

        self::assertSame($original, $capturada);
        self::assertTrue($this->armazenamento->existe($this->novo));
    }

    #[TestDox('arquivosNovos é lido na hora da falha — enxerga o que o trabalho gravou')]
    public function testArquivosNovosEnxergaOQueOTrabalhoGravou(): void
    {
        $gravado = null;
        $this->falhaNoFlush = new \RuntimeException('flush recusado');
        $capturada = null;

        try {
            $this->transacao(DestinoDaTransacao::Incerta)->executar(
                function () use (&$gravado): void {
                    $gravado = $this->novo;
                },
                function () use (&$gravado): array {
                    return $gravado === null ? [] : [$gravado];
                },
                'teste',
            );
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame($this->falhaNoFlush, $capturada);
        self::assertFalse($this->armazenamento->existe($this->novo));
    }

    #[TestDox('só o destino NaoConfirmada autoriza descartar o arquivo novo')]
    public function testSoNaoConfirmadaAutorizaDescartar(): void
    {
        self::assertTrue(DestinoDaTransacao::NaoConfirmada->permiteDescartarArquivoNovo());
        self::assertFalse(DestinoDaTransacao::Confirmada->permiteDescartarArquivoNovo());
        self::assertFalse(DestinoDaTransacao::Incerta->permiteDescartarArquivoNovo());
    }

    // ------------------------------------------------------------ apoio

    private function executarEsperandoFalha(DestinoDaTransacao $destino, callable $trabalho): \Throwable
    {
        $capturada = null;

        try {
            $this->transacao($destino)->executar(
                $trabalho,
                fn (): array => [$this->novo],
                'teste',
            );
        } catch (\Throwable $e) {
            $capturada = $e;
        }

        self::assertNotNull($capturada, 'a transação devia ter falhado');

        return $capturada;
    }

    private function transacao(DestinoDaTransacao $destino): TransacaoComArquivoNovo
    {
        $this->nivel = $this->nivelInicial;

        $conexao = $this->createMock(Connection::class);
        $conexao->method('getTransactionNestingLevel')->willReturnCallback(fn (): int => $this->nivel);
        $conexao->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->nivel > 0);
        $conexao->method('beginTransaction')->willReturnCallback(function (): void {
            if ($this->falhaNoConnect !== null) {
                throw $this->falhaNoConnect;
            }
            $this->passos[] = 'begin';
            ++$this->nivel; // como o DBAL: incrementa antes de chamar o driver
            if ($this->falhaNoBegin !== null) {
                throw $this->falhaNoBegin;
            }
        });
        $conexao->method('fetchOne')->willReturnCallback(function (string $sql): string {
            self::assertStringContainsString('pg_current_xact_id()', $sql);
            $this->passos[] = 'xid';
            if ($this->falhaNoXid !== null) {
                throw $this->falhaNoXid;
            }

            return self::XID;
        });
        $conexao->method('commit')->willReturnCallback(function (): void {
            $this->passos[] = 'commit';
            --$this->nivel;
            if ($this->falhaNoCommit !== null) {
                throw $this->falhaNoCommit;
            }
        });
        $conexao->method('rollBack')->willReturnCallback(function (): void {
            $this->passos[] = 'rollback';
            --$this->nivel;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexao);
        $em->method('flush')->willReturnCallback(function (): void {
            $this->passos[] = 'flush';
            if ($this->falhaNoFlush !== null) {
                throw $this->falhaNoFlush;
            }
        });
        $em->method('close')->willReturnCallback(function (): void {
            $this->passos[] = 'close';
        });

        $registrar = function (string $xid): void {
            $this->consultados[] = $xid;
            if ($this->falhaNaConsulta !== null) {
                throw $this->falhaNaConsulta;
            }
        };

        $consulta = new class ($destino, $registrar) implements ConsultaDeDestinoDaTransacao {
            public function __construct(private readonly DestinoDaTransacao $destino, private readonly \Closure $registrar)
            {
            }

            public function destinoDe(string $xid): DestinoDaTransacao
            {
                ($this->registrar)($xid);

                return $this->destino;
            }
        };

        return new TransacaoComArquivoNovo(
            $em,
            $consulta,
            new RemocaoAposTransacao($this->armazenamento, $this->logger),
            $this->logger,
        );
    }
}
