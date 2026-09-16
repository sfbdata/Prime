<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\DTO\AtualizarFotoInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\AtualizarFotoPerfilUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Service\ArquivoStorageInterface;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A foto nova é gravada pelo armazenamento por chave (E2.4A), sobre um upload REAL em diretório
 * temporário próprio. O storage antigo só continua aqui para excluir a foto anterior (E2.5).
 */
#[CoversClass(AtualizarFotoPerfilUseCase::class)]
final class AtualizarFotoPerfilUseCaseTest extends TestCase
{
    private const DIR = '/tmp/fotos_perfil_test';
    private const TRES_MB = 3 * 1024 * 1024;

    private UserProfileRepository&MockObject $repository;
    private ArquivoStorageStub $storage;
    private ArmazenamentoEmMemoria $armazenamento;
    private AtualizarFotoPerfilUseCase $sut;
    private UserProfile $perfil;
    private string $dirTemp;

    protected function setUp(): void
    {
        $this->dirTemp = sys_get_temp_dir() . '/foto-perfil-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);

        $this->repository = $this->createMock(UserProfileRepository::class);
        $this->storage = new ArquivoStorageStub();
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->sut = new AtualizarFotoPerfilUseCase($this->repository, $this->storage, $this->armazenamento, self::DIR);

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
        $this->perfil->setFotoUrl('foto_antiga.jpg');

        $ordemChamadas = [];
        $this->repository->method('salvar')->willReturnCallback(function () use (&$ordemChamadas): void {
            // Quando o perfil é salvo, a foto nova já tem de estar gravada — senão o registro
            // apontaria para um arquivo inexistente.
            $ordemChamadas[] = sprintf('salvar-perfil (fotos gravadas: %d)', \count($this->armazenamento->gravadas));
        });
        $this->storage->onExcluir = function () use (&$ordemChamadas): void {
            $ordemChamadas[] = 'excluir-antiga';
        };

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        self::assertSame(['salvar-perfil (fotos gravadas: 1)', 'excluir-antiga'], $ordemChamadas);
    }

    #[TestDox('Foto anterior é excluída após salvar a nova')]
    public function testFotoAnteriorExcluidaAposSalvarNova(): void
    {
        $this->perfil->setFotoUrl('foto_antiga.jpg');
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->perfil->getFotoUrl());
        self::assertSame(self::DIR . '/foto_antiga.jpg', $this->storage->caminhoExcluido);
    }

    #[TestDox('Sem foto anterior, excluir não é chamado')]
    public function testSemFotoAnteriorNaoExclui(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->input($this->jpeg()));

        self::assertNull($this->storage->caminhoExcluido);
    }

    #[TestDox('Falha do storage propaga, o perfil não é salvo e a foto anterior fica intacta')]
    public function testFalhaDoArmazenamentoNaoTocaOPerfil(): void
    {
        $this->perfil->setFotoUrl('foto_antiga.jpg');
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco indisponível');
        $this->repository->expects($this->never())->method('salvar');

        try {
            $this->sut->executar($this->perfil, $this->input($this->jpeg()));
            self::fail('A falha do storage tinha de propagar.');
        } catch (FalhaDeArmazenamento) {
        }

        self::assertSame('foto_antiga.jpg', $this->perfil->getFotoUrl());
        self::assertNull($this->storage->caminhoExcluido, 'a foto anterior não pode ser excluída sem a nova');
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

/**
 * Dublê do storage antigo: só a exclusão da foto anterior ainda passa por ele. Gravar por aqui é
 * regressão — `salvar()` falha alto.
 */
final class ArquivoStorageStub implements ArquivoStorageInterface
{
    public ?string $caminhoExcluido = null;
    /** @var callable|null */
    public $onExcluir = null;

    public function salvar(UploadedFile $arquivo, string $diretorio): string
    {
        throw new \LogicException('salvar() saiu de uso na E2.4A: a foto é gravada pelo ArmazenamentoDeArquivos.');
    }

    public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        throw new \LogicException('Não deve ser chamado nos testes de unidade.');
    }

    public function excluir(string $caminhoCompleto): void
    {
        $this->caminhoExcluido = $caminhoCompleto;
        if ($this->onExcluir !== null) {
            ($this->onExcluir)();
        }
    }

    public function existe(string $caminhoCompleto): bool
    {
        return false;
    }

    public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string
    {
        throw new \LogicException('Não deve ser chamado nos testes de unidade.');
    }

    public function moverParaArmazenamento(string $caminhoOrigem, string $diretorio, string $extensao): string
    {
        throw new \LogicException('Não deve ser chamado nos testes de unidade.');
    }

    public function caminho(string $diretorio, string $nomeArquivo): string
    {
        return $diretorio . '/' . $nomeArquivo;
    }
}
