<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `listar()` e `excluirPrefixo()` do disco, contra arquivos de verdade (E2.5, D7).
 *
 * O que se prova aqui é a parte que só o backend local pode errar: que o prefixo apagado é
 * PROVADAMENTE do escritório — nem a raiz compartilhada, nem o vizinho, nem o alvo de um link —, que
 * a remoção alcança o que o `glob()` antigo deixava para trás (ocultos, subpastas) e que, diante de
 * qualquer dúvida, NADA é apagado.
 *
 * Os casos de link simbólico reproduzem o defeito medido na investigação: com `pastas/5 -> pastas`,
 * a purga antiga (`glob` + `is_file`, que seguem link) apagava os documentos de TODOS os escritórios
 * da raiz plana.
 */
#[CoversClass(ArmazenamentoLocal::class)]
#[CoversClass(ResolvedorDeCaminhoLocal::class)]
final class ArmazenamentoLocalPrefixoTest extends TestCase
{
    private string $raiz;
    private string $fora;
    private ArmazenamentoLocal $backend;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/e25-prefixo-' . bin2hex(random_bytes(6));
        $this->fora = sys_get_temp_dir() . '/e25-fora-' . bin2hex(random_bytes(6));
        mkdir($this->raiz . '/pastas', 0o755, true);
        mkdir($this->raiz . '/cobrancas', 0o755, true);
        mkdir($this->fora, 0o755, true);

        $this->backend = $this->backendCom(clientes: $this->raiz . '/clientes');
    }

    protected function tearDown(): void
    {
        foreach ([$this->raiz, $this->fora] as $dir) {
            $this->liberarPermissoes($dir);
            $this->removerArvore($dir);
        }
    }

    // ------------------------------------------------------------ o caminho feliz, inteiro

    #[TestDox('excluirPrefixo apaga tudo do escritório — ocultos, resíduos e subpastas — e o próprio diretório')]
    public function testApagaTudoInclusiveOcultosESubpastas(): void
    {
        $prefixo = $this->raiz . '/cobrancas/5';
        $this->arquivo($prefixo . '/a.pdf');
        $this->arquivo($prefixo . '/.compress_abc');
        $this->arquivo($prefixo . '/b.pdf.parcial-0123456789abcdef');
        $this->arquivo($prefixo . '/sub/sub2/.oculto');
        $this->arquivo($prefixo . '/sub/c.pdf');

        $resultado = $this->backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);

        self::assertSame(5, $resultado->removidos);
        self::assertTrue($resultado->completa());
        self::assertDirectoryDoesNotExist($prefixo, 'o prefixo inteiro sai — o rmdir antigo falhava calado com dotfile');
    }

    #[TestDox('excluirPrefixo não toca o escritório vizinho, a raiz plana nem o import-tmp')]
    public function testNaoTocaVizinhoRaizPlanaNemImportTmp(): void
    {
        $this->arquivo($this->raiz . '/pastas/5/img.png');
        $this->arquivo($this->raiz . '/pastas/6/img.png', 'do escritório 6');
        $this->arquivo($this->raiz . '/pastas/img.png', 'documento de pasta, raiz compartilhada');
        $this->arquivo($this->raiz . '/pastas/55/img.png', 'escritório 55: prefixo textual do 5');
        $this->arquivo($this->raiz . '/cobrancas/import-tmp/5/previa.xlsx', 'import-tmp é irmão, não filho (D3)');

        $removidos = $this->backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::PASTA_IMAGEM_EDITOR)->removidos;
        $this->backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);

        self::assertSame(1, $removidos);
        self::assertDirectoryDoesNotExist($this->raiz . '/pastas/5');
        self::assertStringEqualsFile($this->raiz . '/pastas/6/img.png', 'do escritório 6');
        self::assertStringEqualsFile($this->raiz . '/pastas/img.png', 'documento de pasta, raiz compartilhada');
        self::assertStringEqualsFile($this->raiz . '/pastas/55/img.png', 'escritório 55: prefixo textual do 5');
        self::assertFileExists($this->raiz . '/cobrancas/import-tmp/5/previa.xlsx');
    }

    #[TestDox('prefixo ausente, ou raiz ausente, é zero — e não erro')]
    public function testPrefixoOuRaizAusenteEhZero(): void
    {
        $ausente = $this->backend->excluirPrefixo($this->escopo(9), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);
        self::assertSame(0, $ausente->removidos);
        self::assertTrue($ausente->completa());
        self::assertSame([], $this->backend->listar($this->escopo(9), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO));

        $semRaiz = $this->backendCom(cobrancas: $this->raiz . '/nao-existe/cobrancas');
        self::assertSame(0, $semRaiz->excluirPrefixo($this->escopo(9), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO)->removidos);
    }

    #[TestDox('o prefixo apagado é o mesmo diretório em que a gravação por chave colocou o arquivo')]
    public function testPrefixoEhOndeAGravacaoEscreve(): void
    {
        $chave = new ChaveDeArquivo($this->escopo(5), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'gravado.pdf');
        $this->backend->gravar($chave, FonteDeConteudo::deTexto('x'));

        self::assertSame(1, $this->backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO)->removidos);
        self::assertFalse($this->backend->existe($chave));
    }

    // ------------------------------------------------------------ falha fechada

    #[TestDox('escopo global é recusado, e nada é apagado')]
    public function testEscopoGlobalRecusado(): void
    {
        $this->arquivo($this->raiz . '/pastas/doc.pdf');

        try {
            $this->backend->excluirPrefixo(EscopoDeArquivo::global(), CategoriaComIsolamentoFisico::PASTA_IMAGEM_EDITOR);
            self::fail('escopo global devia ter sido recusado');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('escopo global', $e->getMessage());
        }

        self::assertFileExists($this->raiz . '/pastas/doc.pdf');
    }

    /** @return iterable<string, array{string}> */
    public static function alvosDeLink(): iterable
    {
        yield 'para a própria raiz compartilhada' => ['.'];
        yield 'para fora do volume'               => ['FORA'];
    }

    #[TestDox('prefixo que é link simbólico ($alvo) é recusado, e nada é apagado — nem a raiz, nem o alvo')]
    #[DataProvider('alvosDeLink')]
    public function testPrefixoQueEhLinkEhRecusado(string $alvo): void
    {
        $this->arquivo($this->raiz . '/pastas/doc-de-outro-escritorio.pdf');
        $this->arquivo($this->fora . '/alheio.pdf');
        symlink($alvo === 'FORA' ? $this->fora : $alvo, $this->raiz . '/pastas/5');

        $this->esperarFalhaSemApagar(CategoriaComIsolamentoFisico::PASTA_IMAGEM_EDITOR, 'link simbólico');

        self::assertFileExists($this->raiz . '/pastas/doc-de-outro-escritorio.pdf');
        self::assertFileExists($this->fora . '/alheio.pdf');
        self::assertTrue(is_link($this->raiz . '/pastas/5'), 'o link fica — é evidência para quem investigar');
    }

    /** @return iterable<string, array{string}> */
    public static function linksDentro(): iterable
    {
        yield 'para diretório, no topo'           => ['link-dir'];
        yield 'para arquivo, numa subpasta funda' => ['sub/sub2/link-arquivo'];
    }

    #[TestDox('link simbólico DENTRO do prefixo ($onde) faz a operação inteira falhar antes de apagar qualquer coisa')]
    #[DataProvider('linksDentro')]
    public function testLinkDentroDoPrefixoFalhaFechado(string $onde): void
    {
        $prefixo = $this->raiz . '/cobrancas/5';
        $this->arquivo($prefixo . '/a.pdf', 'fica');
        $this->arquivo($prefixo . '/sub/sub2/b.pdf', 'fica também');
        $this->arquivo($this->fora . '/alheio.pdf');
        mkdir($this->fora . '/dir');
        $this->arquivo($this->fora . '/dir/dentro.pdf');
        symlink(
            $onde === 'link-dir' ? $this->fora . '/dir' : $this->fora . '/alheio.pdf',
            $prefixo . '/' . $onde,
        );

        $this->esperarFalhaSemApagar(CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO, 'link simbólico');

        self::assertStringEqualsFile($prefixo . '/a.pdf', 'fica', 'a inspeção falhou: nenhum arquivo do prefixo pode ter saído');
        self::assertStringEqualsFile($prefixo . '/sub/sub2/b.pdf', 'fica também');
        self::assertFileExists($this->fora . '/alheio.pdf');
        self::assertFileExists($this->fora . '/dir/dentro.pdf');
    }

    #[TestDox('arquivo especial (FIFO) dentro do prefixo faz a operação falhar sem apagar nada')]
    public function testArquivoEspecialFalhaFechado(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo indisponível');
        }

        $prefixo = $this->raiz . '/cobrancas/5';
        $this->arquivo($prefixo . '/a.pdf');
        posix_mkfifo($prefixo . '/fila', 0o600);

        $this->esperarFalhaSemApagar(CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO, 'não é arquivo regular');

        self::assertFileExists($prefixo . '/a.pdf');
    }

    #[TestDox('subpasta ilegível faz a operação falhar sem apagar nada — "não consegui listar" não é "vazio"')]
    public function testSubpastaIlegivelFalhaFechado(): void
    {
        $this->pularSeRoot();

        $prefixo = $this->raiz . '/cobrancas/5';
        $this->arquivo($prefixo . '/a.pdf');
        $this->arquivo($prefixo . '/trancada/b.pdf');
        chmod($prefixo . '/trancada', 0o000);

        $this->esperarFalhaSemApagar(CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO, 'Não foi possível listar');

        self::assertFileExists($prefixo . '/a.pdf');
    }

    #[TestDox('ancestral ilegível é falha, e não "o prefixo não existe"')]
    public function testAncestralIlegivelEhFalhaNaoAusencia(): void
    {
        $this->pularSeRoot();

        $this->arquivo($this->raiz . '/cobrancas/5/a.pdf');
        chmod($this->raiz . '/cobrancas', 0o000);

        foreach (['excluirPrefixo', 'listar'] as $metodo) {
            try {
                $this->backend->{$metodo}($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);
                self::fail($metodo . ' devia ter lançado: diretório ilegível não prova ausência');
            } catch (FalhaDeArmazenamento $e) {
                self::assertStringContainsString('não é legível', $e->getMessage());
            }
        }

        chmod($this->raiz . '/cobrancas', 0o755);
        self::assertFileExists($this->raiz . '/cobrancas/5/a.pdf');
    }

    #[TestDox('prefixo que existe mas não é diretório é recusado')]
    public function testPrefixoQueNaoEhDiretorio(): void
    {
        $this->arquivo($this->raiz . '/pastas/5', 'um documento chamado "5"');

        $this->esperarFalhaSemApagar(CategoriaComIsolamentoFisico::PASTA_IMAGEM_EDITOR, 'não é diretório');

        self::assertStringEqualsFile($this->raiz . '/pastas/5', 'um documento chamado "5"');
    }

    #[TestDox('prefixo que coincide com a raiz de outra categoria é recusado (erro de configuração)')]
    public function testPrefixoQueEhRaizDeOutraCategoria(): void
    {
        // Configuração cruzada: o diretório de clientes de TODOS os escritórios mora onde seria
        // o prefixo de cobrança do escritório 5.
        $backend = $this->backendCom(clientes: $this->raiz . '/cobrancas/5');
        $this->arquivo($this->raiz . '/cobrancas/5/cliente-de-outro-escritorio.pdf');

        try {
            $backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);
            self::fail('devia recusar um prefixo que é raiz de outra categoria');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('raiz configurada', $e->getMessage());
        }

        self::assertFileExists($this->raiz . '/cobrancas/5/cliente-de-outro-escritorio.pdf');
    }

    #[TestDox('prefixo que CONTÉM a raiz de outra categoria é recusado')]
    public function testPrefixoQueContemOutraRaiz(): void
    {
        $backend = $this->backendCom(clientes: $this->raiz . '/cobrancas/5/clientes');
        $this->arquivo($this->raiz . '/cobrancas/5/clientes/doc.pdf');

        try {
            $backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);
            self::fail('devia recusar um prefixo que contém a raiz de outra categoria');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('raiz configurada', $e->getMessage());
        }

        self::assertFileExists($this->raiz . '/cobrancas/5/clientes/doc.pdf');
    }

    /**
     * O item que não sai vem PRIMEIRO na ordem do inventário — senão um `break` na primeira falha
     * passaria verde. A sobra volta no resultado, em caminho relativo ao escopo, e não como exceção.
     */
    #[TestDox('falha de I/O num item não impede os demais, e o que sobrou volta no resultado')]
    public function testRemocaoParcialDevolveAsSobras(): void
    {
        $this->pularSeRoot();

        $prefixo = $this->raiz . '/cobrancas/5';
        $this->arquivo($prefixo . '/a-trancada/b.pdf');
        $this->arquivo($prefixo . '/z.pdf');
        chmod($prefixo . '/a-trancada', 0o555); // legível (a inspeção passa), mas não dá para apagar dentro

        $resultado = $this->backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);

        self::assertSame(1, $resultado->removidos, 'o que vinha DEPOIS da falha também saiu');
        self::assertFileDoesNotExist($prefixo . '/z.pdf');
        self::assertFileExists($prefixo . '/a-trancada/b.pdf');

        $rotulo = $this->escopo(5)->comoTexto() . '/cobranca_documento';
        self::assertSame(
            [$rotulo . '/a-trancada/b.pdf', $rotulo . '/a-trancada/', $rotulo . '/'],
            $resultado->naoRemovidas,
        );
        foreach ($resultado->naoRemovidas as $item) {
            self::assertStringNotContainsString($this->raiz, $item, 'caminho absoluto de disco não sai do backend');
        }
    }

    /**
     * A troca que a revisão desenhou: o prefixo vira link DEPOIS da prova. As etapas são chamadas
     * por reflexão, na ordem de `excluirPrefixo()`, para a troca acontecer no meio sem corrida.
     */
    #[TestDox('prefixo trocado por link entre a prova e a remoção: a identidade não confere e o alvo não é tocado')]
    public function testTrocaDoPrefixoDepoisDaProvaEhRecusada(): void
    {
        $prefixo = $this->raiz . '/pastas/5';
        $this->arquivo($prefixo . '/aaa/img.png');
        $this->arquivo($this->fora . '/aaa/alvo.txt');

        $comprovado = $this->invocar('prefixoComprovado', $this->escopo(5), CategoriaComIsolamentoFisico::PASTA_IMAGEM_EDITOR);
        rename($prefixo, $this->raiz . '/pastas/5-original');
        symlink($this->fora, $prefixo);

        $arquivos   = [];
        $diretorios = [];
        $inventariar = new \ReflectionMethod($this->backend, 'inventariar');
        $inventariar->invokeArgs($this->backend, [$comprovado['caminho'], $comprovado['dev'], &$arquivos, &$diretorios]);
        $alvo = $comprovado['caminho'] . '/aaa/alvo.txt';
        self::assertArrayHasKey($alvo, $arquivos, 'pré-condição: o inventário desceu pelo link trocado');

        try {
            $this->invocar('exigirMesmaIdentidade', $comprovado['caminho'], $comprovado['dev'], $comprovado['ino']);
            self::fail('a troca do prefixo devia ter sido percebida');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('mudou entre a prova', $e->getMessage());
        }

        self::assertFalse(
            $this->invocar('continuaComoInventariado', $alvo, $arquivos[$alvo]['dev'], $arquivos[$alvo]['ino']),
            'com o cache de realpath limpo, o pai já não é o caminho inventariado',
        );
        self::assertFileExists($this->fora . '/aaa/alvo.txt');
    }

    /**
     * A camada que fica atrás da identidade do prefixo: entre a reconferência de um arquivo e a do
     * seguinte, a subpasta é MOVIDA para fora (os inodes não mudam) e um link toma o lugar dela.
     * A primeira reconferência deixou o pai no cache de `realpath()`; limpando só o caminho do
     * arquivo, o pai ainda resolveria para dentro do prefixo e o `unlink` seguiria o link.
     * Nada de limpeza global entre as duas chamadas — é isso que o teste anterior não isola.
     *
     * A troca é feita por OUTRO processo (`mv`), como seria na vida real: o `rename()` do PHP
     * limpa o cache de `realpath()` inteiro quando dá certo e esconderia exatamente o que se testa.
     */
    #[TestDox('subpasta trocada por link entre duas reconferências: o arquivo que foi para fora não é tocado')]
    public function testSubpastaTrocadaPorLinkEntreDuasReconferenciasNaoEhSeguida(): void
    {
        if (!\function_exists('exec')) {
            self::markTestSkipped('sem exec(): a troca precisa vir de outro processo');
        }

        $this->arquivo($this->raiz . '/cobrancas/5/aaa/1.pdf');
        $this->arquivo($this->raiz . '/cobrancas/5/aaa/2.pdf');
        $prefixoReal = (string) realpath($this->raiz . '/cobrancas/5');
        $primeiro    = lstat($prefixoReal . '/aaa/1.pdf');
        $segundo     = lstat($prefixoReal . '/aaa/2.pdf');

        self::assertTrue($this->invocar('continuaComoInventariado', $prefixoReal . '/aaa/1.pdf', $primeiro['dev'], $primeiro['ino']));
        self::assertArrayHasKey(
            $prefixoReal . '/aaa',
            realpath_cache_get(),
            'pré-condição: a reconferência deixou o pai no cache de realpath (com o cache desligado o teste não prova nada)',
        );

        exec(sprintf('mv %s %s', escapeshellarg($prefixoReal . '/aaa'), escapeshellarg($this->fora . '/aaa')), $saida, $codigo);
        self::assertSame(0, $codigo, 'pré-condição: a subpasta foi movida por outro processo');
        symlink($this->fora . '/aaa', $prefixoReal . '/aaa');

        clearstatcache(false); // só o cache de stat; o de realpath fica como a reconferência o deixou
        self::assertSame($segundo['ino'], lstat($prefixoReal . '/aaa/2.pdf')['ino'], 'pré-condição: pelo link, o mesmo inode');

        self::assertFalse(
            $this->invocar('continuaComoInventariado', $prefixoReal . '/aaa/2.pdf', $segundo['dev'], $segundo['ino']),
            'o pai agora resolve para fora do prefixo',
        );
        self::assertFileExists($this->fora . '/aaa/2.pdf');
    }

    #[TestDox('arquivo que mudou de inode desde o inventário não é removido')]
    public function testArquivoTrocadoDepoisDoInventarioNaoEhRemovido(): void
    {
        $caminho = $this->raiz . '/pastas/5/img.png';
        $this->arquivo($caminho);
        $info = lstat($caminho);

        self::assertTrue($this->invocar('continuaComoInventariado', $caminho, $info['dev'], $info['ino']));
        self::assertFalse($this->invocar('continuaComoInventariado', $caminho, $info['dev'], $info['ino'] + 1));
        self::assertFalse($this->invocar('continuaComoInventariado', $caminho, $info['dev'] + 1, $info['ino']));
    }

    /** Um sistema de arquivos montado dentro do prefixo não pode ser criado sem root: a guarda é exercitada direto. */
    #[TestDox('entrada em outro dispositivo que o do prefixo faz o inventário falhar')]
    public function testOutroDispositivoEhRecusado(): void
    {
        $prefixo = $this->raiz . '/cobrancas/5';
        $this->arquivo($prefixo . '/a.pdf');

        $arquivos    = [];
        $diretorios  = [];
        $inventariar = new \ReflectionMethod($this->backend, 'inventariar');

        $this->expectException(FalhaDeArmazenamento::class);
        $this->expectExceptionMessage('outro sistema de arquivos');
        $inventariar->invokeArgs($this->backend, [$prefixo, lstat($prefixo)['dev'] + 1, &$arquivos, &$diretorios]);
    }

    #[TestDox('raiz que é link quebrado não é "ainda não existe": é falha')]
    public function testRaizQueEhLinkQuebrado(): void
    {
        $backend = $this->backendCom(cobrancas: $this->raiz . '/cobrancas-volume');
        symlink($this->raiz . '/volume-que-sumiu', $this->raiz . '/cobrancas-volume');

        $this->expectException(FalhaDeArmazenamento::class);
        $this->expectExceptionMessage('link simbólico quebrado');
        $backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);
    }

    #[TestDox('raiz legítima que é link (volume montado em outro lugar) funciona')]
    public function testRaizQueEhLinkValido(): void
    {
        mkdir($this->fora . '/volume');
        symlink($this->fora . '/volume', $this->raiz . '/cobrancas-volume');
        $this->arquivo($this->fora . '/volume/5/doc.pdf');
        $backend = $this->backendCom(cobrancas: $this->raiz . '/cobrancas-volume');

        self::assertSame(1, $backend->excluirPrefixo($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO)->removidos);
        self::assertDirectoryDoesNotExist($this->fora . '/volume/5');
        self::assertDirectoryExists($this->fora . '/volume');
    }

    // ------------------------------------------------------------ listar

    #[TestDox('listar devolve só arquivos endereçáveis do escopo, na categoria pedida')]
    public function testListarDevolveSoArquivosEnderecaveis(): void
    {
        $prefixo = $this->raiz . '/cobrancas/5';
        $this->arquivo($prefixo . '/b.pdf');
        $this->arquivo($prefixo . '/a.pdf');
        $this->arquivo($prefixo . '/.compress_x');
        $this->arquivo($prefixo . '/c.pdf.parcial-0123456789abcdef');
        $this->arquivo($prefixo . '/sub/d.pdf');
        $this->arquivo($this->raiz . '/cobrancas/6/e.pdf');

        $chaves = $this->backend->listar($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);

        self::assertSame(['a.pdf', 'b.pdf'], array_map(static fn (ChaveDeArquivo $c): string => $c->nome, $chaves));
        foreach ($chaves as $chave) {
            self::assertSame(CategoriaDeArquivo::COBRANCA_DOCUMENTO, $chave->categoria);
            self::assertTrue($chave->escopo->ehIgualA($this->escopo(5)));
            self::assertTrue($this->backend->existe($chave), 'cada chave listada endereça o arquivo de verdade');
        }
    }

    #[TestDox('listar falha diante de arquivo especial no prefixo')]
    public function testListarFalhaComArquivoEspecial(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo indisponível');
        }

        mkdir($this->raiz . '/cobrancas/5');
        posix_mkfifo($this->raiz . '/cobrancas/5/fila', 0o600);

        $this->expectException(FalhaDeArmazenamento::class);
        $this->backend->listar($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);
    }

    #[TestDox('listar falha diante de link simbólico no prefixo')]
    public function testListarFalhaComLink(): void
    {
        $this->arquivo($this->fora . '/alheio.pdf');
        mkdir($this->raiz . '/cobrancas/5');
        symlink($this->fora . '/alheio.pdf', $this->raiz . '/cobrancas/5/alheio.pdf');

        $this->expectException(FalhaDeArmazenamento::class);
        $this->backend->listar($this->escopo(5), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);
    }

    // ------------------------------------------------------------ D7, a barreira de tipo

    /** @return iterable<string, array{CategoriaDeArquivo}> */
    public static function categoriasPlanas(): iterable
    {
        foreach (CategoriaDeArquivo::cases() as $categoria) {
            if (CategoriaComIsolamentoFisico::deCategoriaOuNull($categoria) === null) {
                yield $categoria->value => [$categoria];
            }
        }
    }

    /**
     * §11.2 (b): mesmo forçando o adapter por reflexão, a categoria plana não passa — e nada é
     * apagado. A barreira é o tipo do parâmetro: o PHP recusa antes da primeira linha do método.
     */
    #[TestDox('D7: categoria plana ($categoria) forçada por reflexão é recusada e nada é apagado')]
    #[DataProvider('categoriasPlanas')]
    public function testCategoriaPlanaForcadaPorReflexaoEhRecusada(CategoriaDeArquivo $categoria): void
    {
        $this->arquivo($this->raiz . '/clientes/de-todos-os-escritorios.pdf');
        $this->arquivo($this->raiz . '/pastas/documento.pdf');

        self::assertNull(CategoriaComIsolamentoFisico::tryFrom($categoria->value));

        foreach (['excluirPrefixo', 'listar'] as $metodo) {
            try {
                (new \ReflectionMethod($this->backend, $metodo))->invoke($this->backend, $this->escopo(5), $categoria);
                self::fail($metodo . ' aceitou categoria plana');
            } catch (\TypeError $e) {
                self::assertStringContainsString(CategoriaComIsolamentoFisico::class, $e->getMessage());
            }
        }

        self::assertFileExists($this->raiz . '/clientes/de-todos-os-escritorios.pdf');
        self::assertFileExists($this->raiz . '/pastas/documento.pdf');
    }

    // ------------------------------------------------------------ excluir() do núcleo (E2.5)

    #[TestDox('excluir() LANÇA quando um ancestral é ilegível — antes voltava calado e o arquivo ficava')]
    public function testExcluirLancaComAncestralIlegivel(): void
    {
        $this->pularSeRoot();

        $chave = new ChaveDeArquivo($this->escopo(5), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'a.pdf');
        $this->backend->gravar($chave, FonteDeConteudo::deTexto('x'));
        chmod($this->raiz, 0o000);

        try {
            $this->backend->excluir($chave);
            self::fail('excluir() devia ter lançado');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('não é legível', $e->getMessage());
        } finally {
            chmod($this->raiz, 0o755);
        }

        self::assertTrue($this->backend->existe($chave));
    }

    #[TestDox('excluir() LANÇA quando o arquivo existe e não pôde ser removido')]
    public function testExcluirLancaQuandoNaoRemove(): void
    {
        $this->pularSeRoot();

        $chave = new ChaveDeArquivo($this->escopo(5), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'a.pdf');
        $this->backend->gravar($chave, FonteDeConteudo::deTexto('x'));
        chmod($this->raiz . '/clientes', 0o555);

        try {
            $this->backend->excluir($chave);
            self::fail('excluir() devia ter lançado');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('Não foi possível remover', $e->getMessage());
        } finally {
            chmod($this->raiz . '/clientes', 0o755);
        }

        self::assertTrue($this->backend->existe($chave));
    }

    // ------------------------------------------------------------ apoio

    private function esperarFalhaSemApagar(CategoriaComIsolamentoFisico $categoria, string $motivo): void
    {
        try {
            $this->backend->excluirPrefixo($this->escopo(5), $categoria);
            self::fail('a operação devia ter falhado fechada');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString($motivo, $e->getMessage());
        }
    }

    private function invocar(string $metodo, mixed ...$argumentos): mixed
    {
        return (new \ReflectionMethod($this->backend, $metodo))->invoke($this->backend, ...$argumentos);
    }

    private function backendCom(?string $clientes = null, ?string $cobrancas = null): ArmazenamentoLocal
    {
        return new ArmazenamentoLocal(new ResolvedorDeCaminhoLocal(
            uploadsDir: $this->raiz . '/pastas',
            clientesUploadsDir: $clientes ?? $this->raiz . '/clientes',
            chamadosUploadsDir: $this->raiz . '/chamados',
            justificativasUploadsDir: $this->raiz . '/justificativas',
            fotosPerfilDir: $this->raiz . '/perfil',
            cobrancasUploadsDir: $cobrancas ?? $this->raiz . '/cobrancas',
            kanbanUploadsDir: $this->raiz . '/kanban',
        ));
    }

    private function escopo(int $tenantId): EscopoDeArquivo
    {
        return EscopoDeArquivo::deTenant($tenantId);
    }

    private function arquivo(string $caminho, string $conteudo = 'x'): void
    {
        if (!is_dir(\dirname($caminho))) {
            mkdir(\dirname($caminho), 0o755, true);
        }

        file_put_contents($caminho, $conteudo);
    }

    private function pularSeRoot(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório; o guarda não é observável');
        }
    }

    private function liberarPermissoes(string $caminho): void
    {
        if (is_link($caminho) || !is_dir($caminho)) {
            return;
        }

        @chmod($caminho, 0o755);

        foreach (scandir($caminho) ?: [] as $entrada) {
            if ($entrada !== '.' && $entrada !== '..') {
                $this->liberarPermissoes($caminho . '/' . $entrada);
            }
        }
    }

    private function removerArvore(string $caminho): void
    {
        if (is_link($caminho) || is_file($caminho) || (file_exists($caminho) && !is_dir($caminho))) {
            @unlink($caminho);

            return;
        }

        if (!is_dir($caminho)) {
            return;
        }

        foreach (scandir($caminho) ?: [] as $entrada) {
            if ($entrada !== '.' && $entrada !== '..') {
                $this->removerArvore($caminho . '/' . $entrada);
            }
        }

        @rmdir($caminho);
    }
}
