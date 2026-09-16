<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\SubstituirAnexoDoLoteUseCase;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Tests\Ponto\Doubles\StorageDeDiscoParaTeste;
use Symfony\Component\Validator\Validation;

/**
 * O caminho de FALHA da fase 1 — o único que a suíte funcional não alcança, e justamente aquele
 * cuja existência é a garantia de consistência.
 *
 * `app/tests/CLAUDE.md` diz para não mockar `EntityManagerInterface` diretamente. Aqui a exceção
 * é o próprio objeto do teste: o que se quer provar é o comportamento quando a TRANSAÇÃO falha,
 * e não existe outra forma de provocar isso sem derrubar a conexão de verdade. Sob DAMA a
 * transação do teste é aninhada, então o commit real nunca falha.
 *
 * A falha mais provável da fase 1 é o COMMIT, não o `flush()`: `wrapInTransaction` executa o
 * closure, e só DEPOIS faz flush e commit, por fora dele. Um `try/catch` interno ao closure não
 * veria essa falha — foi por isso que o tratamento passou a envolver a CHAMADA. Este teste fixa
 * esse comportamento: se a transação falhar, o arquivo novo não pode sobrar em disco.
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

    #[TestDox('Falha no COMMIT da fase 1 não deixa o arquivo novo órfão em disco')]
    public function testFalhaDeCommitRemoveOArquivoNovo(): void
    {
        $tenant        = $this->tenant(7);
        $justificativa = $this->justificativa($tenant, 'antigo.pdf', 'lote-1');

        // Simula o wrapInTransaction real: roda o closure e, DEPOIS dele, estoura — como um
        // commit que falha por queda de conexão ou statement_timeout.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->conexaoEmTransacao());
        $em->method('wrapInTransaction')->willReturnCallback(
            static function (callable $func): never {
                $func();

                throw new \RuntimeException('commit falhou');
            },
        );

        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->method('findLotePorBatchId')->willReturn([$justificativa]);

        $storage = new StorageDeDiscoParaTeste();

        $armazenamento = $this->espiao($this->armazenamentoNoDiretorioDoTeste());

        $useCase = new SubstituirAnexoDoLoteUseCase(
            $em,
            $repositorio,
            $storage,
            $armazenamento,
            Validation::createValidator(),
            new NullLogger(),
            $this->diretorio,
        );

        try {
            $useCase->executar($justificativa, $this->upload(), $tenant);
            self::fail('a falha da transação deveria ter sido propagada');
        } catch (\RuntimeException $e) {
            self::assertSame('commit falhou', $e->getMessage());
        }

        // O closure apontou o registro em memória para o arquivo novo antes de o commit falhar:
        // é assim que se sabe o nome que chegou a ser gravado.
        $novo = $justificativa->getAnexoPath();
        self::assertNotSame('antigo.pdf', $novo, 'o arquivo novo chegou a ser gravado');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $novo);
        self::assertFileDoesNotExist(
            $this->diretorio . '/' . $novo,
            'o arquivo novo tinha de ter sido removido: o banco nunca chegou a referenciá-lo',
        );
        self::assertSame(['antigo.pdf'], $this->arquivosNoDiretorio(), 'nada além do anexo antigo pode sobrar');
        self::assertFileExists(
            $this->diretorio . '/antigo.pdf',
            'o anexo ANTIGO não podia ser tocado — a fase 2 nem deveria ter rodado',
        );

        // R1: o diretório de justificativas é plano, então o escopo só aparece na chave. Ela tem
        // de ser a que a leitura monta a partir do registro que passou a apontar para o arquivo.
        self::assertCount(1, $armazenamento->gravadas);
        self::assertSame(CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $armazenamento->gravadas[0]->categoria);
        self::assertSame(7, $armazenamento->gravadas[0]->escopo->tenantIdOuNull());
        self::assertTrue($armazenamento->gravadas[0]->ehIgualA(ChavesDePonto::anexoDeJustificativa($justificativa)));
    }

    #[TestDox('Falha do storage na fase 1: nada é apontado, nada é gravado, o anexo antigo fica')]
    public function testFalhaDoStorageNaoMexeEmNada(): void
    {
        $tenant        = $this->tenant(7);
        $justificativa = $this->justificativa($tenant, 'antigo.pdf', 'lote-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->conexaoEmTransacao());
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func());
        $em->expects(self::never())->method('flush');

        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->method('findLotePorBatchId')->willReturn([$justificativa]);
        $repositorio->method('anexoNoBancoPorId')->willReturn('antigo.pdf');

        $armazenamento = $this->espiao($this->armazenamentoNoDiretorioDoTeste(), new FalhaDeArmazenamento('disco cheio'));

        $useCase = new SubstituirAnexoDoLoteUseCase(
            $em,
            $repositorio,
            new StorageDeDiscoParaTeste(),
            $armazenamento,
            Validation::createValidator(),
            new NullLogger(),
            $this->diretorio,
        );

        try {
            $useCase->executar($justificativa, $this->upload(), $tenant);
            self::fail('a falha do storage deveria ter sido propagada');
        } catch (FalhaDeArmazenamento $e) {
            self::assertSame('disco cheio', $e->getMessage());
        }

        self::assertSame('antigo.pdf', $justificativa->getAnexoPath(), 'nenhum registro pode apontar para um arquivo que não foi gravado');
        self::assertSame(['antigo.pdf'], $this->arquivosNoDiretorio());
    }

    #[TestDox('Justificativa de outro escritório é recusada sem gravar nada')]
    public function testTenantDivergenteNaoGravaNada(): void
    {
        $justificativa = $this->justificativa($this->tenant(7), 'antigo.pdf', 'lote-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');

        $storage = new StorageDeDiscoParaTeste();

        $useCase = new SubstituirAnexoDoLoteUseCase(
            $em,
            $this->createMock(JustificativaPontoRepository::class),
            $storage,
            $this->armazenamentoNoDiretorioDoTeste(),
            Validation::createValidator(),
            new NullLogger(),
            $this->diretorio,
        );

        $this->expectException(\LogicException::class);

        try {
            $useCase->executar($justificativa, $this->upload(), $this->tenant(99));
        } finally {
            self::assertSame(['antigo.pdf'], $this->arquivosNoDiretorio(), 'nada podia ter sido gravado em disco');
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * O armazenamento novo precisa enxergar o MESMO diretório do dublê de disco antigo: desde a
     * E2.4A ele grava (por chave) e, desde a E2.2, responde a presença; a remoção ainda é do dublê
     * (por caminho), até a E2.5.
     */
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

    /**
     * Delega ao backend real e anota cada chave gravada; com `$falha`, recusa a gravação antes de
     * tocar em qualquer coisa.
     */
    private function espiao(ArmazenamentoDeArquivos $real, ?\Throwable $falha = null): object
    {
        return new class ($real, $falha) implements ArmazenamentoDeArquivos {
            /** @var list<ChaveDeArquivo> */
            public array $gravadas = [];

            public function __construct(
                private readonly ArmazenamentoDeArquivos $real,
                private readonly ?\Throwable $falha,
            ) {
            }

            public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
            {
                if ($this->falha !== null) {
                    throw $this->falha;
                }

                $armazenado       = $this->real->gravar($destino, $fonte);
                $this->gravadas[] = $armazenado->chave;

                return $armazenado;
            }

            public function abrir(ChaveDeArquivo $chave): mixed
            {
                return $this->real->abrir($chave);
            }

            public function ler(ChaveDeArquivo $chave): string
            {
                return $this->real->ler($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->real->existe($chave);
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                $this->real->excluir($chave);
            }

            public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
            {
                return $this->real->metadados($chave);
            }
        };
    }

    /** @return list<string> */
    private function arquivosNoDiretorio(): array
    {
        $nomes = array_values(array_diff(scandir($this->diretorio) ?: [], ['.', '..']));
        sort($nomes);

        return $nomes;
    }

    private function conexaoEmTransacao(): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('executeStatement')->willReturn(1);

        return $conn;
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
