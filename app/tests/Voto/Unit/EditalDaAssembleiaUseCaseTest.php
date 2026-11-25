<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\AnexarEditalDaAssembleiaUseCase;
use App\Voto\UseCase\ExcluirRascunhoDaAssembleiaUseCase;
use App\Voto\UseCase\RemoverEditalDaAssembleiaUseCase;
use App\Voto\Service\FotosDasCandidaturas;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Edital em PDF da assembleia: anexar, trocar e remover. Só PDF de verdade, até 10 MB; só antes do resultado; o arquivo
 * antigo só sai DEPOIS do COMMIT; o escopo do arquivo é o do escritório dono. A `TransacaoComArquivoNovo` é a REAL.
 */
#[CoversClass(AnexarEditalDaAssembleiaUseCase::class)]
#[CoversClass(RemoverEditalDaAssembleiaUseCase::class)]
#[CoversClass(ExcluirRascunhoDaAssembleiaUseCase::class)]
#[CoversClass(Assembleia::class)]
final class EditalDaAssembleiaUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private Assembleia $assembleia;
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private RemocaoAposTransacao $remocao;
    private TransacaoComArquivoNovo $transacao;
    private string $dirTemp;

    /** @var list<string> */
    private array $ordem = [];
    private ?int $paginas = 4;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->assembleia = new Assembleia($this->tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        (new \ReflectionProperty(Assembleia::class, 'id'))->setValue($this->assembleia, 5);
        $this->dirTemp = sys_get_temp_dir() . '/voto-edital-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->armazenamento->aoExcluir = function (): void {
            $this->ordem[] = 'excluir';
        };
        $this->logger = new LoggerEmMemoria();
        $this->remocao = new RemocaoAposTransacao($this->armazenamento, $this->logger);
        $this->transacao = new TransacaoComArquivoNovo($this->emDeTeste(), new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), $this->remocao, $this->logger);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
    }

    #[TestDox('Anexar: grava no escopo do escritório (VOTO_EDITAL) e a assembleia guarda nome, tamanho e sha256')]
    public function testAnexa(): void
    {
        $pdf = $this->pdf();
        $trocou = $this->anexar($this->upload($pdf, 'Edital AGO 2026.pdf'));

        self::assertFalse($trocou);
        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::VOTO_EDITAL, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame($chave->nome, $this->assembleia->getEditalChave());
        self::assertStringEndsWith('.pdf', $chave->nome);
        self::assertSame('Edital AGO 2026.pdf', $this->assembleia->getEditalNome());
        self::assertSame(hash('sha256', $pdf), $this->assembleia->getEditalSha256());
        self::assertSame(\strlen($pdf), $this->assembleia->getEditalTamanho());
        self::assertSame(['commit'], $this->ordem);
    }

    #[TestDox('Trocar: grava o novo, COMMIT, e só depois apaga o anterior')]
    public function testTrocaApagaOAntigoDepoisDoCommit(): void
    {
        $this->anexar($this->upload($this->pdf('um')));
        $antigo = ChavesDoVoto::edital($this->assembleia);
        $this->ordem = [];

        $trocou = $this->anexar($this->upload($this->pdf('dois')));

        self::assertTrue($trocou);
        self::assertNotSame($antigo->nome, $this->assembleia->getEditalChave());
        self::assertSame(['commit', 'excluir'], $this->ordem);
        self::assertFalse($this->armazenamento->existe($antigo));
        self::assertTrue($this->armazenamento->existe(ChavesDoVoto::edital($this->assembleia)));
    }

    #[TestDox('Recusa o que não é PDF mesmo com extensão .pdf, e nada é gravado')]
    public function testRecusaNaoPdf(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('PDF');

        try {
            $this->anexar($this->upload('<?php echo 1;', 'edital.pdf'));
        } finally {
            self::assertNull($this->assembleia->getEditalChave());
            self::assertSame([], $this->armazenamento->excluidas);
        }
    }

    #[TestDox('Recusa PDF acima de 10 MB')]
    public function testRecusaGrande(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('10 MB');

        $this->anexar($this->upload($this->pdf() . str_repeat('0', AnexarEditalDaAssembleiaUseCase::TAMANHO_MAX)));
    }

    #[TestDox('Recusa PDF que o contador de páginas não consegue ler')]
    public function testRecusaIlegivel(): void
    {
        $this->paginas = null;
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Não foi possível ler');

        $this->anexar($this->upload($this->pdf()));
    }

    #[TestDox('Com resultado já apurado (pauta encerrada), anexar é bloqueado e nada é gravado')]
    public function testBloqueiaDepoisDoResultado(): void
    {
        $this->encerrarUmaPauta();

        try {
            $this->anexar($this->upload($this->pdf()));
            self::fail('deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertStringContainsString('resultado já saiu', $e->getMessage());
        }
        self::assertNull($this->assembleia->getEditalChave());
        self::assertFalse($this->assembleia->podeAlterarEdital());
    }

    #[TestDox('Com resultado já apurado, remover também é bloqueado e o arquivo fica')]
    public function testRemoverBloqueadoDepoisDoResultado(): void
    {
        $this->anexar($this->upload($this->pdf()));
        $chave = ChavesDoVoto::edital($this->assembleia);
        $this->encerrarUmaPauta();

        try {
            $this->remover();
            self::fail('deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException) {
        }
        self::assertTrue($this->armazenamento->existe($chave));
        self::assertTrue($this->assembleia->temEdital());
    }

    #[TestDox('Remover: tira o edital e o arquivo sai só depois do flush')]
    public function testRemove(): void
    {
        $this->anexar($this->upload($this->pdf()));
        $chave = ChavesDoVoto::edital($this->assembleia);

        $this->remover();

        self::assertFalse($this->assembleia->temEdital());
        self::assertNull($this->assembleia->getEditalSha256());
        self::assertFalse($this->armazenamento->existe($chave));
    }

    #[TestDox('Remover sem edital recusa com mensagem de negócio')]
    public function testRemoverSemEdital(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);

        $this->remover();
    }

    #[TestDox('Outro escritório: a assembleia não é encontrada (posse antes de operar)')]
    public function testOutroEscritorio(): void
    {
        $repo = $this->createStub(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn(null);

        $this->expectException(AssembleiaNaoEncontradaException::class);

        $this->casoAnexar($repo)->executar($this->tenant, 5, $this->upload($this->pdf()));
    }

    #[TestDox('Excluir o rascunho apaga também o edital, depois da remoção')]
    public function testExcluirRascunhoApagaOEdital(): void
    {
        $this->anexar($this->upload($this->pdf()));
        $chave = ChavesDoVoto::edital($this->assembleia);
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn($this->assembleia);
        $repo->expects(self::once())->method('remover');

        (new ExcluirRascunhoDaAssembleiaUseCase($repo, self::serializacao(), new FotosDasCandidaturas($this->armazenamento, $this->transacao, $this->remocao)))->executar($this->tenant, 5);

        self::assertFalse($this->armazenamento->existe($chave));
    }

    // ---------------------------------------------------------------- helpers

    private function anexar(UploadedFile $arquivo): bool
    {
        $repo = $this->createStub(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn($this->assembleia);

        return $this->casoAnexar($repo)->executar($this->tenant, 5, $arquivo);
    }

    private function casoAnexar(AssembleiaRepository $repo): AnexarEditalDaAssembleiaUseCase
    {
        $contador = $this->createStub(ContadorDePaginasDePdf::class);
        $contador->method('contar')->willReturnCallback(fn (): ?int => $this->paginas);

        return new AnexarEditalDaAssembleiaUseCase($repo, self::serializacao(), $this->armazenamento, $this->transacao, $this->remocao, $contador);
    }

    private function remover(): void
    {
        $repo = $this->createStub(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn($this->assembleia);

        (new RemoverEditalDaAssembleiaUseCase($repo, self::serializacao(), $this->remocao))->executar($this->tenant, 5);
    }

    private function encerrarUmaPauta(): void
    {
        $pauta = new PautaDaAssembleia($this->assembleia, 1, 'Pauta');
        (new \ReflectionProperty(PautaDaAssembleia::class, 'estado'))->setValue($pauta, \App\Voto\Enum\EstadoDaPauta::Encerrada);
    }

    private function upload(string $conteudo, string $nome = 'edital.pdf'): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function pdf(string $marca = 'edital'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            . "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% {$marca}\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private static function serializacao(): SerializacaoDaConfiguracao
    {
        return new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
    }

    private function emDeTeste(): EntityManagerInterface
    {
        $nivel = 0;
        $conexao = $this->createStub(Connection::class);
        $conexao->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$nivel): int {
            return $nivel;
        });
        $conexao->method('beginTransaction')->willReturnCallback(static function () use (&$nivel): void {
            ++$nivel;
        });
        $conexao->method('rollBack')->willReturnCallback(static function () use (&$nivel): void {
            --$nivel;
        });
        $conexao->method('commit')->willReturnCallback(function () use (&$nivel): void {
            --$nivel;
            $this->ordem[] = 'commit';
        });
        $conexao->method('fetchOne')->willReturn('11117396');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexao);

        return $em;
    }
}
