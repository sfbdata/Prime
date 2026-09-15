<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\SubstituirAnexoDoLoteUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

/**
 * O caminho de FALHA da fase 1 — o único que a suíte funcional não alcança, e justamente aquele
 * cuja existência é a garantia de consistência.
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

        $useCase = new SubstituirAnexoDoLoteUseCase(
            $em,
            $repositorio,
            $storage,
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

        self::assertNotNull($storage->ultimoNomeSalvo, 'o arquivo novo chegou a ser gravado');
        self::assertFileDoesNotExist(
            $this->diretorio . '/' . $storage->ultimoNomeSalvo,
            'o arquivo novo tinha de ter sido removido: o banco nunca chegou a referenciá-lo',
        );
        self::assertFileExists(
            $this->diretorio . '/antigo.pdf',
            'o anexo ANTIGO não podia ser tocado — a fase 2 nem deveria ter rodado',
        );
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
            Validation::createValidator(),
            new NullLogger(),
            $this->diretorio,
        );

        $this->expectException(\LogicException::class);

        try {
            $useCase->executar($justificativa, $this->upload(), $this->tenant(99));
        } finally {
            self::assertNull($storage->ultimoNomeSalvo, 'nada podia ter sido gravado em disco');
        }
    }

    // ------------------------------------------------------------------ helpers

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

/**
 * Storage mínimo que mexe em disco de verdade — mock não serviria, porque o que se quer provar é
 * que o ARQUIVO deixou de existir.
 */
final class StorageDeDiscoParaTeste implements ArquivoStorageInterface
{
    public ?string $ultimoNomeSalvo = null;

    public function salvar(UploadedFile $arquivo, string $diretorio): string
    {
        $nome = bin2hex(random_bytes(8)) . '.pdf';
        copy($arquivo->getPathname(), $diretorio . '/' . $nome);
        $this->ultimoNomeSalvo = $nome;

        return $nome;
    }

    public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string
    {
        $nome = bin2hex(random_bytes(8)) . '.' . ltrim($extensao, '.');
        file_put_contents($diretorio . '/' . $nome, $conteudo);

        return $nome;
    }

    public function moverParaArmazenamento(string $caminhoOrigem, string $diretorio, string $extensao): string
    {
        $nome = bin2hex(random_bytes(8)) . '.' . ltrim($extensao, '.');
        rename($caminhoOrigem, $diretorio . '/' . $nome);

        return $nome;
    }

    public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return new \Symfony\Component\HttpFoundation\BinaryFileResponse($caminhoCompleto);
    }

    public function excluir(string $caminhoCompleto): void
    {
        if (file_exists($caminhoCompleto)) {
            unlink($caminhoCompleto);
        }
    }

    public function existe(string $caminhoCompleto): bool
    {
        return file_exists($caminhoCompleto);
    }

    public function caminho(string $diretorio, string $nomeArquivo): string
    {
        return $diretorio . '/' . $nomeArquivo;
    }
}
