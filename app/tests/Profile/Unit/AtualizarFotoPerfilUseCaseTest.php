<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\DTO\AtualizarFotoInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\AtualizarFotoPerfilUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(AtualizarFotoPerfilUseCase::class)]
final class AtualizarFotoPerfilUseCaseTest extends TestCase
{
    private UserProfileRepository&MockObject $repository;
    private ArquivoStorageStub $storage;
    private AtualizarFotoPerfilUseCase $sut;
    private UserProfile $perfil;

    private const DIR = '/tmp/fotos_perfil_test';

    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserProfileRepository::class);
        $this->storage = new ArquivoStorageStub();
        $this->sut = new AtualizarFotoPerfilUseCase($this->repository, $this->storage, self::DIR);

        $this->perfil = new UserProfile($this->createStub(User::class));
    }

    private function criarInput(string $mime, int $tamanho = 100_000): AtualizarFotoInput
    {
        $arquivo = $this->createMock(UploadedFile::class);
        $arquivo->method('getMimeType')->willReturn($mime);
        $arquivo->method('getSize')->willReturn($tamanho);

        return new AtualizarFotoInput($arquivo);
    }

    #[TestDox('Salvar JPEG atualiza fotoUrl e persiste via repository')]
    public function testSalvarJpegAtualizaPerfilEPersiste(): void
    {
        $this->storage->nomeRetorno = 'novo_arquivo.jpg';
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, $this->criarInput('image/jpeg'));

        self::assertSame('novo_arquivo.jpg', $this->perfil->getFotoUrl());
        self::assertSame(self::DIR, $this->storage->diretorioUsado);
    }

    #[TestDox('Salvar PNG atualiza fotoUrl corretamente')]
    public function testSalvarPngAtualizaFotoUrl(): void
    {
        $this->storage->nomeRetorno = 'foto.png';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->criarInput('image/png'));

        self::assertSame('foto.png', $this->perfil->getFotoUrl());
    }

    #[TestDox('Salvar WebP atualiza fotoUrl corretamente')]
    public function testSalvarWebpAtualizaFotoUrl(): void
    {
        $this->storage->nomeRetorno = 'foto.webp';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->criarInput('image/webp'));

        self::assertSame('foto.webp', $this->perfil->getFotoUrl());
    }

    #[TestDox('Nova foto é salva ANTES de deletar a antiga (ordem correta)')]
    public function testNovaFotoSalvaAntesDeExcluirAntiga(): void
    {
        $this->perfil->setFotoUrl('foto_antiga.jpg');
        $this->storage->nomeRetorno = 'foto_nova.jpg';
        $this->repository->method('salvar');

        $ordemChamadas = [];
        $this->storage->onSalvar = function () use (&$ordemChamadas): void {
            $ordemChamadas[] = 'salvar';
        };
        $this->storage->onExcluir = function () use (&$ordemChamadas): void {
            $ordemChamadas[] = 'excluir';
        };

        $this->sut->executar($this->perfil, $this->criarInput('image/jpeg'));

        self::assertSame(['salvar', 'excluir'], $ordemChamadas);
    }

    #[TestDox('Foto anterior é excluída após salvar a nova')]
    public function testFotoAnteriorExcluidaAposSalvarNova(): void
    {
        $this->perfil->setFotoUrl('foto_antiga.jpg');
        $this->storage->nomeRetorno = 'foto_nova.jpg';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->criarInput('image/jpeg'));

        self::assertSame('foto_nova.jpg', $this->perfil->getFotoUrl());
        self::assertSame(self::DIR . '/foto_antiga.jpg', $this->storage->caminhoExcluido);
    }

    #[TestDox('Sem foto anterior, excluir não é chamado')]
    public function testSemFotoAnteriorNaoExclui(): void
    {
        $this->storage->nomeRetorno = 'primeira_foto.jpg';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->criarInput('image/jpeg'));

        self::assertNull($this->storage->caminhoExcluido);
    }

    #[TestDox('Mime GIF lança InvalidArgumentException')]
    public function testMimeGifLancaExcecao(): void
    {
        $this->repository->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/não permitido/i');

        $this->sut->executar($this->perfil, $this->criarInput('image/gif'));
    }

    #[TestDox('Mime PDF lança InvalidArgumentException')]
    public function testMimePdfLancaExcecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sut->executar($this->perfil, $this->criarInput('application/pdf'));
    }

    #[TestDox('Arquivo acima de 3 MB lança InvalidArgumentException')]
    public function testArquivoAcimaDe3MBLancaExcecao(): void
    {
        $this->repository->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/3 MB/i');

        $this->sut->executar($this->perfil, $this->criarInput('image/jpeg', 3 * 1024 * 1024 + 1));
    }

    #[TestDox('Arquivo exatamente no limite de 3 MB é aceito')]
    public function testArquivoNaLimiteEhAceito(): void
    {
        $this->storage->nomeRetorno = 'no_limite.jpg';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $this->criarInput('image/jpeg', 3 * 1024 * 1024));

        self::assertSame('no_limite.jpg', $this->perfil->getFotoUrl());
    }
}

final class ArquivoStorageStub implements ArquivoStorageInterface
{
    public string $nomeRetorno = 'arquivo_gerado.jpg';
    public ?string $caminhoExcluido = null;
    public ?string $diretorioUsado = null;
    /** @var callable|null */
    public $onSalvar = null;
    /** @var callable|null */
    public $onExcluir = null;

    public function salvar(UploadedFile $arquivo, string $diretorio): string
    {
        $this->diretorioUsado = $diretorio;
        if ($this->onSalvar !== null) {
            ($this->onSalvar)();
        }

        return $this->nomeRetorno;
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

    public function caminho(string $diretorio, string $nomeArquivo): string
    {
        return $diretorio . '/' . $nomeArquivo;
    }
}
