<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoIndisponivelNoMomento;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEspiao;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A leitura de recuperação do `ArmazenamentoEspelhado` (spec `leitura-pelo-r2-e-liberacao-de-disco.md`,
 * D1 a D3, §3.3): disco em memória, espelho em memória, e a outbox de verdade no PostgreSQL (a classe é
 * concreta e a consulta é SQL; um dublê dela não provaria a regra "a linha mais nova da chave").
 */
#[CoversClass(ArmazenamentoEspelhado::class)]
#[CoversClass(OutboxDeReplicacao::class)]
final class LeituraDeRecuperacaoTest extends KernelTestCase
{
    private const CONTEUDO = "%PDF-1.4\nconteúdo que só o R2 tem\n";

    private Connection $conexao;
    private ArmazenamentoEmMemoria $disco;
    private EspelhoRemotoEmMemoria $espelho;
    private ProvedorDoEspelhoFixo $provedor;
    private OutboxDeReplicacao $outbox;
    private LoggerEspiao $logger;
    private string $diretorioDoCache;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao          = static::getContainer()->get(Connection::class);
        $this->disco            = new ArmazenamentoEmMemoria();
        $this->espelho          = new EspelhoRemotoEmMemoria();
        $this->provedor         = ProvedorDoEspelhoFixo::com($this->espelho);
        $this->outbox           = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-28 12:00:00'));
        $this->logger           = new LoggerEspiao();
        $this->diretorioDoCache = sys_get_temp_dir() . '/jusprime-teste-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diretorioDoCache . '/{,.}*', \GLOB_BRACE) ?: [] as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        if (is_dir($this->diretorioDoCache)) {
            rmdir($this->diretorioDoCache);
        }

        parent::tearDown();
    }

    #[TestDox('disco tem o arquivo: lê do disco, e o espelho nem é montado (D1)')]
    public function testDiscoTemNaoChamaOEspelho(): void
    {
        $chave = $this->chave('no-disco.pdf');
        $this->disco->gravar($chave, FonteDeConteudo::deTexto('do disco'));
        $this->verificado($chave, 'do disco');
        $espelhado = $this->espelhado();

        self::assertSame('do disco', $espelhado->ler($chave));
        self::assertSame('do disco', stream_get_contents($espelhado->abrir($chave)));
        self::assertTrue($espelhado->existe($chave));
        self::assertNull($espelhado->metadados($chave)?->checksum, 'metadado do disco, não da outbox');
        self::assertSame('do disco', file_get_contents($espelhado->paraLeitura($chave)->caminho()));
        $copia = $espelhado->copiaGravavel($chave);
        self::assertSame('do disco', file_get_contents($copia->caminho()));
        $copia->liberar();

        self::assertSame(0, $this->provedor->montagens);
        self::assertSame(0, $this->espelho->downloads);
        self::assertFalse(is_dir($this->diretorioDoCache), 'nada no cache');
    }

    #[TestDox('disco não tem e há envio verificado: hidrata UMA vez, e as leituras seguintes saem do cache')]
    public function testHidrataUmaVez(): void
    {
        $chave = $this->soNoEspelho('so-no-r2.pdf');
        $espelhado = $this->espelhado();

        $emprestado = $espelhado->paraLeitura($chave);
        self::assertSame(self::CONTEUDO, file_get_contents($emprestado->caminho()));
        self::assertSame($this->diretorioDoCache . '/' . hash('sha256', self::CONTEUDO), $emprestado->caminho(), 'o nome no cache é o SHA');

        self::assertSame(self::CONTEUDO, $espelhado->ler($chave));
        self::assertSame(self::CONTEUDO, stream_get_contents($espelhado->abrir($chave)));
        self::assertSame(self::CONTEUDO, file_get_contents($espelhado->paraLeitura($chave)->caminho()));

        self::assertSame(1, $this->espelho->downloads, 'a segunda leitura em diante não vai ao R2');
        self::assertSame(1, $this->provedor->montagens);
    }

    #[TestDox('existe() e metadados() sem o disco respondem pela outbox (tamanho, SHA, MIME), sem ir ao R2')]
    public function testExisteEMetadadosPelaOutbox(): void
    {
        $chave = $this->soNoEspelho('meta.pdf');
        $espelhado = $this->espelhado();

        self::assertTrue($espelhado->existe($chave));
        $meta = $espelhado->metadados($chave);
        self::assertNotNull($meta);
        self::assertSame(\strlen(self::CONTEUDO), $meta->tamanhoBytes);
        self::assertSame(hash('sha256', self::CONTEUDO), $meta->checksum);
        self::assertSame('application/pdf', $meta->mimeType);

        self::assertSame(0, $this->espelho->downloads);
        self::assertSame(0, $this->provedor->montagens);
    }

    #[TestDox('copiaGravavel() sem o disco: hidrata e entrega uma cópia possuída, fora do cache; liberar não apaga o cache')]
    public function testCopiaGravavelHidratada(): void
    {
        $chave = $this->soNoEspelho('copia.pdf');
        $espelhado = $this->espelhado();

        $copia = $espelhado->copiaGravavel($chave);
        self::assertSame(self::CONTEUDO, file_get_contents($copia->caminho()));
        self::assertStringNotContainsString($this->diretorioDoCache, $copia->caminho());
        $copia->liberar();

        self::assertFileExists($this->diretorioDoCache . '/' . hash('sha256', self::CONTEUDO));
    }

    #[TestDox('SHA errado no R2: falha como indisponível (503), e nenhum arquivo fica no cache')]
    public function testShaErradoNaoDeixaArquivo(): void
    {
        $chave = $this->chave('corrompido.pdf');
        $this->verificado($chave, self::CONTEUDO);
        $this->espelho->semear($chave, "%PDF-1.4\nOUTRO conteúdo de igual tamanh\n", hash('sha256', self::CONTEUDO));
        $espelhado = $this->espelhado();

        try {
            $espelhado->paraLeitura($chave);
            self::fail('devia falhar');
        } catch (ArquivoIndisponivelNoMomento $e) {
            self::assertStringNotContainsString('corrompido', $e->getMessage(), 'a mensagem não leva o nome do arquivo');
        }

        self::assertSame([], $this->arquivosNoCache());
        self::assertCount(1, $this->logger->registros);
        self::assertStringNotContainsString('corrompido', $this->logger->registros[0]['mensagem']);
    }

    #[TestDox('R2 fora do ar: indisponível (nunca ArquivoNaoEncontrado), sem arquivo no cache')]
    public function testForaDoAr(): void
    {
        $chave = $this->soNoEspelho('fora.pdf');
        $this->espelho->foraDoAr = true;
        $espelhado = $this->espelhado();

        foreach ([
            fn () => $espelhado->paraLeitura($chave),
            fn () => $espelhado->ler($chave),
            fn () => $espelhado->abrir($chave),
            fn () => $espelhado->copiaGravavel($chave),
        ] as $i => $leitura) {
            try {
                $leitura();
                self::fail('caso ' . $i . ' devia falhar');
            } catch (ArquivoIndisponivelNoMomento) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertTrue($espelhado->existe($chave), 'existe() responde pela outbox: o arquivo existe');
        self::assertSame([], $this->arquivosNoCache());
    }

    #[TestDox('tarefa_anexo fica fora da leitura de recuperação até a prova R1 (spec §3.3): ligada, com verificado e no R2, comportamento de hoje')]
    public function testTarefaAnexoFicaDeFora(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::TAREFA_ANEXO, 'arquivo_6abab5669fac90.42347212.pdf');
        $this->verificado($chave, self::CONTEUDO);
        $this->espelho->semear($chave, self::CONTEUDO);

        $this->assertComportamentoDeHoje($this->espelhado(new PoliticaDeEspelhamento('tarefa_anexo,pasta_documento')), $chave);
    }

    #[TestDox('a indisponibilidade não leva o nome do arquivo: nem na mensagem, nem numa causa encadeada (só a classe dela)')]
    public function testIndisponivelSemNomeDoArquivo(): void
    {
        $chave = $this->soNoEspelho('Contrato de Antônio Carlos.pdf');
        $this->espelho->foraDoAr = true;

        try {
            $this->espelhado()->paraLeitura($chave);
            self::fail('devia falhar');
        } catch (ArquivoIndisponivelNoMomento $e) {
            self::assertNull($e->getPrevious(), 'a causa traz a chave remota, com o nome: não é encadeada');
            self::assertStringNotContainsString('Antônio', $e->getMessage());
            self::assertStringNotContainsString('core/t/', $e->getMessage());
            self::assertSame(\App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto::class, $e->classeDaCausa);
            self::assertStringContainsString('causa=App\\Shared\\EspelhoRemoto\\FalhaNoEspelhoRemoto', $e->getMessage());
        }
    }

    #[TestDox('categoria desligada: comportamento de hoje (ausente), mesmo com envio verificado e objeto no R2')]
    public function testCategoriaDesligada(): void
    {
        $chave = $this->soNoEspelho('desligada.pdf');

        $this->assertComportamentoDeHoje($this->espelhado(new PoliticaDeEspelhamento('foto_perfil')), $chave);
    }

    #[TestDox('espelho sem configuração: comportamento de hoje')]
    public function testEspelhoNaoConfigurado(): void
    {
        $chave          = $this->soNoEspelho('sem-config.pdf');
        $this->provedor = ProvedorDoEspelhoFixo::naoConfigurado();

        $this->assertComportamentoDeHoje($this->espelhado(), $chave);
    }

    #[TestDox('sem envio verificado (nunca enviado, pendente, falhou): comportamento de hoje')]
    public function testSemVerificado(): void
    {
        $nunca = $this->chave('nunca.pdf');
        $this->espelho->semear($nunca, self::CONTEUDO);
        $this->assertComportamentoDeHoje($this->espelhado(), $nunca);

        foreach (['pendente', 'falhou', 'descartado'] as $estado) {
            $chave = $this->chave($estado . '.pdf');
            $this->espelho->semear($chave, self::CONTEUDO);
            $id = $this->outbox->registrarEnvio($chave, hash('sha256', self::CONTEUDO), \strlen(self::CONTEUDO), 'application/pdf');
            $this->conexao->executeStatement('UPDATE armazenamento_replicacao SET estado = ? WHERE id = ?', [$estado, $id]);

            $this->assertComportamentoDeHoje($this->espelhado(), $chave);
        }
    }

    #[TestDox('o verificado só vale se for a linha MAIS NOVA da chave: uma exclusão ou um reenvio pendente depois dele tiram a prova')]
    public function testVerificadoAntigoNaoProva(): void
    {
        $excluida = $this->soNoEspelho('excluida.pdf');
        $this->outbox->registrarExclusao($excluida);
        $this->assertComportamentoDeHoje($this->espelhado(), $excluida);

        $regravada = $this->soNoEspelho('regravada.pdf');
        $this->outbox->registrarEnvio($regravada, hash('sha256', 'versão nova'), \strlen('versão nova'), 'application/pdf');
        $this->assertComportamentoDeHoje($this->espelhado(), $regravada);
    }

    #[TestDox('a prova é da chave do escritório: o verificado do escritório 7 não serve à mesma chave no escritório 8')]
    public function testOutroEscritorioNaoHerdaAProva(): void
    {
        $this->soNoEspelho('mesmo-nome.pdf');
        $deOutro = new ChaveDeArquivo(EscopoDeArquivo::deTenant(8), CategoriaDeArquivo::PASTA_DOCUMENTO, 'mesmo-nome.pdf');

        $this->assertComportamentoDeHoje($this->espelhado(), $deOutro);
    }

    #[TestDox('listar não muda: lista só o disco, sem os arquivos que estão só no espelho')]
    public function testListarNaoMuda(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'so-no-r2.pdf');
        $this->verificado($chave, self::CONTEUDO);
        $this->espelho->semear($chave, self::CONTEUDO);

        $listados = iterator_to_array($this->espelhado(new PoliticaDeEspelhamento('cobranca_documento'))->listar(EscopoDeArquivo::deTenant(7), \App\Shared\Armazenamento\CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO), false);

        self::assertSame([], $listados);
    }

    #[TestDox('ESPELHO_CACHE_MAX_BYTES tem padrão no próprio container (512 MB): sem a variável no ambiente, o teto não fica sem valor')]
    public function testTetoTemPadraoNoContainer(): void
    {
        $container = static::getContainer();

        // É este o valor que o `default:` do %env()% usa quando a variável não existe no ambiente.
        self::assertSame(536_870_912, $container->getParameter('espelho_cache_max_bytes_padrao'));

        $cache = $container->get(ArmazenamentoEspelhado::class);
        $teto  = (new \ReflectionProperty(CacheDeLeituraDoEspelho::class, 'maximoDeBytes'))->getValue(
            (new \ReflectionProperty(ArmazenamentoEspelhado::class, 'cache'))->getValue($cache),
        );
        self::assertSame(536_870_912, $teto);
    }

    private function assertComportamentoDeHoje(ArmazenamentoEspelhado $espelhado, ChaveDeArquivo $chave): void
    {
        self::assertFalse($espelhado->existe($chave));
        self::assertNull($espelhado->metadados($chave));
        foreach ([
            fn () => $espelhado->paraLeitura($chave),
            fn () => $espelhado->ler($chave),
            fn () => $espelhado->abrir($chave),
            fn () => $espelhado->copiaGravavel($chave),
        ] as $i => $leitura) {
            try {
                $leitura();
                self::fail('caso ' . $i . ' devia ser ArquivoNaoEncontrado');
            } catch (ArquivoNaoEncontrado) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->espelho->downloads, 'sem prova, o R2 não é chamado');
        self::assertSame([], $this->arquivosNoCache());
    }

    private function espelhado(?PoliticaDeEspelhamento $politica = null): ArmazenamentoEspelhado
    {
        return new ArmazenamentoEspelhado(
            $this->disco,
            $this->outbox,
            $politica ?? new PoliticaDeEspelhamento('pasta_documento,cobranca_documento'),
            $this->logger,
            $this->provedor,
            new CacheDeLeituraDoEspelho($this->diretorioDoCache, 10_000_000),
        );
    }

    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
    }

    /** Uma chave que não está no disco, está no R2 e tem o envio verificado na outbox. */
    private function soNoEspelho(string $nome): ChaveDeArquivo
    {
        $chave = $this->chave($nome);
        $this->verificado($chave, self::CONTEUDO);
        $this->espelho->semear($chave, self::CONTEUDO, null, 'application/pdf');

        return $chave;
    }

    private function verificado(ChaveDeArquivo $chave, string $conteudo): void
    {
        $id = $this->outbox->registrarEnvio($chave, hash('sha256', $conteudo), \strlen($conteudo), 'application/pdf');
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE id = ?", [$id]);
        self::assertSame('verificado', $this->outbox->ultimoEstadoDe(LayoutDeChaveRemota::chaveDe($chave)));
    }

    /** @return list<string> */
    private function arquivosNoCache(): array
    {
        if (!is_dir($this->diretorioDoCache)) {
            return [];
        }

        return array_values(array_filter(
            array_diff(scandir($this->diretorioDoCache) ?: [], ['.', '..']),
            static fn (string $nome): bool => !str_starts_with($nome, '.trava-'),
        ));
    }
}
