<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\DTO\AtualizarFotoInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\AtualizarFotoPerfilUseCase;
use App\Profile\Armazenamento\ChavesDePerfil;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A foto nova é gravada pelo armazenamento por chave (E2.4A), sobre um upload REAL em diretório
 * temporário próprio. A anterior sai depois do COMMIT, pela chave montada a partir do NOME guardado
 * (E2.5) — pelo perfil, ela já seria a chave da foto nova.
 */
#[CoversClass(AtualizarFotoPerfilUseCase::class)]
final class AtualizarFotoPerfilUseCaseTest extends TestCase
{
    private const TRES_MB = 3 * 1024 * 1024;

    private UserProfileRepository&MockObject $repository;
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private AtualizarFotoPerfilUseCase $sut;
    private UserProfile $perfil;
    private string $dirTemp;

    protected function setUp(): void
    {
        $this->dirTemp = sys_get_temp_dir() . '/foto-perfil-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);

        $this->repository = $this->createMock(UserProfileRepository::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger = new LoggerEmMemoria();
        $this->sut = new AtualizarFotoPerfilUseCase(
            $this->repository,
            $this->armazenamento,
            new RemocaoAposTransacao($this->armazenamento, $this->logger),
        );

        $this->perfil = new UserProfile($this->createStub(User::class));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($this->dirTemp);
    }

    #[TestDox('Salvar JPEG grava a foto, atualiza fotoUrl com o nome cunhado e persiste via repository')]
    public function testSalvarJpegAtualizaPerfilEPersiste(): void
    {
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        $chave = $this->armazenamento->ultimaGravada();
        self::assertCount(1, $this->armazenamento->gravadas);
        self::assertSame($chave->nome, $this->perfil->getFotoUrl());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', (string) $this->perfil->getFotoUrl());
        self::assertSame($this->jpeg(), $this->armazenamento->ler($chave));
    }

    #[TestDox('A foto é gravada com escopo GLOBAL e categoria FOTO_PERFIL (D1)')]
    public function testFotoTemEscopoGlobal(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        $chave = $this->armazenamento->ultimaGravada();
        self::assertTrue($chave->escopo->ehGlobal());
        self::assertNull($chave->escopo->tenantIdOuNull());
        self::assertSame(CategoriaDeArquivo::FOTO_PERFIL, $chave->categoria);
    }

    #[TestDox('Salvar PNG atualiza fotoUrl com extensão png')]
    public function testSalvarPngAtualizaFotoUrl(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->png()));

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->perfil->getFotoUrl());
        self::assertStringEndsWith('.png', (string) $this->perfil->getFotoUrl());
    }

    #[TestDox('Salvar WebP atualiza fotoUrl com extensão webp')]
    public function testSalvarWebpAtualizaFotoUrl(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->webp()));

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->perfil->getFotoUrl());
        self::assertStringEndsWith('.webp', (string) $this->perfil->getFotoUrl());
    }

    #[TestDox('Ordem: grava a nova, salva o perfil e SÓ DEPOIS exclui a antiga')]
    public function testNovaFotoSalvaAntesDeExcluirAntiga(): void
    {
        $this->comFotoAnterior('foto_antiga.jpg');

        $ordemChamadas = [];
        $this->repository->method('salvar')->willReturnCallback(function () use (&$ordemChamadas): void {
            // Quando o perfil é salvo, a foto nova já tem de estar gravada — senão o registro
            // apontaria para um arquivo inexistente.
            $ordemChamadas[] = sprintf('salvar-perfil (fotos gravadas: %d)', \count($this->armazenamento->gravadas));
        });
        $this->armazenamento->aoExcluir = function () use (&$ordemChamadas): void {
            $ordemChamadas[] = 'excluir-antiga';
        };

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        self::assertSame(['salvar-perfil (fotos gravadas: 1)', 'excluir-antiga'], $ordemChamadas);
    }

    #[TestDox('A anterior sai e a NOVA fica — a chave da anterior não sai do perfil já trocado')]
    public function testFotoAnteriorExcluidaAposSalvarNova(): void
    {
        $antiga = $this->comFotoAnterior('foto_antiga.jpg');
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        $nova = $this->armazenamento->ultimaGravada();
        self::assertSame($nova->nome, $this->perfil->getFotoUrl());
        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertTrue($this->armazenamento->existe($nova), 'a foto recém-gravada não pode ser a apagada');
        self::assertEquals([$antiga], $this->armazenamento->excluidas);
    }

    #[TestDox('Sem foto anterior, nada é excluído')]
    public function testSemFotoAnteriorNaoExclui(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('Falha do storage propaga, o perfil não é salvo e a foto anterior fica intacta')]
    public function testFalhaDoArmazenamentoNaoTocaOPerfil(): void
    {
        $antiga = $this->comFotoAnterior('foto_antiga.jpg');
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco indisponível');
        $this->repository->expects($this->never())->method('salvar');

        try {
            $this->sut->executar($this->perfil, $this->input($this->jpeg()));
            self::fail('A falha do storage tinha de propagar.');
        } catch (FalhaDeArmazenamento) {
        }

        self::assertSame('foto_antiga.jpg', $this->perfil->getFotoUrl());
        self::assertTrue($this->armazenamento->existe($antiga), 'a foto anterior não pode ser excluída sem a nova');
    }

    #[TestDox('Banco recusa o perfil: a exceção sobe e a foto anterior FICA (rollback sem perda física)')]
    public function testBancoQueRecusaNaoApagaAAnterior(): void
    {
        $antiga = $this->comFotoAnterior('foto_antiga.jpg');
        $recusa = new \RuntimeException('flush recusado');
        $this->repository->method('salvar')->willThrowException($recusa);

        $capturada = null;
        try {
            $this->sut->executar($this->perfil, $this->input($this->jpeg()));
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame($recusa, $capturada);
        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('Disco falha ao apagar a anterior depois do COMMIT: a troca vale, e o órfão é registrado')]
    public function testDiscoQueFalhaDepoisDoCommitNaoDerrubaATroca(): void
    {
        $antiga = $this->comFotoAnterior('foto_antiga.jpg');
        $this->repository->method('salvar');
        $this->armazenamento->falhaAoExcluir = static fn (): \Throwable => new FalhaDeArmazenamento('disco ilegível');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->perfil->getFotoUrl());
        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertSame($antiga->comoTexto(), $this->logger->doNivel('error')[0]['contexto']['chave']);
    }

    #[TestDox('Nome anterior que a chave recusa vira registro — não 422 com a foto nova já salva')]
    public function testNomeAnteriorRecusadoViraRegistro(): void
    {
        $this->perfil->setFotoUrl('legado/com-barra.jpg');
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->perfil->getFotoUrl());
        self::assertCount(1, $this->logger->doNivel('error'));
    }

    #[TestDox('Mime GIF lança InvalidArgumentException sem gravar')]
    public function testMimeGifLancaExcecao(): void
    {
        $this->repository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar('/não permitido/i', $this->input($this->gif()));
    }

    #[TestDox('Mime PDF lança InvalidArgumentException sem gravar')]
    public function testMimePdfLancaExcecao(): void
    {
        $this->repository->expects($this->never())->method('salvar');

        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $this->assertRecusaSemGravar('/não permitido/i', $this->input($pdf));
    }

    #[TestDox('Arquivo acima de 3 MB lança InvalidArgumentException sem gravar')]
    public function testArquivoAcimaDe3MBLancaExcecao(): void
    {
        $this->repository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar('/3 MB/i', $this->input($this->jpegComTamanho(self::TRES_MB + 1)));
    }

    #[TestDox('Arquivo exatamente no limite de 3 MB é aceito')]
    public function testArquivoNaLimiteEhAceito(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpegComTamanho(self::TRES_MB)));

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->perfil->getFotoUrl());
    }

    private function assertRecusaSemGravar(string $mensagem, AtualizarFotoInput $input): void
    {
        try {
            $this->sut->executar($this->perfil, $input);
        } catch (\InvalidArgumentException $e) {
            self::assertMatchesRegularExpression($mensagem, $e->getMessage());
            self::assertSame([], $this->armazenamento->gravadas, 'A recusa tem de acontecer antes de qualquer gravação.');
            self::assertNull($this->perfil->getFotoUrl());

            return;
        }

        self::fail('Esperava InvalidArgumentException.');
    }

    private function comFotoAnterior(string $nome): \App\Shared\Armazenamento\ChaveDeArquivo
    {
        $this->perfil->setFotoUrl($nome);
        $chave = ChavesDePerfil::fotoPorNome($nome);
        $this->armazenamento->semear($chave, 'antiga');

        return $chave;
    }

    /** Upload real em modo de teste (pula `is_uploaded_file`), sobre um arquivo com conteúdo real. */
    private function input(string $conteudo): AtualizarFotoInput
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new AtualizarFotoInput(new UploadedFile($caminho, 'foto', null, null, true));
    }

    private function jpeg(): string
    {
        // JPEG mínimo válido: SOI + APP0/JFIF + EOI — reconhecido pelo finfo como image/jpeg.
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
    }

    private function jpegComTamanho(int $bytes): string
    {
        return str_pad($this->jpeg(), $bytes, "\0");
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));

        return (string) ob_get_clean();
    }

    private function webp(): string
    {
        // RIFF/WEBP com um chunk VP8L mínimo (1x1): o GD do container não escreve WebP.
        $vp8l  = "\x2f\x00\x00\x00\x00\x07\x10\x11\x11\x88\x88\xfe\x07\x00";
        $chunk = 'VP8L' . pack('V', \strlen($vp8l)) . $vp8l;

        return 'RIFF' . pack('V', 4 + \strlen($chunk)) . 'WEBP' . $chunk;
    }

    private function gif(): string
    {
        return "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,"
            . "\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
    }
}
