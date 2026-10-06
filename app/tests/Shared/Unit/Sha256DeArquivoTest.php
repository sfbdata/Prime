<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\Sha256DeArquivo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O cálculo por trás de `pasta_documento.sha256`. O que importa aqui: o resultado é o mesmo de
 * `hash('sha256', …)` em qualquer das quatro origens, e o arquivo NÃO passa inteiro pela memória.
 */
#[CoversClass(Sha256DeArquivo::class)]
final class Sha256DeArquivoTest extends TestCase
{
    private const SHA_DO_VAZIO = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** @var list<string> */
    private array $arquivos = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivos as $arquivo) {
            @unlink($arquivo);
        }

        $this->arquivos = [];
    }

    #[TestDox('deStream: conteúdo maior que o bloco de leitura dá o mesmo hash de hash()')]
    public function testDeStreamBateComHashDoPhp(): void
    {
        $conteudo = str_repeat(random_bytes(1024), 3 * 1024); // 3 MB, acima de qualquer buffer interno
        $recurso  = fopen('php://temp', 'w+b');
        self::assertIsResource($recurso);
        fwrite($recurso, $conteudo);
        rewind($recurso);

        try {
            self::assertSame(hash('sha256', $conteudo), Sha256DeArquivo::deStream($recurso));
        } finally {
            fclose($recurso);
        }
    }

    #[TestDox('deStream lê da posição atual até o fim e não fecha o recurso — ele é de quem chamou')]
    public function testDeStreamRespeitaAPosicaoENaoFecha(): void
    {
        $recurso = fopen('php://temp', 'w+b');
        self::assertIsResource($recurso);
        fwrite($recurso, 'cabecalho-ignorado|corpo');
        fseek($recurso, strlen('cabecalho-ignorado|'));

        self::assertSame(hash('sha256', 'corpo'), Sha256DeArquivo::deStream($recurso));
        self::assertIsResource($recurso, 'o recurso tem de continuar aberto');
        fclose($recurso);
    }

    #[TestDox('deStream recusa o que não é recurso aberto')]
    public function testDeStreamRecusaNaoRecurso(): void
    {
        $this->expectException(FalhaDeArmazenamento::class);

        Sha256DeArquivo::deStream('não é um recurso');
    }

    #[TestDox('deArquivoLocal: igual a hash_file(), e a origem continua lá — não é consumida')]
    public function testDeArquivoLocalBateComHashFileEPreservaAOrigem(): void
    {
        $caminho = $this->arquivoTemporario("%PDF-1.4\n" . random_bytes(4096));

        self::assertSame(hash_file('sha256', $caminho), Sha256DeArquivo::deArquivoLocal($caminho));
        self::assertFileExists($caminho);
    }

    #[TestDox('deArquivoLocal: arquivo de 0 byte tem o hash do vazio — 114 deles existem no acervo')]
    public function testArquivoVazioTemOHashDoVazio(): void
    {
        $caminho = $this->arquivoTemporario('');

        self::assertSame(self::SHA_DO_VAZIO, Sha256DeArquivo::deArquivoLocal($caminho));
        self::assertSame(self::SHA_DO_VAZIO, Sha256DeArquivo::deTexto(''));
    }

    #[TestDox('deArquivoLocal: caminho que não abre é FalhaDeArmazenamento, não aviso do PHP')]
    public function testDeArquivoLocalInexistenteLancaFalha(): void
    {
        $this->expectException(FalhaDeArmazenamento::class);

        Sha256DeArquivo::deArquivoLocal(sys_get_temp_dir() . '/nao-existe-' . bin2hex(random_bytes(6)));
    }

    /**
     * INV-4: a prova de que o arquivo não é carregado inteiro. PHP 8.2 permite zerar o pico de
     * memória; um `file_get_contents` escondido faria o pico subir pelo tamanho do arquivo.
     */
    #[TestDox('deArquivoLocal não carrega o arquivo inteiro na memória (pico sobe muito menos que o arquivo)')]
    public function testDeArquivoLocalNaoCarregaOArquivoInteiro(): void
    {
        $tamanho = 8 * 1024 * 1024;
        $caminho = sys_get_temp_dir() . '/sha-grande-' . bin2hex(random_bytes(6)) . '.bin';
        $this->arquivos[] = $caminho;

        $recurso = fopen($caminho, 'wb');
        self::assertIsResource($recurso);
        $bloco = random_bytes(64 * 1024);
        for ($escrito = 0; $escrito < $tamanho; $escrito += strlen($bloco)) {
            fwrite($recurso, $bloco);
        }
        fclose($recurso);
        unset($bloco);

        memory_reset_peak_usage();
        $antes = memory_get_usage();
        $hash  = Sha256DeArquivo::deArquivoLocal($caminho);
        $pico  = memory_get_peak_usage() - $antes;

        self::assertSame(hash_file('sha256', $caminho), $hash);
        self::assertLessThan(2 * 1024 * 1024, $pico, sprintf('o pico de memória subiu %d bytes para um arquivo de %d', $pico, $tamanho));
    }

    #[TestDox('deChave: lê pelo verbo abrir() do armazenamento e devolve o hash do que está lá')]
    public function testDeChaveLeDoArmazenamento(): void
    {
        $memoria = new ArmazenamentoEmMemoria();
        $chave   = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, 'abc.pdf');
        $memoria->semear($chave, 'conteúdo armazenado');

        self::assertSame(hash('sha256', 'conteúdo armazenado'), Sha256DeArquivo::deChave($memoria, $chave));
    }

    #[TestDox('deChave: chave sem arquivo propaga ArquivoNaoEncontrado')]
    public function testDeChaveAusenteLancaNaoEncontrado(): void
    {
        $memoria = new ArmazenamentoEmMemoria();
        $chave   = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, 'sumiu.pdf');

        $this->expectException(ArquivoNaoEncontrado::class);

        Sha256DeArquivo::deChave($memoria, $chave);
    }

    #[TestDox('o formato é hex minúsculo de 64 caracteres — o único que PastaDocumento::setSha256 aceita')]
    public function testFormatoHexMinusculoDe64(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Sha256DeArquivo::deTexto('qualquer coisa'));
    }

    private function arquivoTemporario(string $conteudo): string
    {
        $caminho = sys_get_temp_dir() . '/sha-' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);
        $this->arquivos[] = $caminho;

        return $caminho;
    }
}
