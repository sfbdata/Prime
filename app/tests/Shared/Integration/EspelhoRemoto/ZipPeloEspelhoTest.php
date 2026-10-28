<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\MontarZipDeDocumentosUseCase;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use App\Tests\Shared\Doubles\LoggerEspiao;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O .zip da pasta com documentos que só estão no R2 (spec `leitura-pelo-r2-e-liberacao-de-disco.md`,
 * D3): a libzip só lê os arquivos no `close()`, depois de todos os empréstimos. Com teto minúsculo, o
 * cache não pode descartar o primeiro documento ao hidratar o segundo.
 *
 * O EntityManager é stub pelo mesmo motivo do `MontarZipDeDocumentosUseCaseTest`: aqui ele só recebe
 * o `AuditLog` e o `flush`. A outbox é a de verdade, no PostgreSQL.
 */
#[CoversClass(CacheDeLeituraDoEspelho::class)]
#[CoversClass(ArmazenamentoEspelhado::class)]
final class ZipPeloEspelhoTest extends KernelTestCase
{
    private string $diretorioDoCache;

    /** @var list<string> */
    private array $gerados = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->diretorioDoCache = sys_get_temp_dir() . '/jusprime-teste-cache-zip-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ([...$this->gerados, ...(glob($this->diretorioDoCache . '/{,.}*', \GLOB_BRACE) ?: [])] as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        if (is_dir($this->diretorioDoCache)) {
            rmdir($this->diretorioDoCache);
        }

        parent::tearDown();
    }

    #[TestDox('ZIP com 2 documentos só no R2 e teto de 1 byte: sai completo, com os 2 SHAs certos')]
    public function testZipComDocumentosSoNoEspelho(): void
    {
        $conexao = static::getContainer()->get(Connection::class);
        $outbox  = new OutboxDeReplicacao($conexao, new MockClock('2026-10-28 12:00:00'));
        $espelho = new EspelhoRemotoEmMemoria();
        $tenant  = self::comId((new Tenant())->setName('Escritório R2'), 7);
        $pasta   = self::comId((new Pasta())->setNup('R2-1')->setTenant($tenant), 9);

        $conteudos  = ['um.pdf' => "%PDF-1.4\nprimeiro documento\n", 'dois.pdf' => "%PDF-1.4\nsegundo documento, maior\n"];
        $documentos = [];
        foreach ($conteudos as $nome => $conteudo) {
            $doc   = $this->documento($pasta, $tenant, $nome, $conteudo, 100 + \count($documentos));
            $chave = ChavesDePasta::documento($doc);
            $id    = $outbox->registrarEnvio($chave, hash('sha256', $conteudo), \strlen($conteudo), 'application/pdf');
            $conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE id = ?", [$id]);
            $espelho->semear($chave, $conteudo, null, 'application/pdf');
            $documentos[] = $doc;
        }

        $espelhado = new ArmazenamentoEspelhado(
            new ArmazenamentoEmMemoria(),
            $outbox,
            new PoliticaDeEspelhamento('pasta_documento'),
            new LoggerEspiao(),
            ProvedorDoEspelhoFixo::com($espelho),
            new CacheDeLeituraDoEspelho($this->diretorioDoCache, 1),
        );

        $saida           = $this->useCase($espelhado, $documentos)->executar($pasta, $documentos, [], self::comId((new User())->setEmail('ana@r2.com')->setFullName('Ana'), 3), $tenant);
        $this->gerados[] = $saida->arquivo->caminho();

        self::assertSame(2, $saida->arquivos);
        self::assertSame(0, $saida->naoEncontrados);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($saida->arquivo->caminho()));
        foreach ($conteudos as $nome => $conteudo) {
            self::assertSame(hash('sha256', $conteudo), hash('sha256', (string) $zip->getFromName($nome)), $nome);
        }
        $zip->close();
        self::assertSame(2, $espelho->downloads);
    }

    /** @param list<PastaDocumento> $documentos */
    private function useCase(ArmazenamentoEspelhado $materializador, array $documentos): MontarZipDeDocumentosUseCase
    {
        $repositorio = $this->createStub(PastaDocumentoRepository::class);
        $repositorio->method('findByPastaComSecao')->willReturn($documentos);
        $secoes = $this->createStub(PastaSecaoRepository::class);
        $secoes->method('findByPasta')->willReturn([]);

        return new MontarZipDeDocumentosUseCase(
            $repositorio,
            $secoes,
            $materializador,
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-10-28 12:00:00'),
            new LoggerEmMemoria(),
            new ResolvedorIdentificadorDaPasta($this->createStub(CasoCobrancaRepository::class), new ComporNomeDaPastaJudicial()),
        );
    }

    private function documento(Pasta $pasta, Tenant $tenant, string $nome, string $conteudo, int $id): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo($nome);
        $doc->setNomeOriginal($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo(bin2hex(random_bytes(6)) . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(\strlen($conteudo));

        return self::comId($doc, $id);
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
