<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\ReferenciasDePecaHtml;
use App\Pasta\UseCase\ExportarPecaTextoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspiaoDeHttp;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os vetores do export de peça (E2.6C, D32), cada um medido no código ANTES de ser fechado.
 *
 * O que foi medido contra o export da E2.6B, localmente e sem rede (o `http://` é atendido por um
 * wrapper espião, e o "outro escritório" é um diretório do próprio teste):
 *
 * | vetor                                   | DOCX (PhpWord)            | PDF (Dompdf)            |
 * |-----------------------------------------|---------------------------|-------------------------|
 * | `<img src="http://169.254.169.254/...">`| **buscou a URL e embutiu**| bloqueado (remote off)  |
 * | `<img src="file:///tmp/...">`           | **embutiu**               | bloqueado (chroot)      |
 * | `%2e%2e` para outro escritório          | **embutiu** (urldecode)   | não resolveu            |
 * | `../` para outro escritório             | **embutiu**               | **embutiu** (chroot público) |
 * | `data:image/png;base64,...`             | embutiu                   | embutiu                 |
 * | imagem ausente                          | **exceção → 500**         | imagem quebrada         |
 *
 * Estes testes afirmam o estado FECHADO. Rodados contra o código anterior, falham — foi assim que a
 * tabela acima foi levantada.
 *
 * O controle é {@see testImagemDoProprioEscritorioEntraNoDocx()}: sem ele, "nada foi embutido"
 * passaria mesmo com o export quebrado, sem embutir imagem nenhuma.
 */
#[CoversClass(ExportarPecaTextoUseCase::class)]
#[CoversClass(ReferenciasDePecaHtml::class)]
final class ExportarPecaImagemSeguraTest extends TestCase
{
    private const MEU_ESCRITORIO   = 7;
    private const OUTRO_ESCRITORIO = 9;

    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private ExportarPecaTextoUseCase $useCase;
    private PastaDocumento $doc;
    private string $dir;
    private string $segredoDeOutro;
    private string $minhaImagem;

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger        = new LoggerEmMemoria();
        $this->useCase       = new ExportarPecaTextoUseCase($this->armazenamento, new ReferenciasDePecaHtml(), $this->logger);

        $this->dir = sys_get_temp_dir() . '/export-seguro-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);

        // Ruído: incompressível, então aparecer no PDF/DOCX é medível pelo tamanho e pelos bytes.
        $this->segredoDeOutro = $this->png(0x99);
        $this->minhaImagem    = $this->png(0x11);

        // A imagem do MEU escritório, endereçável por chave. A do outro NÃO entra no armazenamento
        // do teste: ela existe só no disco, como existiria no volume compartilhado.
        $this->armazenamento->semear(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::MEU_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'minha.png'),
            $this->minhaImagem,
        );
        $this->armazenamento->semear(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::OUTRO_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'segredo.png'),
            $this->segredoDeOutro,
        );

        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, self::MEU_ESCRITORIO);

        $this->doc = new PastaDocumento();
        $this->doc->setTenant($tenant);
        $this->doc->setPasta((new Pasta())->setTenant($tenant));
        $this->doc->setTitulo('peca.html');
        $this->doc->setCaminhoArquivo('peca.html');
        $this->doc->setMimeType('text/html');
    }

    protected function tearDown(): void
    {
        EspiaoDeHttp::desinstalar();

        if (isset($this->dir) && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function png(int $semente): string
    {
        $imagem = imagecreatetruecolor(120, 120);
        mt_srand($semente);
        for ($x = 0; $x < 120; $x++) {
            for ($y = 0; $y < 120; $y++) {
                imagesetpixel($imagem, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        $caminho = $this->dir . '/' . $semente . '.png';
        imagepng($imagem, $caminho);
        imagedestroy($imagem);

        return (string) file_get_contents($caminho);
    }

    private function chaveDaPeca(): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::MEU_ESCRITORIO), CategoriaDeArquivo::PASTA_DOCUMENTO, 'peca.html');
    }

    private function exportar(string $html, string $formato): string
    {
        $this->armazenamento->semear($this->chaveDaPeca(), $html);
        $this->armazenamento->lidas = [];

        return $this->useCase->executar($this->doc, $formato)->conteudo;
    }

    /** O DOCX é um zip: o que foi embutido está em `word/media/`. */
    private function midiasDoDocx(string $docx): array
    {
        $caminho = $this->dir . '/saida-' . bin2hex(random_bytes(4)) . '.docx';
        file_put_contents($caminho, $docx);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($caminho) === true, 'o DOCX não abriu como zip');

        $midias = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = (string) $zip->getNameIndex($i);
            if (str_starts_with($nome, 'word/media/')) {
                $midias[] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $midias;
    }

    #[TestDox('controle: a imagem DO PRÓPRIO escritório é embutida no DOCX')]
    public function testImagemDoProprioEscritorioEntraNoDocx(): void
    {
        $docx = $this->exportar('<p>oi</p><img src="/uploads/pastas/7/minha.png">', 'docx');

        self::assertContains($this->minhaImagem, $this->midiasDoDocx($docx), 'o export não embute mais imagem nenhuma: os outros testes não provam nada');
    }

    #[TestDox('controle: a imagem do próprio escritório também entra pelo caminho relativo do editor')]
    public function testImagemRelativaDoEditorEntraNoDocx(): void
    {
        $docx = $this->exportar('<p>oi</p><img src="../../uploads/pastas/7/minha.png">', 'docx');

        self::assertContains($this->minhaImagem, $this->midiasDoDocx($docx));
    }

    #[TestDox('controle: TODAS as imagens da peça são materializadas, em qualquer das formas do editor')]
    public function testTodasAsImagensSaoMaterializadas(): void
    {
        $this->armazenamento->semear(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::MEU_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'outra.png'),
            $this->png(0x33),
        );

        $docx = $this->exportar(
            '<img src="/uploads/pastas/7/minha.png">texto'
            . '<img src="../../uploads/pastas/7/outra.png">mais'
            . '<img src="./uploads/pastas/minha.png">',
            'docx',
        );

        // O PhpWord reusa a mesma mídia quando o arquivo se repete; o que importa é que as três
        // tags resolveram (nenhuma foi removida) e que o conteúdo embutido é o do escritório.
        self::assertNotSame([], $this->midiasDoDocx($docx));
        self::assertSame([], $this->logger->doNivel('warning'), 'alguma imagem legítima foi recusada');
    }

    /**
     * O vetor mais grave: `Html::parseImage` faz `file_get_contents($src)` em qualquer coisa que não
     * seja arquivo local, e `allow_url_fopen` está ligado. O wrapper espião registra a tentativa sem
     * que nada saia da máquina.
     */
    #[TestDox('SSRF: o export não busca URL http:// do conteúdo da peça')]
    public function testSsrfNaoAcontece(): void
    {
        EspiaoDeHttp::instalar($this->minhaImagem);

        $docx = $this->exportar('<p>oi</p><img src="http://169.254.169.254/latest/meta-data/iam.png">', 'docx');

        self::assertSame([], EspiaoDeHttp::$pedidos, 'o export saiu buscando URL da peça (SSRF)');
        self::assertSame([], $this->midiasDoDocx($docx));
    }

    #[TestDox('SSRF: nem no PDF, nem com https')]
    public function testSsrfNaoAconteceNoPdf(): void
    {
        EspiaoDeHttp::instalar($this->minhaImagem);

        $pdf = $this->exportar('<p>oi</p><img src="http://169.254.169.254/x.png"><img src="https://exemplo.invalido/y.png">', 'pdf');

        self::assertSame([], EspiaoDeHttp::$pedidos);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /** @return iterable<string, array{string}> */
    public static function referenciasProibidas(): iterable
    {
        yield 'file:// para fora do volume' => ['file:///etc/hostname'];
        yield 'caminho absoluto de disco' => ['/etc/hostname'];
        yield 'travessia com ../ para outro escritório' => ['/uploads/pastas/../9/segredo.png'];
        yield 'travessia percent-encoded (%2e%2e)' => ['/uploads/pastas/%2e%2e/9/segredo.png'];
        yield 'subpasta de outro escritório' => ['/uploads/pastas/9/segredo.png'];
        yield 'data: com a imagem inteira embutida' => ['data:image/png;base64,{SEGREDO}'];
        yield 'outra categoria do volume' => ['/uploads/clientes/algum.png'];
        yield 'nome com .. no meio' => ['/uploads/pastas/a..b.png'];
        yield 'URL remota com cara de caminho local' => ['http://malicioso.invalido/uploads/pastas/minha.png'];
        yield 'subpasta de outro escritório com nome que eu também tenho' => ['/uploads/pastas/9/minha.png'];
    }

    #[DataProvider('referenciasProibidas')]
    #[TestDox('referência proibida ($_dataName) não entra no DOCX')]
    public function testReferenciaProibidaNaoEntraNoDocx(string $src): void
    {
        EspiaoDeHttp::instalar($this->minhaImagem);

        foreach ($this->pecasCom($src) as $descricao => $html) {
            $this->logger->registros = []; // o aviso conferido é o DESTA forma
            $docx = $this->exportar($html, 'docx');

            self::assertSame([], $this->midiasDoDocx($docx), 'o export embutiu um arquivo que não é imagem do escritório (' . $descricao . ')');
            self::assertSame([], EspiaoDeHttp::$pedidos, 'o export buscou a URL da peça (' . $descricao . ')');
            self::assertNotSame([], $this->logger->doNivel('warning'), 'a imagem recusada tem de ficar no log');
        }
    }

    /**
     * A MESMA referência em duas peças: com texto ao lado e SOZINHA.
     *
     * A segunda forma é a que a revisão da 6C pegou: removida a única tag, o corpo fica vazio, e um
     * fallback "se não sobrou nada, use o HTML original" devolvia a peça inteira — com o `src`
     * malicioso — para a biblioteca. Teste que sempre põe um `<p>` ao lado nunca chega nesse ramo.
     *
     * @return array<string, string>
     */
    private function pecasCom(string $src): array
    {
        $tag = '<img src="' . $this->comSegredo($src) . '">';

        return [
            'com texto ao lado' => '<p>oi</p>' . $tag,
            'a peça é só a imagem' => $tag,
            'duas imagens, nada mais' => $tag . $tag,
        ];
    }

    #[DataProvider('referenciasProibidas')]
    #[TestDox('referência proibida ($_dataName) não entra no PDF')]
    public function testReferenciaProibidaNaoEntraNoPdf(string $src): void
    {
        EspiaoDeHttp::instalar($this->minhaImagem);

        foreach ($this->pecasCom($src) as $descricao => $html) {
            $pdf = $this->exportar($html, 'pdf');

            self::assertStringStartsWith('%PDF-', $pdf);
            self::assertStringNotContainsString($this->segredoDeOutro, $pdf);
            self::assertSame([], EspiaoDeHttp::$pedidos, 'o export buscou a URL da peça (' . $descricao . ')');
            // O PDF sem imagem é de poucos KB; a imagem de ruído sozinha tem ~44 KB.
            self::assertLessThan(20000, \strlen($pdf), 'algo grande foi embutido no PDF (' . $descricao . ')');
        }
    }

    /** O `data:` carrega a imagem INTEIRA: sem a troca, o teste mediria um base64 de três letras. */
    private function comSegredo(string $src): string
    {
        return str_replace('{SEGREDO}', base64_encode($this->segredoDeOutro), $src);
    }

    #[TestDox('controle: a imagem do próprio escritório é embutida no PDF (o chroot é a área)')]
    public function testImagemDoProprioEscritorioEntraNoPdf(): void
    {
        $pdf = $this->exportar('<p>oi</p><img src="/uploads/pastas/7/minha.png">', 'pdf');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(20000, \strlen($pdf), 'a imagem do próprio escritório não entrou no PDF');
    }

    /**
     * A segunda tranca, testada sozinha: mesmo que um caminho de fora escapasse da allowlist e
     * chegasse ao Dompdf, o `chroot` é a área deste export e o único protocolo permitido é
     * `file://` — então nem arquivo de fora nem `data:` entram. Por isso o teste chama o gerador
     * direto: pela porta da frente a allowlist já teria barrado, e a tranca de dentro nunca seria
     * exercitada.
     */
    #[TestDox('segunda tranca: o Dompdf não lê arquivo fora da área nem aceita data:')]
    public function testChrootEProtocolosDoPdf(): void
    {
        $fora = $this->dir . '/fora-da-area.png';
        file_put_contents($fora, $this->segredoDeOutro);

        $area = \App\Shared\Armazenamento\AreaTemporariaPrivada::criar('exportteste');

        try {
            $gerar = new \ReflectionMethod($this->useCase, 'gerarPdf');
            $html  = '<p>oi</p><img src="' . $fora . '">'
                . '<img src="data:image/png;base64,' . base64_encode($this->segredoDeOutro) . '">';

            $pdf = $gerar->invoke($this->useCase, $this->doc, $html, $area)->conteudo;
        } finally {
            $area->liberar();
        }

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString($this->segredoDeOutro, $pdf);
        self::assertLessThan(20000, \strlen($pdf), 'o Dompdf embutiu arquivo de fora da área ou um data:');
    }

    /**
     * A chave é aceita byte a byte do que está no disco (D8), e o storage cunha extensão de até 16
     * caracteres. Se a área temporária for mais restrita que isso, uma imagem legítima vira pane —
     * que é o oposto do "pula o que não resolve".
     */
    #[TestDox('imagem com extensão longa (que o storage cunha) é materializada, não derruba o export')]
    public function testExtensaoLongaEhMaterializada(): void
    {
        $conteudo = $this->png(0x55);
        $this->armazenamento->semear(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::MEU_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'foto.jpegjpegjpeg'),
            $conteudo,
        );

        $docx = $this->exportar('<p>oi</p><img src="/uploads/pastas/7/foto.jpegjpegjpeg">', 'docx');

        self::assertContains($conteudo, $this->midiasDoDocx($docx));
        self::assertSame([], $this->logger->doNivel('warning'));
    }

    /**
     * O outro lado: extensão que a área NÃO aceita (o nome vem byte a byte do disco, D8, e um
     * arquivo posto ali por fora pode ter qualquer coisa). Isso é imagem pulada, nunca 500.
     */
    #[TestDox('extensão que a área temporária recusa vira imagem pulada, não erro do export')]
    public function testExtensaoImpossivelEhPulada(): void
    {
        $this->armazenamento->semear(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::MEU_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'foto.extensaoabsurdamentelonga'),
            $this->png(0x66),
        );

        $docx = $this->exportar('<p>oi</p><img src="/uploads/pastas/7/foto.extensaoabsurdamentelonga">', 'docx');

        self::assertSame([], $this->midiasDoDocx($docx));
        self::assertNotSame([], $this->logger->doNivel('warning'));
    }

    #[TestDox('D32: imagem que não existe é pulada — o export não quebra')]
    public function testImagemAusenteEhPulada(): void
    {
        $docx = $this->exportar('<p>oi</p><img src="/uploads/pastas/7/sumiu.png">', 'docx');

        self::assertSame([], $this->midiasDoDocx($docx));
        self::assertNotSame([], $this->logger->doNivel('warning'));
    }

    /**
     * Presente não é sinônimo de legível: o PhpWord LANÇA diante de conteúdo que não é imagem, e
     * uma imagem corrompida no storage tornava a peça inexportável em DOCX/ODT para sempre.
     */
    #[TestDox('imagem presente mas corrompida é pulada — o export não quebra em nenhum formato')]
    public function testImagemCorrompidaEhPulada(): void
    {
        $this->armazenamento->semear(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::MEU_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'quebrada.png'),
            'nao sou uma imagem',
        );
        $html = '<p>oi</p><img src="/uploads/pastas/7/minha.png"><img src="/uploads/pastas/7/quebrada.png">';

        $docx = $this->exportar($html, 'docx');
        $odt  = $this->exportar($html, 'odt');
        $pdf  = $this->exportar($html, 'pdf');

        self::assertSame([$this->minhaImagem], $this->midiasDoDocx($docx), 'a boa tinha de entrar, a quebrada não');
        self::assertNotSame('', $odt);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertNotSame([], $this->logger->doNivel('warning'));
    }

    /**
     * HTML malformado faz o parser empurrar conteúdo para FORA do `<body>`, e a serialização é do
     * corpo: o que está fora não entra no export. Materializar assim mesmo é trabalho perdido — e
     * sumir calado esconde do usuário que a peça estava malformada.
     */
    #[TestDox('imagem que o parser joga para fora do corpo não é materializada, e o descarte é avisado')]
    public function testImagemForaDoCorpoNaoEhMaterializada(): void
    {
        $chaveDaImagem = (new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::MEU_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'minha.png'))->comoTexto();
        $tag           = '<img src="/uploads/pastas/7/minha.png">';

        $this->exportar('<p>oi</p>' . $tag . '</html>' . $tag, 'docx');

        $lidas = array_count_values($this->armazenamento->lidas);
        self::assertSame(1, $lidas[$chaveDaImagem] ?? 0, 'a imagem fora do corpo também foi materializada');
        self::assertNotSame([], $this->logger->doNivel('warning'), 'o descarte do trecho fora do corpo não foi avisado');
    }

    #[TestDox('a imagem do outro escritório não entra nem quando o nome é o mesmo do meu')]
    public function testMesmoNomeEmOutroEscritorioNaoVaza(): void
    {
        $this->armazenamento->semear(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(self::OUTRO_ESCRITORIO), CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, 'minha.png'),
            $this->segredoDeOutro,
        );

        $midias = $this->midiasDoDocx($this->exportar('<p>oi</p><img src="/uploads/pastas/minha.png">', 'docx'));

        self::assertNotContains($this->segredoDeOutro, $midias, 'saiu a imagem do outro escritório');
    }

    #[TestDox('o HTML exportado aponta para a área temporária, e ela some depois')]
    public function testAreaTemporariaSomeDepoisDoExport(): void
    {
        $privado = sys_get_temp_dir() . '/jusprime-export-' . posix_geteuid();
        $antes   = is_dir($privado) ? (glob($privado . '/*') ?: []) : [];

        $this->exportar('<p>oi</p><img src="/uploads/pastas/7/minha.png">', 'docx');

        $depois = is_dir($privado) ? (glob($privado . '/*') ?: []) : [];
        self::assertSame($antes, $depois, 'a área do export ficou para trás');
    }

    /**
     * O TXT não passa por materialização nenhuma: o que prova isso não é a ausência da palavra
     * "uploads" (o `strip_tags` levaria a tag de qualquer jeito), e sim nenhuma área ter sido criada
     * e nenhuma imagem ter sido lida do armazenamento.
     */
    #[TestDox('TXT não materializa imagem nenhuma — nem cria área temporária')]
    public function testTxtNaoMaterializa(): void
    {
        $texto = $this->exportar('<p>oi</p><img src="/uploads/pastas/7/minha.png">', 'txt');

        self::assertStringContainsString('oi', $texto);
        // A prova é o que o armazenamento registrou: só a peça foi lida, a imagem não. Comparar
        // diretórios antes e depois não discrimina — a área criada e liberada dá o mesmo resultado.
        self::assertSame(
            [$this->chaveDaPeca()->comoTexto()],
            $this->armazenamento->lidas,
            'o TXT leu a imagem do armazenamento',
        );
        self::assertSame([], $this->logger->doNivel('warning'), 'o TXT foi decidir sobre imagem');
    }
}
