<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\UploadImagemEditorInput;
use App\Pasta\UseCase\UploadImagemEditorUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Imagem embutida no editor de peças. Sem linha no banco: a chave é o tenant da sessão + o nome
 * cunhado, na categoria com isolamento físico (E2.4A).
 */
#[CoversClass(UploadImagemEditorUseCase::class)]
final class UploadImagemEditorUseCaseTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private ArmazenamentoEmMemoria $armazenamento;
    private UploadImagemEditorUseCase $useCase;
    private string $diretorio;

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->useCase       = new UploadImagemEditorUseCase($this->armazenamento);
        $this->diretorio     = sys_get_temp_dir() . '/e2-imagem-editor-' . bin2hex(random_bytes(6));
        mkdir($this->diretorio, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diretorio . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($this->diretorio);
    }

    #[TestDox('JPEG válido é gravado na categoria da imagem do editor, com o escopo do tenant da sessão')]
    public function testJpegValidoEhGravadoComOEscopoDoTenant(): void
    {
        $output = $this->useCase->executar(new UploadImagemEditorInput($this->upload(self::JPEG, 'foto.jpg'), $this->tenant(7)));

        $gravada = $this->armazenamento->ultimaGravada();
        self::assertSame($gravada->nome, $output->nomeArquivo);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $output->nomeArquivo);
        self::assertSame(CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, $gravada->categoria);
        self::assertSame(7, $gravada->escopo->tenantIdOuNull());
        self::assertSame(self::JPEG, $this->armazenamento->ler($gravada));
    }

    #[TestDox('PNG válido é gravado com extensão png')]
    public function testPngValidoEhGravado(): void
    {
        $output = $this->useCase->executar(new UploadImagemEditorInput($this->upload($this->png(), 'print.png'), $this->tenant(7)));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $output->nomeArquivo);
    }

    #[TestDox('MIME não permitido é recusado sem gravar')]
    public function testMimeInvalidoNaoGrava(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Apenas imagens JPEG e PNG são permitidas no editor.');

        try {
            $this->useCase->executar(new UploadImagemEditorInput(
                $this->upload("%PDF-1.4\n%%EOF\n", 'peca.pdf'),
                $this->tenant(7),
            ));
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    #[TestDox('Imagem maior que 3 MB é recusada sem gravar')]
    public function testArquivoMuitoGrandeNaoGrava(): void
    {
        $upload = $this->upload(self::JPEG, 'grande.jpg');
        $alvo   = fopen($upload->getPathname(), 'r+b');
        self::assertNotFalse($alvo);
        ftruncate($alvo, 3 * 1024 * 1024 + 1); // esparso: tamanho sem ocupar disco
        fclose($alvo);
        clearstatcache();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A imagem não pode ter mais de 3 MB.');

        try {
            $this->useCase->executar(new UploadImagemEditorInput($upload, $this->tenant(7)));
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    /**
     * O MIME nulo não sai de arquivo real (o detector sempre responde algo), por isso este caso
     * continua com dublê do `UploadedFile`. A recusa acontece antes da ponte de upload, então o
     * dublê nunca chega a ser movido.
     */
    #[TestDox('MIME nulo é tratado como inválido')]
    public function testMimeNuloEhInvalido(): void
    {
        $arquivo = $this->createMock(UploadedFile::class);
        $arquivo->method('getMimeType')->willReturn(null);
        $arquivo->method('getSize')->willReturn(1024);
        $arquivo->expects(self::never())->method('move');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar(new UploadImagemEditorInput($arquivo, $this->tenant(7)));
    }

    #[TestDox('Falha do storage propaga — a rota não devolve URL de imagem que não existe')]
    public function testFalhaDoStoragePropaga(): void
    {
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $this->expectException(FalhaDeArmazenamento::class);

        $this->useCase->executar(new UploadImagemEditorInput($this->upload(self::JPEG, 'foto.jpg'), $this->tenant(7)));
    }

    private function upload(string $conteudo, string $nome): UploadedFile
    {
        $caminho = $this->diretorio . '/' . bin2hex(random_bytes(4));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function png(): string
    {
        $imagem = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($imagem);

        return (string) ob_get_clean();
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
