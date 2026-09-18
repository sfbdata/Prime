<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Tenant;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\SubstituirAnexoDoLoteUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Shared\Doubles\ArmazenamentoEspiao;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

/**
 * O caminho de FALHA da fase 1 — o único que a suíte funcional não alcança sem ajuda, e justamente
 * aquele cuja existência é a garantia de consistência.
 *
 * `app/tests/CLAUDE.md` diz para não mockar `EntityManagerInterface` diretamente. Aqui a exceção
 * é o próprio objeto do teste: o que se quer provar é o comportamento quando a TRANSAÇÃO falha, em
 * cada fase dela. A decisão sobre o arquivo é da `TransacaoComArquivoNovo` REAL; só a conexão e o
 * EntityManager são dublês. (O funcional `SubstituirAnexoDoLoteUseCaseTest` prova o mesmo contra o
 * banco, com o COMMIT falhando de verdade pelo `FalhaDeCommitArmavel`.)
 *
 * **A política mudou na E2.5.** Antes, qualquer falha apagava o arquivo novo — inclusive um COMMIT
 * que chegou ao servidor e só perdeu a resposta, deixando o lote inteiro apontando para o vazio.
 * Agora só a ausência PROVADA de COMMIT autoriza apagar.
 */
#[CoversClass(SubstituirAnexoDoLoteUseCase::class)]
final class SubstituirAnexoDoLoteFalhaTest extends TestCase
{
    private string $diretorio;

    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/just-falha-' . bin2hex(random_bytes(8));
        mkdir($this->diretorio, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->diretorio)) {
            exec('rm -rf ' . escapeshellarg($this->diretorio));
        }
    }

    /** @return iterable<string, array{DestinoDaTransacao, bool}> */
    public static function destinosDoCommit(): iterable
    {
        yield 'banco prova aborted → o arquivo novo sai'   => [DestinoDaTransacao::NaoConfirmada, false];
        yield 'banco prova committed → o arquivo novo fica' => [DestinoDaTransacao::Confirmada, true];
        yield 'sem prova → o arquivo novo fica'             => [DestinoDaTransacao::Incerta, true];
    }

    #[TestDox('Falha no COMMIT da fase 1 e $destino')]
    #[DataProvider('destinosDoCommit')]
    public function testFalhaDeCommitSegueODestino(DestinoDaTransacao $destino, bool $novoFica): void
    {
        $tenant        = $this->tenant(7);
        $justificativa = $this->justificativa($tenant, 'antigo.pdf', 'lote-1');

        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->method('findLotePorBatchId')->willReturn([$justificativa]);

        $armazenamento = new ArmazenamentoEspiao($this->armazenamentoNoDiretorioDoTeste());
        $logger        = new LoggerEmMemoria();
        $useCase       = $this->useCase(
            $this->emQueFalha(noCommit: new \RuntimeException('commit falhou')),
            $repositorio,
            $armazenamento,
            $destino,
            $logger,
        );

        $capturada = null;
        try {
            $useCase->executar($justificativa, $this->upload(), $tenant);
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame('commit falhou', $capturada?->getMessage(), 'a exceção original sobe');

        // O closure apontou o registro em memória para o arquivo novo antes de o commit falhar:
        // é assim que se sabe o nome que chegou a ser gravado.
        $novo = $justificativa->getAnexoPath();
        self::assertNotSame('antigo.pdf', $novo, 'o arquivo novo chegou a ser gravado');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $novo);
        self::assertSame(
            $novoFica,
            is_file($this->diretorio . '/' . $novo),
            $novoFica
                ? 'COMMIT de destino não provado: o lote pode estar apontando para o arquivo, que tem de ficar (INV-6)'
                : 'o banco provou que nada foi confirmado: o arquivo novo não pode sobrar',
        );
        self::assertFileExists(
            $this->diretorio . '/antigo.pdf',
            'o anexo ANTIGO não podia ser tocado — a fase 2 nem deveria ter rodado',
        );

        if ($novoFica) {
            self::assertSame($destino->value, $logger->doNivel('error')[0]['contexto']['destino']);
            self::assertStringEndsWith($novo, $logger->doNivel('warning')[0]['contexto']['chave']);
        }

        // R1: o diretório de justificativas é plano, então o escopo só aparece na chave. Ela tem
        // de ser a que a leitura monta a partir do registro que passou a apontar para o arquivo.
        self::assertCount(1, $armazenamento->gravadas);
        self::assertSame(CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $armazenamento->gravadas[0]->categoria);
        self::assertSame(7, $armazenamento->gravadas[0]->escopo->tenantIdOuNull());
        self::assertTrue($armazenamento->gravadas[0]->ehIgualA(ChavesDePonto::anexoDeJustificativa($justificativa)));
    }

    #[TestDox('Falha ANTES do COMMIT (flush recusado): o arquivo novo sai, sem perguntar ao banco')]
    public function testFalhaAntesDoCommitRemoveOArquivoNovo(): void
    {
        $tenant        = $this->tenant(7);
        $justificativa = $this->justificativa($tenant, 'antigo.pdf', 'lote-1');

        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->method('findLotePorBatchId')->willReturn([$justificativa]);

        $useCase = $this->useCase(
            $this->emQueFalha(noFlush: new \RuntimeException('flush recusado')),
            $repositorio,
            $this->armazenamentoNoDiretorioDoTeste(),
            DestinoDaTransacao::Confirmada, // não pode ser consultada: o COMMIT nem foi enviado
        );

        $capturada = null;
        try {
            $useCase->executar($justificativa, $this->upload(), $tenant);
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame('flush recusado', $capturada?->getMessage());
        self::assertSame(['antigo.pdf'], $this->arquivosNoDiretorio(), 'nada além do anexo antigo pode sobrar');
    }

    #[TestDox('Falha do storage na fase 1: nada é apontado, nada é gravado, o anexo antigo fica')]
    public function testFalhaDoStorageNaoMexeEmNada(): void
    {
        $tenant        = $this->tenant(7);
        $justificativa = $this->justificativa($tenant, 'antigo.pdf', 'lote-1');

        $em = $this->emQueFalha();
        $em->expects(self::never())->method('flush');

        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->method('findLotePorBatchId')->willReturn([$justificativa]);
        $repositorio->method('anexoNoBancoPorId')->willReturn('antigo.pdf');

        $useCase = $this->useCase($em, $repositorio, $this->armazenamentoQueRecusa(), DestinoDaTransacao::Incerta);

        $capturada = null;
        try {
            $useCase->executar($justificativa, $this->upload(), $tenant);
        } catch (FalhaDeArmazenamento $e) {
            $capturada = $e;
        }

        self::assertSame('disco cheio', $capturada?->getMessage());
        self::assertSame('antigo.pdf', $justificativa->getAnexoPath(), 'nenhum registro pode apontar para um arquivo que não foi gravado');
        self::assertSame(['antigo.pdf'], $this->arquivosNoDiretorio());
    }

    #[TestDox('Justificativa de outro escritório é recusada sem gravar nada')]
    public function testTenantDivergenteNaoGravaNada(): void
    {
        $justificativa = $this->justificativa($this->tenant(7), 'antigo.pdf', 'lote-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getConnection');

        $useCase = $this->useCase(
            $em,
            $this->createMock(JustificativaPontoRepository::class),
            $this->armazenamentoNoDiretorioDoTeste(),
            DestinoDaTransacao::Incerta,
        );

        $this->expectException(\LogicException::class);

        try {
            $useCase->executar($justificativa, $this->upload(), $this->tenant(99));
        } finally {
            self::assertSame(['antigo.pdf'], $this->arquivosNoDiretorio(), 'nada podia ter sido gravado em disco');
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Todas as categorias no diretório do teste: aqui só importa a de justificativas. */
    private function armazenamentoNoDiretorioDoTeste(): ArmazenamentoLocal
    {
        return new ArmazenamentoLocal(new ResolvedorDeCaminhoLocal(
            uploadsDir: $this->diretorio,
            clientesUploadsDir: $this->diretorio,
            chamadosUploadsDir: $this->diretorio,
            justificativasUploadsDir: $this->diretorio,
            fotosPerfilDir: $this->diretorio,
            cobrancasUploadsDir: $this->diretorio,
            kanbanUploadsDir: $this->diretorio,
        ));
    }

    /** @return list<string> */
    private function arquivosNoDiretorio(): array
    {
        $nomes = array_values(array_diff(scandir($this->diretorio) ?: [], ['.', '..']));
        sort($nomes);

        return $nomes;
    }

    private function useCase(
        EntityManagerInterface $em,
        JustificativaPontoRepository $repositorio,
        ArmazenamentoDeArquivos $armazenamento,
        DestinoDaTransacao $destino,
        ?LoggerEmMemoria $logger = null,
    ): SubstituirAnexoDoLoteUseCase {
        $logger ??= new LoggerEmMemoria();
        $remocao  = new RemocaoAposTransacao($armazenamento, $logger);
        $consulta = new class ($destino) implements ConsultaDeDestinoDaTransacao {
            public function __construct(private readonly DestinoDaTransacao $destino)
            {
            }

            public function destinoDe(string $xid): DestinoDaTransacao
            {
                return $this->destino;
            }
        };

        return new SubstituirAnexoDoLoteUseCase(
            $em,
            $repositorio,
            $armazenamento,
            new TransacaoComArquivoNovo($em, $consulta, $remocao, $logger),
            $remocao,
            Validation::createValidator(),
            $logger,
        );
    }

    /**
     * EntityManager + conexão que se comportam como a transação real — nível de aninhamento,
     * trava, xid — e falham onde o teste pedir.
     */
    private function emQueFalha(?\Throwable $noFlush = null, ?\Throwable $noCommit = null): EntityManagerInterface
    {
        $nivel = 0;

        $conn = $this->createMock(Connection::class);
        $conn->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$nivel): int {
            return $nivel;
        });
        $conn->method('isTransactionActive')->willReturnCallback(static function () use (&$nivel): bool {
            return $nivel > 0;
        });
        $conn->method('beginTransaction')->willReturnCallback(static function () use (&$nivel): void {
            ++$nivel;
        });
        $conn->method('rollBack')->willReturnCallback(static function () use (&$nivel): void {
            --$nivel;
        });
        $conn->method('commit')->willReturnCallback(static function () use (&$nivel, $noCommit): void {
            --$nivel;
            if ($noCommit !== null) {
                throw $noCommit;
            }
        });
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('fetchOne')->willReturn('11117396');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        if ($noFlush !== null) {
            $em->method('flush')->willThrowException($noFlush);
        }

        return $em;
    }

    /** Recusa a gravação antes de tocar em qualquer coisa. */
    private function armazenamentoQueRecusa(): ArmazenamentoDeArquivos
    {
        $memoria                = new \App\Tests\Shared\Doubles\ArmazenamentoEmMemoria();
        $memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        return $memoria;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('T' . $id);
        (new \ReflectionProperty($tenant, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function justificativa(Tenant $tenant, string $anexo, string $batchId): JustificativaPonto
    {
        file_put_contents($this->diretorio . '/' . $anexo, '%PDF-1.4 antigo');

        $j = new JustificativaPonto();
        $j->setTenant($tenant);
        $j->setData(new \DateTime('2026-03-01'));
        $j->setAnexoPath($anexo);
        $j->setBatchId($batchId);

        return $j;
    }

    private function upload(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/novo-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($origem, "%PDF-1.4\n% novo\n");

        return new UploadedFile($origem, 'novo.pdf', 'application/pdf', null, true);
    }
}
