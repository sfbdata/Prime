<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Contrato;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\NovoArquivo;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato de `ArmazenamentoDeArquivos`, escrito UMA vez e cobrado de todo backend.
 *
 * Roda hoje contra `ArmazenamentoLocal` e `ArmazenamentoEmMemoria`. Quando o `R2Storage` chegar
 * na E4, herdar desta classe é o que prova que ele se comporta igual — e é por isso que nenhum
 * caso aqui pode espiar a implementação: tudo passa por chave e pelos seis métodos.
 *
 * Os números citados nos casos não são hipóteses. Vêm da auditoria E0 e da medição em `saas_ux`:
 * 114 arquivos de 0 byte em produção, 165 chaves terminadas em `.` e 3 com espaço nas bordas.
 */
abstract class ArmazenamentoContratoTestCase extends TestCase
{
    abstract protected function backend(): ArmazenamentoDeArquivos;

    /** Categoria plana, que não desce para subpasta de tenant — o caso mais comum. */
    protected function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant(1),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            $nome,
        );
    }

    protected function novo(string $extensao = 'pdf'): NovoArquivo
    {
        return new NovoArquivo(
            EscopoDeArquivo::deTenant(1),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            $extensao,
        );
    }

    #[TestDox('gravar, ler, existir, medir e excluir formam o ciclo completo')]
    public function testCicloCompleto(): void
    {
        $backend = $this->backend();
        $chave   = $this->chave('ciclo-completo.txt');

        self::assertFalse($backend->existe($chave), 'nada deveria existir antes de gravar');
        self::assertNull($backend->metadados($chave));

        $gravado = $backend->gravar($chave, FonteDeConteudo::deTexto('conteudo de teste'));

        self::assertTrue($gravado->chave->ehIgualA($chave));
        self::assertSame(17, $gravado->tamanhoBytes);
        self::assertTrue($backend->existe($chave));
        self::assertSame('conteudo de teste', $backend->ler($chave));
        self::assertSame(17, $backend->metadados($chave)?->tamanhoBytes);

        $backend->excluir($chave);

        self::assertFalse($backend->existe($chave));
        self::assertNull($backend->metadados($chave));
    }

    #[TestDox('excluir é idempotente: apagar o que não existe não é erro')]
    public function testExcluirEhIdempotente(): void
    {
        $backend = $this->backend();
        $chave   = $this->chave('nunca-existiu.txt');

        $backend->excluir($chave);
        $backend->excluir($chave);

        self::assertFalse($backend->existe($chave));
    }

    /**
     * Não é caso de borda inventado: a E0 encontrou 114 arquivos de 0 byte em produção, vindos do
     * Drive, e o dono decidiu migrá-los como estão. Um backend que confunda "vazio" com "ausente"
     * os apagaria.
     */
    #[TestDox('arquivo de 0 byte existe, é legível e tem tamanho zero')]
    public function testArquivoVazio(): void
    {
        $backend = $this->backend();
        $chave   = $this->chave('vazio.bin');

        $gravado = $backend->gravar($chave, FonteDeConteudo::deTexto(''));

        self::assertSame(0, $gravado->tamanhoBytes);
        self::assertTrue($backend->existe($chave), 'arquivo de 0 byte EXISTE');
        self::assertSame('', $backend->ler($chave));
        self::assertSame(0, $backend->metadados($chave)?->tamanhoBytes);
    }

    #[TestDox('gravar sobre a mesma chave substitui o conteúdo e mantém a chave')]
    public function testSobrescreverMesmaChave(): void
    {
        $backend = $this->backend();
        $chave   = $this->chave('sobrescrito.html');

        $backend->gravar($chave, FonteDeConteudo::deTexto('<p>versao 1</p>'));
        $segundo = $backend->gravar($chave, FonteDeConteudo::deTexto('<p>versao 2 bem maior</p>'));

        self::assertTrue($segundo->chave->ehIgualA($chave));
        self::assertSame('<p>versao 2 bem maior</p>', $backend->ler($chave));
        self::assertSame(25, $backend->metadados($chave)?->tamanhoBytes);
    }

    #[TestDox('ler e abrir de chave inexistente lançam ArquivoNaoEncontrado')]
    public function testLerInexistenteLanca(): void
    {
        $backend = $this->backend();

        $this->expectException(ArquivoNaoEncontrado::class);
        $backend->ler($this->chave('fantasma.pdf'));
    }

    #[TestDox('abrir de chave inexistente lança ArquivoNaoEncontrado')]
    public function testAbrirInexistenteLanca(): void
    {
        $backend = $this->backend();

        $this->expectException(ArquivoNaoEncontrado::class);
        $backend->abrir($this->chave('fantasma.pdf'));
    }

    #[TestDox('abrir devolve um recurso legível em streaming')]
    public function testAbrirDevolveStream(): void
    {
        $backend = $this->backend();
        $chave   = $this->chave('stream.txt');
        $backend->gravar($chave, FonteDeConteudo::deTexto('abcdefghij'));

        $recurso = $backend->abrir($chave);

        self::assertIsResource($recurso);
        self::assertSame('abcde', fread($recurso, 5), 'deve dar para ler em pedaços');
        self::assertSame('fghij', stream_get_contents($recurso));
        fclose($recurso);
    }

    #[TestDox('as três formas de FonteDeConteudo gravam o mesmo conteúdo')]
    public function testTresFormasDeFonte(): void
    {
        $backend = $this->backend();

        $backend->gravar($this->chave('de-texto.txt'), FonteDeConteudo::deTexto('mesmo conteudo'));

        $origemCopiada = $this->arquivoTemporarioCom('mesmo conteudo');
        $backend->gravar($this->chave('de-arquivo.txt'), FonteDeConteudo::deArquivoLocal($origemCopiada));

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'mesmo conteudo');
        rewind($stream);
        $backend->gravar($this->chave('de-stream.txt'), FonteDeConteudo::deStream($stream));
        fclose($stream);

        self::assertSame('mesmo conteudo', $backend->ler($this->chave('de-texto.txt')));
        self::assertSame('mesmo conteudo', $backend->ler($this->chave('de-arquivo.txt')));
        self::assertSame('mesmo conteudo', $backend->ler($this->chave('de-stream.txt')));

        self::assertFileExists($origemCopiada, 'sem consumirOrigem, a origem continua lá');
        @unlink($origemCopiada);
    }

    #[TestDox('consumirOrigem move o arquivo de origem em vez de copiá-lo')]
    public function testConsumirOrigemRemoveAOrigem(): void
    {
        $backend = $this->backend();
        $origem  = $this->arquivoTemporarioCom('vindo do Drive');

        $backend->gravar(
            $this->chave('consumido.bin'),
            FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: true),
        );

        self::assertSame('vindo do Drive', $backend->ler($this->chave('consumido.bin')));
        self::assertFileDoesNotExist($origem, 'com consumirOrigem, a origem deixa de existir');
    }

    #[TestDox('NovoArquivo faz o storage cunhar a chave e devolvê-la no resultado')]
    public function testNovoArquivoCunhaChave(): void
    {
        $backend = $this->backend();

        $gravado = $backend->gravar($this->novo('pdf'), FonteDeConteudo::deTexto('conteudo'));

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}\.pdf$/',
            $gravado->chave->nome,
            'nome opaco de 128 bits mais a extensão, como o formato de hoje',
        );
        self::assertTrue($backend->existe($gravado->chave), 'a chave devolvida tem de endereçar o arquivo');
        self::assertSame('conteudo', $backend->ler($gravado->chave));
    }

    #[TestDox('cada NovoArquivo gera uma chave diferente')]
    public function testNovoArquivoGeraChavesDistintas(): void
    {
        $backend = $this->backend();

        $primeiro = $backend->gravar($this->novo(), FonteDeConteudo::deTexto('a'));
        $segundo  = $backend->gravar($this->novo(), FonteDeConteudo::deTexto('b'));

        self::assertNotSame($primeiro->chave->nome, $segundo->chave->nome);
        self::assertSame('a', $backend->ler($primeiro->chave));
        self::assertSame('b', $backend->ler($segundo->chave));
    }

    /**
     * Chave legada terminada em ponto — 165 delas em `pasta_documento`, vindas de
     * `salvarConteudo(..., extensao: '')`. Se o backend "arrumar" o nome, 165 arquivos somem.
     */
    #[TestDox('chave legada terminada em ponto continua endereçável byte a byte')]
    public function testChaveLegadaComPontoFinal(): void
    {
        $backend = $this->backend();
        $nome    = '3fa85f6457174562b3fc2c963f66afa6.';
        $chave   = $this->chave($nome);

        $gravado = $backend->gravar($chave, FonteDeConteudo::deTexto('documento legado'));

        self::assertSame($nome, $gravado->chave->nome, 'o ponto final não pode sumir');
        self::assertTrue($backend->existe($chave));
        self::assertSame('documento legado', $backend->ler($chave));
    }

    /** Três chaves com espaço nas bordas existem em produção. `trim()` as tornaria inalcançáveis. */
    #[TestDox('chave legada com espaço nas bordas continua endereçável byte a byte')]
    public function testChaveLegadaComEspacoNasBordas(): void
    {
        $backend = $this->backend();
        $nome    = ' contrato assinado.pdf ';
        $chave   = $this->chave($nome);

        $gravado = $backend->gravar($chave, FonteDeConteudo::deTexto('x'));

        self::assertSame($nome, $gravado->chave->nome);
        self::assertTrue($backend->existe($chave));
        self::assertSame('x', $backend->ler($chave));
        self::assertFalse(
            $backend->existe($this->chave(trim($nome))),
            'a versão trimada é OUTRO arquivo e não pode ser encontrada no lugar',
        );
    }

    #[TestDox('chave com acento e caractere multibyte não é normalizada')]
    public function testChaveUnicodeNaoEhNormalizada(): void
    {
        $backend = $this->backend();

        // "peça" em NFC (ç como um único code point) e em NFD (c + cedilha combinante).
        $nfc = "pe\u{00E7}a-inicial.pdf";
        $nfd = "pec\u{0327}a-inicial.pdf";

        self::assertNotSame($nfc, $nfd, 'pré-condição: são bytes diferentes');

        $backend->gravar($this->chave($nfc), FonteDeConteudo::deTexto('forma composta'));
        $backend->gravar($this->chave($nfd), FonteDeConteudo::deTexto('forma decomposta'));

        self::assertSame('forma composta', $backend->ler($this->chave($nfc)));
        self::assertSame(
            'forma decomposta',
            $backend->ler($this->chave($nfd)),
            'normalizar Unicode faria as duas colidirem e uma sobrescreveria a outra',
        );
    }

    /**
     * `deStream` lê a partir da posição CORRENTE, e isso é contrato, não acidente.
     *
     * Quem farejou o MIME lendo os primeiros bytes e não rebobinou grava o arquivo truncado.
     * O teste fixa a semântica para que nenhum backend "conserte" isso rebobinando o recurso do
     * chamador — rebobinar recurso alheio é efeito colateral surpresa.
     */
    #[TestDox('deStream grava a partir da posição corrente do recurso')]
    public function testStreamLeDaPosicaoCorrente(): void
    {
        $backend = $this->backend();

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'CABECALHOconteudo real');
        fseek($stream, 9); // quem leu o cabeçalho para farejar o tipo para aqui

        $backend->gravar($this->chave('da-posicao.bin'), FonteDeConteudo::deStream($stream));
        fclose($stream);

        self::assertSame('conteudo real', $backend->ler($this->chave('da-posicao.bin')));
    }

    /**
     * Sobrescrever é a operação mais perigosa do contrato: é o único caminho em que um arquivo
     * válido pode virar lixo. Se a fonte falhar no meio, o conteúdo anterior tem de estar lá.
     */
    #[TestDox('falha no meio da gravação não destrói o conteúdo anterior')]
    public function testFalhaNaGravacaoPreservaConteudoAnterior(): void
    {
        $backend = $this->backend();
        $chave   = $this->chave('preservado.txt');

        $backend->gravar($chave, FonteDeConteudo::deTexto('conteudo bom que nao pode sumir'));

        $streamFechado = fopen('php://temp', 'w+b');
        $fonte         = FonteDeConteudo::deStream($streamFechado);
        fclose($streamFechado); // o recurso morre ANTES de gravar

        try {
            $backend->gravar($chave, $fonte);
        } catch (\Throwable) {
            // o contrato permite lançar; o que não permite é perder o conteúdo
        }

        self::assertTrue($backend->existe($chave), 'o arquivo anterior não pode ter sumido');
        self::assertSame('conteudo bom que nao pode sumir', $backend->ler($chave));
    }

    protected function arquivoTemporarioCom(string $conteudo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'contrato-origem-');
        self::assertNotFalse($caminho);
        file_put_contents($caminho, $conteudo);

        return $caminho;
    }
}
