<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use App\Shared\Service\CompressaoDeArquivoArmazenado;
use App\Shared\Service\CompressorArquivoInterface;
use App\Shared\Service\ResultadoCompressao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O serviço único da compressão de arquivo persistido (D31): materializar → comprimir → regravar →
 * medir. As regras que ele carrega:
 *
 *  - D26: falha em qualquer etapa preserva o original e vira log; só a impossibilidade de MEDIR sobe;
 *  - D30: o tamanho devolvido é o que o storage mediu depois da última gravação válida — nunca a
 *    conta do compressor (que o dublê daqui falseia de propósito);
 *  - D9: a cópia gravável é liberada em todos os caminhos.
 */
#[CoversClass(CompressaoDeArquivoArmazenado::class)]
final class CompressaoDeArquivoArmazenadoTest extends TestCase
{
    private const ORIGINAL   = 'conteudo original do cliente, bem maior que o comprimido';
    private const COMPRIMIDO = 'menor';

    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private ChaveDeArquivo $chave;

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger        = new LoggerEmMemoria();
        $this->chave         = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'boleto.pdf');
        $this->armazenamento->semear($this->chave, self::ORIGINAL);
    }

    private function servico(CompressorArquivoInterface $compressor): CompressaoDeArquivoArmazenado
    {
        return new CompressaoDeArquivoArmazenado($this->armazenamento, $this->armazenamento, $compressor, $this->logger);
    }

    /**
     * Compressor controlado: reescreve o arquivo que recebe com `$conteudo` (ou não toca nele) e
     * RELATA tamanhos falsos — o serviço não pode confiar neles.
     */
    private function compressor(?string $conteudo, bool $comprimido, ?\Throwable $lanca = null): CompressorArquivoInterface
    {
        return new class ($conteudo, $comprimido, $lanca) implements CompressorArquivoInterface {
            /** @var list<array{string, string}> */
            public array $chamadas = [];

            public function __construct(
                private readonly ?string $conteudo,
                private readonly bool $comprimido,
                private readonly ?\Throwable $lanca,
            ) {
            }

            public function comprimir(string $caminhoCompleto, string $mimeType): ResultadoCompressao
            {
                $this->chamadas[] = [$caminhoCompleto, $mimeType];
                if ($this->lanca !== null) {
                    throw $this->lanca;
                }
                if ($this->conteudo !== null) {
                    file_put_contents($caminhoCompleto, $this->conteudo);
                }

                return new ResultadoCompressao(99_999, 1, $this->comprimido, eraAssinado: true);
            }

            public function pdfEstaAssinado(string $caminhoCompleto): bool
            {
                return false;
            }
        };
    }

    private function assertCopiasLiberadas(): void
    {
        foreach ($this->armazenamento->copiasEntregues as $copia) {
            self::assertTrue($copia->foiLiberado(), 'a cópia gravável não foi liberada (D9)');
        }
    }

    private function assertAvisou(): void
    {
        self::assertNotSame([], $this->logger->doNivel('warning'), 'a falha tinha de ficar no log (D26)');
    }

    #[TestDox('sucesso: regrava na MESMA chave e devolve os tamanhos medidos, não os do compressor (D30)')]
    public function testSucessoRegravaNaMesmaChaveComTamanhoMedido(): void
    {
        $compressor = $this->compressor(self::COMPRIMIDO, true);

        $resultado = $this->servico($compressor)->comprimir($this->chave, 'application/pdf');

        self::assertSame(self::COMPRIMIDO, $this->armazenamento->ler($this->chave));
        self::assertSame($this->chave->comoTexto(), $this->armazenamento->ultimaGravada()->comoTexto(), 'regravou em outra chave (R1)');
        self::assertTrue($resultado->comprimido);
        self::assertSame(\strlen(self::ORIGINAL), $resultado->tamanhoOriginal);
        self::assertSame(\strlen(self::COMPRIMIDO), $resultado->tamanhoFinal);
        self::assertTrue($resultado->eraAssinado);
        self::assertSame('application/pdf', $compressor->chamadas[0][1]);
        self::assertNotSame([], $this->armazenamento->copiasEntregues);
        $this->assertCopiasLiberadas();
    }

    #[TestDox('D30: o tamanho final é o que o storage devolveu ao gravar, mesmo que difira do conteúdo')]
    public function testTamanhoFinalVemDaGravacao(): void
    {
        $this->armazenamento->tamanhoRelatado = 4242;

        $resultado = $this->servico($this->compressor(self::COMPRIMIDO, true))->comprimir($this->chave, 'application/pdf');

        self::assertSame(4242, $resultado->tamanhoFinal);
    }

    #[TestDox('compressor que não comprime: nada é regravado e o tamanho é o medido do original')]
    public function testNaoComprimidoNaoRegrava(): void
    {
        $resultado = $this->servico($this->compressor(null, false))->comprimir($this->chave, 'image/png');

        self::assertSame([], $this->armazenamento->gravadas, 'regravou sem ter comprimido');
        self::assertSame(self::ORIGINAL, $this->armazenamento->ler($this->chave));
        self::assertFalse($resultado->comprimido);
        self::assertSame(\strlen(self::ORIGINAL), $resultado->tamanhoOriginal);
        self::assertSame(\strlen(self::ORIGINAL), $resultado->tamanhoFinal);
        self::assertTrue($resultado->eraAssinado, 'a informação de assinatura do compressor se perdeu');
        $this->assertCopiasLiberadas();
    }

    #[TestDox('D26: o /tmp que não serve mantém o original, não chama o compressor e vira aviso')]
    public function testFalhaDoTemporarioAoMaterializar(): void
    {
        $this->armazenamento->falhaAoCopiar = new FalhaNoTemporario('disco cheio no /tmp');
        $compressor                         = $this->compressor(self::COMPRIMIDO, true);

        $resultado = $this->servico($compressor)->comprimir($this->chave, 'application/pdf');

        self::assertSame([], $compressor->chamadas, 'o compressor rodou sem cópia');
        self::assertSame(self::ORIGINAL, $this->armazenamento->ler($this->chave));
        self::assertFalse($resultado->comprimido);
        self::assertSame(\strlen(self::ORIGINAL), $resultado->tamanhoFinal);
        $this->assertAvisou();
    }

    /**
     * A outra metade da D26, e a que importa: "não consegui LER o arquivo do cliente" é pane. Se
     * virasse aviso, o upload terminaria com sucesso e o registro apontaria para um arquivo que
     * ninguém consegue abrir — o erro só apareceria no download, depois, como 500.
     */
    #[TestDox('D26: ler o persistido é impossível — o erro SOBE, não vira aviso')]
    public function testFalhaDeLeituraAoMaterializarSobe(): void
    {
        $this->armazenamento->falhaAoCopiar = new FalhaDeArmazenamento('Arquivo existe mas não pôde ser aberto');
        $compressor                         = $this->compressor(self::COMPRIMIDO, true);

        try {
            $this->servico($compressor)->comprimir($this->chave, 'application/pdf');
            self::fail('Pane de leitura foi engolida como "não comprimido".');
        } catch (FalhaNoTemporario $e) {
            self::fail('A falha de leitura foi classificada como falha do temporário: ' . $e->getMessage());
        } catch (FalhaDeArmazenamento) {
        }

        self::assertSame([], $compressor->chamadas);
        self::assertSame(self::ORIGINAL, $this->armazenamento->ler($this->chave));
    }

    #[TestDox('D26: falha ao regravar mantém o original, mede de novo, avisa e libera a cópia')]
    public function testFalhaAoRegravar(): void
    {
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('publicação recusada');

        $resultado = $this->servico($this->compressor(self::COMPRIMIDO, true))->comprimir($this->chave, 'application/pdf');

        self::assertSame(self::ORIGINAL, $this->armazenamento->ler($this->chave));
        self::assertFalse($resultado->comprimido);
        self::assertSame(\strlen(self::ORIGINAL), $resultado->tamanhoFinal);
        $this->assertAvisou();
        $this->assertCopiasLiberadas();
    }

    #[TestDox('D26: compressor que lança (fora do contrato) mantém o original, avisa e libera a cópia')]
    public function testCompressorQueLanca(): void
    {
        $resultado = $this->servico($this->compressor(self::COMPRIMIDO, true, new \RuntimeException('GD quebrou')))
            ->comprimir($this->chave, 'image/jpeg');

        self::assertSame(self::ORIGINAL, $this->armazenamento->ler($this->chave));
        self::assertSame([], $this->armazenamento->gravadas);
        self::assertFalse($resultado->comprimido);
        self::assertSame(\strlen(self::ORIGINAL), $resultado->tamanhoFinal);
        $this->assertAvisou();
        $this->assertCopiasLiberadas();
    }

    #[TestDox('D26: sem conseguir medir antes, o erro sobe e nada é tentado')]
    public function testSemMedicaoInicialOErroSobe(): void
    {
        $this->armazenamento->falhaAoMedir = static fn (int $chamada): \Throwable => new FalhaDeArmazenamento('I/O');
        $compressor                        = $this->compressor(self::COMPRIMIDO, true);

        try {
            $this->servico($compressor)->comprimir($this->chave, 'application/pdf');
            self::fail('Medição impossível foi engolida.');
        } catch (FalhaDeArmazenamento) {
        }

        self::assertSame([], $compressor->chamadas);
        self::assertSame([], $this->armazenamento->copiasEntregues);
    }

    #[TestDox('D26: regravação que falha e medição que falha depois — o erro sobe, a cópia sai')]
    public function testRegravacaoEMedicaoFalhandoOErroSobe(): void
    {
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('Gravou mas não conseguiu medir');
        $this->armazenamento->falhaAoMedir  = static fn (int $chamada): ?\Throwable => $chamada > 1 ? new FalhaDeArmazenamento('I/O depois') : null;

        try {
            $this->servico($this->compressor(self::COMPRIMIDO, true))->comprimir($this->chave, 'application/pdf');
            self::fail('Tamanho desconhecido depois de uma regravação incerta foi engolido.');
        } catch (FalhaDeArmazenamento $e) {
            self::assertSame('I/O depois', $e->getMessage());
        }

        $this->assertCopiasLiberadas();
    }

    /**
     * O ramo que a revisão da 6A encontrou: o backend publica com `rename()` e só DEPOIS mede — se a
     * medição dele falhar, a versão comprimida já está no lugar do original. Dizer "não comprimido"
     * aqui esconderia do chamador exatamente o aviso que ele dá ao usuário ("o PDF assinado foi
     * comprimido"), num caso em que a assinatura pode ter ido embora de verdade.
     */
    #[TestDox('regravação que falhou DEPOIS de publicar: o tamanho mudou, então o chamador é avisado')]
    public function testRegravacaoQueFalhouDepoisDePublicar(): void
    {
        $this->armazenamento->falhaDepoisDeGravar = new FalhaDeArmazenamento('publicou e não conseguiu medir');

        $resultado = $this->servico($this->compressor(self::COMPRIMIDO, true))->comprimir($this->chave, 'application/pdf');

        self::assertSame(self::COMPRIMIDO, $this->armazenamento->ler($this->chave), 'o dublê não chegou a publicar');
        self::assertTrue($resultado->comprimido, 'o storage já não tem o original, e o chamador não ficou sabendo');
        self::assertSame(\strlen(self::ORIGINAL), $resultado->tamanhoOriginal);
        self::assertSame(\strlen(self::COMPRIMIDO), $resultado->tamanhoFinal);
        self::assertTrue($resultado->eraAssinado);
        $this->assertAvisou();
        $this->assertCopiasLiberadas();
    }

    #[TestDox('arquivo ausente: ArquivoNaoEncontrado sobe, sem materializar')]
    public function testArquivoAusente(): void
    {
        $ausente = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'nao-existe.pdf');

        $this->expectException(ArquivoNaoEncontrado::class);

        $this->servico($this->compressor(self::COMPRIMIDO, true))->comprimir($ausente, 'application/pdf');
    }

    #[TestDox('R1: comprime o arquivo do escritório da chave, nunca o de outro com o mesmo nome')]
    public function testEscopoDaChave(): void
    {
        $outro = new ChaveDeArquivo(EscopoDeArquivo::deTenant(8), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'boleto.pdf');
        $this->armazenamento->semear($outro, 'do escritorio 8, intacto');

        $this->servico($this->compressor(self::COMPRIMIDO, true))->comprimir($this->chave, 'application/pdf');

        self::assertSame('do escritorio 8, intacto', $this->armazenamento->ler($outro));
        self::assertSame(self::COMPRIMIDO, $this->armazenamento->ler($this->chave));
    }
}
