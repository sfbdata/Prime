<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Contribua\Armazenamento\ArmazenamentoComCota;
use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEspiao;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O decorador da gravação dupla (spec P2 §3.1, §14), com a outbox de verdade no PostgreSQL e o
 * armazenamento em memória por baixo (que materializa o escopo na chave).
 */
#[CoversClass(ArmazenamentoEspelhado::class)]
final class ArmazenamentoEspelhadoTest extends KernelTestCase
{
    private const NOME_PESSOAL = 'a1b2c3d4e5f60718293a4b5c6d7e8f90. RG - Antônio Carlos';

    private Connection $conexao;
    private ArmazenamentoEmMemoria $interno;
    private OutboxDeReplicacao $outbox;
    private LoggerEspiao $logger;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->interno = new ArmazenamentoEmMemoria();
        $this->outbox  = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-15 12:00:00'));
        $this->logger  = new LoggerEspiao();
    }

    #[TestDox('desligado (o padrão): gravar e excluir não deixam linha nenhuma, e o resultado é o do disco')]
    public function testDesligadoEhRepassePuro(): void
    {
        $espelhado = $this->espelhado(PoliticaDeEspelhamento::desligada());
        $antes     = $this->total();

        $armazenado = $espelhado->gravar($this->novo(CategoriaDeArquivo::PASTA_DOCUMENTO), FonteDeConteudo::deTexto('conteúdo'));
        self::assertSame('conteúdo', $this->interno->ler($armazenado->chave));
        $espelhado->excluir($armazenado->chave);

        self::assertSame($antes, $this->total());
        self::assertFalse($this->interno->existe($armazenado->chave));
        self::assertSame([], $this->logger->registros);
    }

    #[TestDox('ligado para a categoria: gravar registra um envio com o SHA e o tamanho medidos no disco')]
    public function testGravarRegistraEnvio(): void
    {
        $espelhado  = $this->espelhado(new PoliticaDeEspelhamento('pasta_documento'));
        $armazenado = $espelhado->gravar($this->novo(CategoriaDeArquivo::PASTA_DOCUMENTO), FonteDeConteudo::deTexto('conteúdo'));

        $linha = $this->ultimaLinha();
        self::assertSame('enviar', $linha['operacao']);
        self::assertSame(hash('sha256', 'conteúdo'), $linha['sha256']);
        self::assertSame(\strlen('conteúdo'), (int) $linha['tamanho_bytes']);
        self::assertSame($armazenado->chave->nome, $linha['nome']);
        self::assertSame(7, (int) $linha['escopo_tenant_id']);
    }

    #[TestDox('regravar uma chave existente (edição de peça, compressão) também registra um envio novo')]
    public function testRegravarRegistraOutroEnvio(): void
    {
        $espelhado  = $this->espelhado(new PoliticaDeEspelhamento('pasta_documento'));
        $armazenado = $espelhado->gravar($this->novo(CategoriaDeArquivo::PASTA_DOCUMENTO), FonteDeConteudo::deTexto('v1'));
        $antes      = $this->total();

        $espelhado->gravar($armazenado->chave, FonteDeConteudo::deTexto('versão 2'));

        self::assertSame($antes + 1, $this->total());
        self::assertSame(hash('sha256', 'versão 2'), $this->ultimaLinha()['sha256']);
    }

    #[TestDox('categoria não ligada não registra nada, mesmo com outra ligada')]
    public function testSoACategoriaLigada(): void
    {
        $espelhado = $this->espelhado(new PoliticaDeEspelhamento('foto_perfil'));
        $antes     = $this->total();

        $espelhado->gravar($this->novo(CategoriaDeArquivo::PASTA_DOCUMENTO), FonteDeConteudo::deTexto('x'));

        self::assertSame($antes, $this->total());
    }

    #[TestDox('excluir em categoria ligada: apaga no disco e registra o EVENTO — nada mais')]
    public function testExcluirRegistraEvento(): void
    {
        $espelhado  = $this->espelhado(new PoliticaDeEspelhamento('pasta_documento'));
        $armazenado = $espelhado->gravar($this->novo(CategoriaDeArquivo::PASTA_DOCUMENTO), FonteDeConteudo::deTexto('x'));

        $espelhado->excluir($armazenado->chave);

        self::assertFalse($this->interno->existe($armazenado->chave));
        self::assertSame('exclusao_registrada', $this->ultimaLinha()['operacao']);
        self::assertSame('registrado', $this->ultimaLinha()['estado']);
    }

    #[TestDox('purga por prefixo em categoria ligada: um evento por arquivo que saiu do disco')]
    public function testPurgaRegistraCadaExclusao(): void
    {
        $espelhado = $this->espelhado(new PoliticaDeEspelhamento('cobranca_documento'));
        $escopo    = EscopoDeArquivo::deTenant(7);
        $espelhado->gravar(new NovoArquivo($escopo, CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'pdf'), FonteDeConteudo::deTexto('1'));
        $espelhado->gravar(new NovoArquivo($escopo, CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'pdf'), FonteDeConteudo::deTexto('2'));
        $antes = (int) $this->conexao->fetchOne("SELECT count(*) FROM armazenamento_replicacao WHERE operacao = 'exclusao_registrada'");

        $espelhado->excluirPrefixo($escopo, CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);

        self::assertSame($antes + 2, (int) $this->conexao->fetchOne("SELECT count(*) FROM armazenamento_replicacao WHERE operacao = 'exclusao_registrada'"));
    }

    #[TestDox('leitura é repasse puro: abrir, ler, existe, metadados, paraLeitura e listar devolvem o que o disco devolve')]
    public function testLeituraEhRepasse(): void
    {
        $espelhado  = $this->espelhado(new PoliticaDeEspelhamento('pasta_documento,cobranca_documento'));
        $armazenado = $espelhado->gravar($this->novo(CategoriaDeArquivo::PASTA_DOCUMENTO), FonteDeConteudo::deTexto('abc'));
        $antes      = $this->total();

        self::assertSame('abc', $espelhado->ler($armazenado->chave));
        self::assertSame('abc', stream_get_contents($espelhado->abrir($armazenado->chave)));
        self::assertTrue($espelhado->existe($armazenado->chave));
        self::assertSame(3, $espelhado->metadados($armazenado->chave)?->tamanhoBytes);
        self::assertSame('abc', file_get_contents($espelhado->paraLeitura($armazenado->chave)->caminho()));
        iterator_to_array($espelhado->listar(EscopoDeArquivo::deTenant(7), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO));

        self::assertSame($antes, $this->total(), 'ler não registra nada');
    }

    #[TestDox('o decorador recebe o PROVEDOR do espelho, nunca o espelho montado: a requisição que lê do disco não monta cliente R2')]
    public function testDecoradorNaoMontaOEspelhoNoConstrutor(): void
    {
        foreach ((new \ReflectionClass(ArmazenamentoEspelhado::class))->getConstructor()?->getParameters() ?? [] as $parametro) {
            self::assertNotSame(EspelhoRemoto::class, (string) $parametro->getType());
        }
    }

    #[TestDox('outbox quebrada: o arquivo é gravado, a operação devolve normal e o log não leva o nome do arquivo')]
    public function testOutboxQuebradaNaoQuebraOUsuario(): void
    {
        $semTabela = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $espelhado = new ArmazenamentoEspelhado($this->interno, new OutboxDeReplicacao($semTabela, new MockClock()), new PoliticaDeEspelhamento('pasta_documento'), $this->logger, ProvedorDoEspelhoFixo::naoConfigurado(), new CacheDeLeituraDoEspelho(sys_get_temp_dir() . '/jusprime-cache-nao-usado', 0));
        $chave     = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, self::NOME_PESSOAL);

        $armazenado = $espelhado->gravar($chave, FonteDeConteudo::deTexto('x'));
        $espelhado->excluir($chave);

        self::assertSame(self::NOME_PESSOAL, $armazenado->chave->nome);
        self::assertCount(2, $this->logger->registros, 'um erro por evento não registrado');
        foreach ($this->logger->registros as $registro) {
            self::assertSame('error', $registro['nivel']);
            self::assertStringNotContainsString('Antônio', $registro['mensagem']);
            self::assertStringNotContainsString('core/t/', $registro['mensagem']);
            self::assertStringContainsString('pasta_documento', $registro['mensagem']);
        }
    }

    #[TestDox('no container, a cadeia é cota, espelho, disco; as outras duas interfaces são o espelho; e, sem configuração, ele está desligado')]
    public function testContainerEntregaODecoradorDesligado(): void
    {
        $container = static::getContainer();

        // A cota recusa antes de gravar; o espelho registra depois da gravação local (decisão de 23/10).
        self::assertSame(
            [ArmazenamentoComCota::class, ArmazenamentoEspelhado::class, ArmazenamentoLocal::class],
            $this->cadeia($container->get(ArmazenamentoDeArquivos::class)),
        );
        self::assertInstanceOf(ArmazenamentoEspelhado::class, $container->get(MaterializadorDeArquivo::class));
        self::assertInstanceOf(ArmazenamentoEspelhado::class, $container->get(ArmazenamentoComPrefixo::class));
        self::assertFalse($container->get(PoliticaDeEspelhamento::class)->estaLigada());
    }

    #[TestDox('pelo armazenamento do container, desligado: gravar e excluir chegam ao disco e não deixam linha na outbox')]
    public function testContainerDesligadoNaoRegistraNada(): void
    {
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $antes         = $this->total();

        $armazenado = $armazenamento->gravar(
            new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::FOTO_PERFIL, 'jpg'),
            FonteDeConteudo::deTexto('foto'),
        );
        self::assertSame('foto', $armazenamento->ler($armazenado->chave), 'gravou no disco de verdade');
        $armazenamento->excluir($armazenado->chave);

        self::assertFalse($armazenamento->existe($armazenado->chave));
        self::assertSame($antes, $this->total(), 'desligado, o espelho não registra nada');
    }

    /**
     * As classes da cadeia de decoradores, de fora para dentro, seguindo a propriedade `interno`.
     *
     * @return list<class-string>
     */
    private function cadeia(object $armazenamento): array
    {
        $classes = [];
        for ($atual = $armazenamento; \is_object($atual);) {
            $classes[] = $atual::class;
            $reflexao  = new \ReflectionObject($atual);
            $atual     = $reflexao->hasProperty('interno') ? $reflexao->getProperty('interno')->getValue($atual) : null;
        }

        return $classes;
    }

    private function espelhado(PoliticaDeEspelhamento $politica): ArmazenamentoEspelhado
    {
        return new ArmazenamentoEspelhado($this->interno, $this->outbox, $politica, $this->logger, ProvedorDoEspelhoFixo::naoConfigurado(), new CacheDeLeituraDoEspelho(sys_get_temp_dir() . '/jusprime-cache-nao-usado', 0));
    }

    private function novo(CategoriaDeArquivo $categoria): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::deTenant(7), $categoria, 'pdf');
    }

    private function total(): int
    {
        return (int) $this->conexao->fetchOne('SELECT count(*) FROM armazenamento_replicacao');
    }

    /** @return array<string, mixed> */
    private function ultimaLinha(): array
    {
        return $this->conexao->fetchAssociative('SELECT * FROM armazenamento_replicacao ORDER BY id DESC LIMIT 1') ?: self::fail('sem linha');
    }
}
