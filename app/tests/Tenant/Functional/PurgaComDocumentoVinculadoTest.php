<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\PurgaEscritorioResultado;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Purga do escritório com documento do cadastro VINCULADO ao menu Documentos (Inteligência cadastral, F-A): o vinculado
 * não tem arquivo próprio (`caminho_arquivo` vazio). A purga não o trata como arquivo do cliente: a chave vazia não
 * entra nas sobras, o arquivo da pasta sai uma vez só (pela pasta) e nada de outro escritório é tocado.
 */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class PurgaComDocumentoVinculadoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    /** @var list<string> */
    private array $criados = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->criados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        parent::tearDown();
    }

    #[TestDox('vinculado na purga: sem chave vazia nas sobras, o arquivo da pasta sai uma vez e o vizinho fica intacto')]
    public function testVinculadoNaoEhArquivoDoCliente(): void
    {
        $a = $this->tenantEmQuarentena();
        $b = $this->tenantEmQuarentena();
        $deA = $this->clienteComVinculado($a);
        $deB = $this->clienteComVinculado($b);
        $proprioDeB = $this->anexoProprio($b);

        $resultado = static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($this->tenant($a), dryRun: false);

        self::assertInstanceOf(PurgaEscritorioResultado::class, $resultado);
        self::assertSame([], $resultado->arquivosNaoRemovidos, 'a chave vazia do vinculado não vira sobra');
        self::assertSame(1, $resultado->arquivosRemovidos, 'só o arquivo da pasta, uma vez');
        self::assertFileDoesNotExist($deA);
        self::assertFileExists($deB, 'arquivo da pasta do outro escritório');
        self::assertFileExists($proprioDeB, 'anexo próprio do cliente do outro escritório');
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE tenant_id = ?', [$a]));
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE tenant_id = ?', [$b]));
    }

    /** Cliente do escritório com um documento da pasta (arquivo real) vinculado ao cadastro; devolve o caminho do arquivo. */
    private function clienteComVinculado(int $t): string
    {
        $ts = '2020-01-01 00:00:00';
        $cliente = $this->cliente($t);
        $pasta = $this->ins('pasta', ['nup' => 'NUP-' . uniqid(), 'data_abertura' => $ts, 'created_at' => $ts, 'tenant_id' => $t]);
        $nome = $this->nome();
        $doc = $this->ins('pasta_documento', ['titulo' => 'RG', 'categoria' => 'IDENTIFICACAO', 'caminho_arquivo' => $nome, 'nome_original' => 'rg.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'pasta_id' => $pasta, 'tenant_id' => $t]);
        $this->ins('cliente_documento', ['titulo' => 'RG', 'categoria' => 'IDENTIFICACAO', 'caminho_arquivo' => '', 'nome_original' => 'rg.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 0, 'uploaded_at' => $ts, 'cliente_id' => $cliente, 'tenant_id' => $t, 'pasta_documento_id' => $doc]);

        return $this->arquivo('uploads_dir', $nome);
    }

    private function anexoProprio(int $t): string
    {
        $nome = $this->nome();
        $this->ins('cliente_documento', ['titulo' => 'Doc', 'categoria' => 'DEMAIS', 'caminho_arquivo' => $nome, 'nome_original' => 'd.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => '2020-01-01 00:00:00', 'cliente_id' => $this->cliente($t), 'tenant_id' => $t]);

        return $this->arquivo('clientes_uploads_dir', $nome);
    }

    private function cliente(int $t): int
    {
        return $this->ins('cliente', ['email' => 'cli' . uniqid() . '@x.com', 'cep' => '00000-000', 'endereco' => 'Rua', 'cidade' => 'Cidade', 'estado' => 'SP', 'criado_at' => '2020-01-01 00:00:00', 'tipo' => 'PF', 'tenant_id' => $t]);
    }

    private function tenantEmQuarentena(): int
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant II Purga ' . uniqid());
        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $this->em->persist($tenant);
        $this->em->flush();

        return (int) $tenant->getId();
    }

    private function tenant(int $id): Tenant
    {
        $tenant = $this->em->find(Tenant::class, $id);
        self::assertNotNull($tenant);

        return $tenant;
    }

    private function nome(): string
    {
        return bin2hex(random_bytes(16)) . '.pdf';
    }

    private function arquivo(string $parametro, string $nome): string
    {
        $raiz = rtrim((string) static::getContainer()->getParameter($parametro), '/');
        if (!is_dir($raiz)) {
            mkdir($raiz, 0o775, true);
        }
        $caminho = $raiz . '/' . $nome;
        file_put_contents($caminho, 'x');
        $this->criados[] = $caminho;

        return $caminho;
    }

    /** @param array<string, mixed> $dados */
    private function ins(string $tabela, array $dados): int
    {
        $cols = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', array_keys($dados)));
        $ph   = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($dados)));

        return (int) $this->conn->fetchOne(sprintf('INSERT INTO %s (%s) VALUES (%s) RETURNING id', $tabela, $cols, $ph), $dados);
    }
}
