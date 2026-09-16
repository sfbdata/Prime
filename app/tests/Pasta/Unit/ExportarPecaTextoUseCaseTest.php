<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\DTO\ExportarPecaTextoOutput;
use App\Pasta\Service\ReferenciasDePecaHtml;
use App\Pasta\UseCase\ExportarPecaTextoUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PhpOffice\PhpWord\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Export de peça. Desde a E2.4B o HTML é lido por chave (D13: ausente → `ArquivoNaoEncontrado`,
 * pane → `FalhaDeArmazenamento`); as imagens embutidas ainda saem do disco pelo `projectDir`
 * (E2.6).
 */
#[CoversClass(ExportarPecaTextoUseCase::class)]
final class ExportarPecaTextoUseCaseTest extends TestCase
{
    private ArmazenamentoEmMemoria $armazenamento;
    private ExportarPecaTextoUseCase $useCase;
    private PastaDocumento $doc;

    private const HTML_SIMPLES = '<p>Petição válida com ação e acentuação: ç, ã, é.</p>';

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();

        $this->useCase = $this->useCaseCom($this->armazenamento);

        $this->doc = (new PastaDocumento())
            ->setTenant($this->tenant(7))
            ->setTitulo('Petição Inicial')
            ->setCaminhoArquivo('9c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f.html')
            ->setCategoria('PECA')
            ->setMimeType('text/html')
            ->setNomeOriginal('Petição Inicial.html')
            ->setTamanhoBytes(strlen(self::HTML_SIMPLES));

        $this->gravarPeca(self::HTML_SIMPLES);
    }

    #[TestDox('D13: peça sem arquivo → ArquivoNaoEncontrado (o controller responde 404), sem export vazio')]
    public function testPecaAusenteLancaArquivoNaoEncontrado(): void
    {
        $this->armazenamento->excluir(ChavesDePasta::documento($this->doc));

        $this->expectException(ArquivoNaoEncontrado::class);

        $this->useCase->executar($this->doc, 'txt');
    }

    /**
     * R1: com o escritório errado a chave não acha a peça — o dublê guarda o escopo. O disco
     * plano de hoje devolveria o arquivo mesmo assim.
     */
    #[TestDox('R1: a leitura usa o escritório do documento')]
    public function testLeituraUsaOEscritorioDoDocumento(): void
    {
        $this->doc->setTenant($this->tenant(8));

        $this->expectException(ArquivoNaoEncontrado::class);

        $this->useCase->executar($this->doc, 'txt');
    }

    /** D13: pane não é ausência — `FalhaDeArmazenamento` passa intacta, sem virar `ArquivoNaoEncontrado`. */
    #[TestDox('D13: falha do storage ao ler propaga como FalhaDeArmazenamento')]
    public function testFalhaDoStorageAoLerPropaga(): void
    {
        $useCase = $this->useCaseCom(new class implements ArmazenamentoDeArquivos {
            public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
            {
                throw new \LogicException('não usado');
            }

            public function abrir(ChaveDeArquivo $chave): mixed
            {
                throw new FalhaDeArmazenamento('backend fora do ar');
            }

            public function ler(ChaveDeArquivo $chave): string
            {
                throw new FalhaDeArmazenamento('backend fora do ar');
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                throw new FalhaDeArmazenamento('backend fora do ar');
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                throw new \LogicException('não usado');
            }

            public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
            {
                throw new FalhaDeArmazenamento('backend fora do ar');
            }
        });

        try {
            $useCase->executar($this->doc, 'pdf');
            self::fail('a pane devia propagar');
        } catch (FalhaDeArmazenamento $e) {
            self::assertNotInstanceOf(ArquivoNaoEncontrado::class, $e);
            self::assertSame('backend fora do ar', $e->getMessage());
        }
    }

    #[TestDox('Exportar DOCX retorna mime correto, conteúdo não vazio e arquivo íntegro')]
    public function testExportarDocxRetornaMimeCorretoEConteudoValido(): void
    {
        $output = $this->useCase->executar($this->doc, 'docx');

        self::assertInstanceOf(ExportarPecaTextoOutput::class, $output);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $output->mimeType,
        );
        self::assertStringEndsWith('.docx', $output->nomeArquivo);
        self::assertNotEmpty($output->conteudo);

        // Verificar integridade: DOCX é um ZIP — tentar abrir valida a estrutura
        $tmpDocx = tempnam(sys_get_temp_dir(), 'peca_chk_') . '.docx';
        file_put_contents($tmpDocx, $output->conteudo);
        try {
            $phpWord = IOFactory::load($tmpDocx, 'Word2007');
            self::assertNotNull($phpWord);
        } finally {
            @unlink($tmpDocx);
        }
    }

    #[TestDox('Exportar ODT retorna mime correto e conteúdo com estrutura ZIP válida')]
    public function testExportarOdtRetornaMimeCorretoEConteudoValido(): void
    {
        $output = $this->useCase->executar($this->doc, 'odt');

        self::assertSame('application/vnd.oasis.opendocument.text', $output->mimeType);
        self::assertStringEndsWith('.odt', $output->nomeArquivo);
        self::assertNotEmpty($output->conteudo);

        // ODT é um ZIP — verificar que a estrutura básica é válida
        $tmpOdt = tempnam(sys_get_temp_dir(), 'peca_chk_') . '.odt';
        file_put_contents($tmpOdt, $output->conteudo);
        try {
            $zip = new \ZipArchive();
            $resultado = $zip->open($tmpOdt);
            self::assertTrue($resultado, 'ODT deve ser um arquivo ZIP válido');
            $zip->close();
        } finally {
            @unlink($tmpOdt);
        }
    }

    #[TestDox('Exportar PDF retorna mime correto e conteúdo com assinatura PDF válida')]
    public function testExportarPdfRetornaMimeCorretoEConteudoValido(): void
    {
        $output = $this->useCase->executar($this->doc, 'pdf');

        self::assertSame('application/pdf', $output->mimeType);
        self::assertStringEndsWith('.pdf', $output->nomeArquivo);
        self::assertStringStartsWith('%PDF-', $output->conteudo);
        self::assertGreaterThan(100, strlen($output->conteudo));
    }

    #[TestDox('Exportar TXT retorna texto sem tags HTML e com acentuação UTF-8 correta')]
    public function testExportarTxtRetornaTextoSemTagsComAcentuacao(): void
    {
        $output = $this->useCase->executar($this->doc, 'txt');

        self::assertSame('text/plain', $output->mimeType);
        self::assertStringEndsWith('.txt', $output->nomeArquivo);
        self::assertStringNotContainsString('<p>', $output->conteudo);
        self::assertStringNotContainsString('</p>', $output->conteudo);
        self::assertStringContainsString('ç', $output->conteudo);
        self::assertStringContainsString('ã', $output->conteudo);
        self::assertStringContainsString('é', $output->conteudo);
    }

    #[TestDox('Formato inválido lança InvalidArgumentException')]
    public function testFormatoInvalidoLancaInvalidArgumentException(): void
    {
        // A mensagem prova que é o formato: a chave recusada também estende InvalidArgumentException.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Formato não suportado: xml');

        $this->useCase->executar($this->doc, 'xml');
    }

    #[TestDox('Exportar DOCX com tabela HTML5 (col sem self-closing) não lança exceção')]
    public function testExportarDocxComTabelaHtml5NaoLancaExcecao(): void
    {
        $html = '<table border="1"><colgroup><col style="width: 50%;"><col style="width: 50%;"></colgroup>'
            . '<tbody><tr><td>Coluna A</td><td>Coluna B</td></tr></tbody></table>';
        $this->gravarPeca($html);

        $output = $this->useCase->executar($this->doc, 'docx');

        self::assertNotEmpty($output->conteudo);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $output->mimeType,
        );
    }

    #[TestDox('Exportar ODT com tabela HTML5 (col sem self-closing) não lança exceção')]
    public function testExportarOdtComTabelaHtml5NaoLancaExcecao(): void
    {
        $html = '<table border="1"><colgroup><col style="width: 50%;"><col style="width: 50%;"></colgroup>'
            . '<tbody><tr><td>Coluna A</td><td>Coluna B</td></tr></tbody></table>';
        $this->gravarPeca($html);

        $output = $this->useCase->executar($this->doc, 'odt');

        self::assertNotEmpty($output->conteudo);
        self::assertSame('application/vnd.oasis.opendocument.text', $output->mimeType);
    }

    #[TestDox('Nome do arquivo contém a extensão correta para cada formato')]
    public function testNomeDaArquivoContemExtensaoCorreta(): void
    {
        self::assertStringEndsWith('.docx', $this->useCase->executar($this->doc, 'docx')->nomeArquivo);
        self::assertStringEndsWith('.pdf', $this->useCase->executar($this->doc, 'pdf')->nomeArquivo);
        self::assertStringEndsWith('.txt', $this->useCase->executar($this->doc, 'txt')->nomeArquivo);
    }

    #[TestDox('M5: reescrita de imagem aponta /uploads/pastas/ para a subpasta do tenant do doc')]
    public function testReescritaApontaParaSubpastaDoTenant(): void
    {
        $html = '<p>Peça</p><img src="/uploads/pastas/foto.png" alt="">';

        $resultado = $this->invocarReescrita($html, 77);

        self::assertSame(
            '<p>Peça</p><img src="' . sys_get_temp_dir() . '/public/uploads/pastas/77/foto.png" alt="">',
            $resultado,
        );
    }

    #[TestDox('M5: reescrita normaliza caminho RELATIVO (../../uploads/pastas/) do TinyMCE para a subpasta')]
    public function testReescritaNormalizaCaminhoRelativo(): void
    {
        // O TinyMCE grava URL relativa por default (convert_urls). O str_replace antigo deixava o
        // `../..` para trás e quebrava a imagem no export; o regex consome o prefixo inteiro.
        $html = '<p>X</p><img src="../../uploads/pastas/foto.png" alt="">';

        $resultado = $this->invocarReescrita($html, 77);

        self::assertSame(
            '<p>X</p><img src="' . sys_get_temp_dir() . '/public/uploads/pastas/77/foto.png" alt="">',
            $resultado,
        );
    }

    #[TestDox('M5: reescrita troca TODAS as imagens do HTML (absoluta, relativa e ./) na mesma peça')]
    public function testReescritaTrocaMultiplasImagens(): void
    {
        $base = sys_get_temp_dir() . '/public/uploads/pastas/77/';
        $html = '<img src="/uploads/pastas/a.png">texto'
            . '<img src="../../uploads/pastas/b.jpg">mais'
            . '<img src="./uploads/pastas/c.png">';

        $resultado = $this->invocarReescrita($html, 77);

        self::assertSame(
            '<img src="' . $base . 'a.png">texto'
            . '<img src="' . $base . 'b.jpg">mais'
            . '<img src="' . $base . 'c.png">',
            $resultado,
        );
    }

    #[TestDox('Reescrita sem tenant (degenerado) cai no caminho sem subpasta')]
    public function testReescritaSemTenantUsaCaminhoSemSubpasta(): void
    {
        $html = '<img src="/uploads/pastas/foto.png">';

        $resultado = $this->invocarReescrita($html, null);

        self::assertSame(
            '<img src="' . sys_get_temp_dir() . '/public/uploads/pastas/foto.png">',
            $resultado,
        );
    }

    #[TestDox('M5/PDF: a imagem do disco é EMBUTIDA no PDF (o chroot do Dompdf libera a leitura)')]
    public function testPdfEmbuteImagemDoDisco(): void
    {
        // doc no tenant 99 → a reescrita aponta p/ {projectDir}/public/uploads/pastas/99/
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 99);
        $this->doc->setTenant($tenant);

        $dir = sys_get_temp_dir() . '/public/uploads/pastas/99';
        @mkdir($dir, 0775, true);
        $img = $dir . '/foto.png';
        $gd  = imagecreatetruecolor(80, 80);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 12, 120, 200));
        imagepng($gd, $img);
        imagedestroy($gd);

        $html = '<p>Peça com imagem</p><img src="/uploads/pastas/foto.png">';

        $this->gravarPeca($html);
        $comImagem = $this->useCase->executar($this->doc, 'pdf')->conteudo;

        // baseline: mesma peça, arquivo de imagem AUSENTE → Dompdf não embute → PDF menor
        @unlink($img);
        $this->gravarPeca($html);
        $semImagem = $this->useCase->executar($this->doc, 'pdf')->conteudo;

        @rmdir($dir);

        self::assertStringStartsWith('%PDF-', $comImagem);
        self::assertGreaterThan(
            strlen($semImagem),
            strlen($comImagem),
            'com o arquivo presente o PDF deve ser maior (imagem embutida) que sem — prova o chroot',
        );
    }

    private function useCaseCom(ArmazenamentoDeArquivos $armazenamento): ExportarPecaTextoUseCase
    {
        return new ExportarPecaTextoUseCase($armazenamento, new ReferenciasDePecaHtml(), sys_get_temp_dir());
    }

    /** Grava o HTML na chave do documento como ele está AGORA (escritório incluído). */
    private function gravarPeca(string $html): void
    {
        $this->armazenamento->gravar(ChavesDePasta::documento($this->doc), FonteDeConteudo::deTexto($html));
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function invocarReescrita(string $html, ?int $tenantId): string
    {
        $metodo = new \ReflectionMethod($this->useCase, 'reescreverImagensParaDisco');

        return (string) $metodo->invoke($this->useCase, $html, $tenantId);
    }
}
