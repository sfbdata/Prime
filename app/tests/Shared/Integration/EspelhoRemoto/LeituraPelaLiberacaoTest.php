<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoIndisponivelNoMomento;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao;
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
 * A leitura de recuperação com a linha aberta de liberação como prova (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md` §4): quando a retenção da outbox já levou o envio
 * `verificado`, a linha de liberação prova o conteúdo, e o download é conferido pelo SHA dela.
 */
#[CoversClass(ArmazenamentoEspelhado::class)]
#[CoversClass(RegistroDeLiberacao::class)]
final class LeituraPelaLiberacaoTest extends KernelTestCase
{
    private const CONTEUDO = "%PDF-1.4\nliberado do disco, só no R2\n";

    private Connection $conexao;
    private ArmazenamentoEmMemoria $disco;
    private EspelhoRemotoEmMemoria $espelho;
    private OutboxDeReplicacao $outbox;
    private RegistroDeLiberacao $liberacoes;
    private string $diretorioDoCache;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao          = static::getContainer()->get(Connection::class);
        $relogio                = new MockClock('2026-11-03 12:00:00', 'UTC');
        $this->disco            = new ArmazenamentoEmMemoria();
        $this->espelho          = new EspelhoRemotoEmMemoria();
        $this->outbox           = new OutboxDeReplicacao($this->conexao, $relogio);
        $this->liberacoes       = new RegistroDeLiberacao($this->conexao, $relogio);
        $this->diretorioDoCache = sys_get_temp_dir() . '/jusprime-teste-cache-lib-' . bin2hex(random_bytes(6));
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

    #[TestDox('sem linha na outbox e com liberação aberta: hidrata do R2 conferindo o SHA da linha; existe() e metadados() respondem pela linha')]
    public function testAceitaALinhaDeLiberacao(): void
    {
        $chave = $this->liberada('liberado.pdf', self::CONTEUDO);
        $espelhado = $this->espelhado();

        self::assertTrue($espelhado->existe($chave));
        $meta = $espelhado->metadados($chave);
        self::assertNotNull($meta);
        self::assertSame(\strlen(self::CONTEUDO), $meta->tamanhoBytes);
        self::assertSame(hash('sha256', self::CONTEUDO), $meta->checksum);
        self::assertSame(0, $this->espelho->downloads, 'existe() e metadados() não vão ao R2');

        self::assertSame(self::CONTEUDO, $espelhado->ler($chave));
        self::assertSame(self::CONTEUDO, file_get_contents($espelhado->paraLeitura($chave)->caminho()));
        self::assertSame(1, $this->espelho->downloads);
    }

    #[TestDox('o R2 devolve conteúdo com SHA diferente do da linha: indisponível (503), nada no cache')]
    public function testRecusaShaDivergente(): void
    {
        $chave = $this->liberada('corrompido.pdf', self::CONTEUDO);
        $this->espelho->semear($chave, "%PDF-1.4\nOUTRO conteúdo de mesmo tamanho\n", hash('sha256', self::CONTEUDO));

        try {
            $this->espelhado()->paraLeitura($chave);
            self::fail('devia falhar');
        } catch (ArquivoIndisponivelNoMomento) {
            $this->addToAssertionCount(1);
        }

        self::assertSame([], $this->arquivosNoCache());
    }

    #[TestDox('sem outbox e sem linha de liberação (ou com a linha fechada): a mesma recusa de hoje, sem chamar o R2')]
    public function testSemProvaNenhuma(): void
    {
        $nunca = $this->chave('nunca.pdf');
        $this->espelho->semear($nunca, self::CONTEUDO);
        $this->assertComportamentoDeHoje($nunca);

        $fechada = $this->liberada('fechada.pdf', self::CONTEUDO);
        $this->liberacoes->fechar(LayoutDeChaveRemota::chaveDe($fechada));
        $this->assertComportamentoDeHoje($fechada);
    }

    #[TestDox('linha mais nova na outbox (exclusão ou reenvio pendente) vale mais que a liberação: sem prova')]
    public function testOutboxMaisNovaVenceALiberacao(): void
    {
        $excluida = $this->liberada('excluida.pdf', self::CONTEUDO);
        $this->outbox->registrarExclusao($excluida);
        $this->assertComportamentoDeHoje($excluida);

        $regravada = $this->liberada('regravada.pdf', self::CONTEUDO);
        $this->outbox->registrarEnvio($regravada, hash('sha256', 'nova'), 4, 'application/pdf');
        $this->assertComportamentoDeHoje($regravada);
    }

    #[TestDox('arquivo liberado e excluído: a exclusão fecha a linha, e depois que a retenção apaga o excluir verificado a leitura é a recusa de sempre (inexistente, não 503)')]
    public function testExclusaoFechaALinhaEARetencaoNaoRessuscita(): void
    {
        $chave = $this->liberada('excluido.pdf', self::CONTEUDO);

        $this->espelhado()->excluir($chave);
        self::assertNull($this->liberacoes->abertaDe(LayoutDeChaveRemota::chaveDe($chave)), 'a exclusão fechou a linha');

        // O worker apagou no R2 e a retenção de 90 dias levou a linha `excluir`/`verificado`.
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE chave_remota = ?", [LayoutDeChaveRemota::chaveDe($chave)]);
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao WHERE chave_remota = ?', [LayoutDeChaveRemota::chaveDe($chave)]);

        $this->assertComportamentoDeHoje($chave);
    }

    #[TestDox('purga por prefixo: fecha as linhas abertas do escritório na categoria (o liberado não aparece na listagem do disco), e só as dele')]
    public function testPurgaPorPrefixoFechaAsLinhas(): void
    {
        $doEscritorio = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'cobranca.pdf');
        $deOutro      = new ChaveDeArquivo(EscopoDeArquivo::deTenant(8), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'cobranca.pdf');
        $outraCat     = $this->chave('pasta.pdf');
        foreach ([$doEscritorio, $deOutro, $outraCat] as $chave) {
            $this->liberacoes->registrar('lote-teste', $chave, hash('sha256', self::CONTEUDO), \strlen(self::CONTEUDO));
        }

        $this->espelhado(new PoliticaDeEspelhamento('cobranca_documento,pasta_documento'))
            ->excluirPrefixo(EscopoDeArquivo::deTenant(7), CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO);

        self::assertNull($this->liberacoes->abertaDe(LayoutDeChaveRemota::chaveDe($doEscritorio)));
        self::assertNotNull($this->liberacoes->abertaDe(LayoutDeChaveRemota::chaveDe($deOutro)), 'outro escritório');
        self::assertNotNull($this->liberacoes->abertaDe(LayoutDeChaveRemota::chaveDe($outraCat)), 'outra categoria');
    }

    #[TestDox('com o envio verificado na outbox, a prova é a da outbox (inclusive o MIME), mesmo com a linha aberta')]
    public function testOutboxVerificadaContinuaValendo(): void
    {
        $chave = $this->liberada('com-outbox.pdf', self::CONTEUDO);
        $id    = $this->outbox->registrarEnvio($chave, hash('sha256', self::CONTEUDO), \strlen(self::CONTEUDO), 'application/pdf');
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE id = ?", [$id]);

        self::assertSame('application/pdf', $this->espelhado()->metadados($chave)?->mimeType);
    }

    #[TestDox('a liberação é da chave do escritório: a mesma chave em outro escritório não herda a prova')]
    public function testOutroEscritorio(): void
    {
        $this->liberada('mesmo.pdf', self::CONTEUDO);
        $deOutro = new ChaveDeArquivo(EscopoDeArquivo::deTenant(8), CategoriaDeArquivo::PASTA_DOCUMENTO, 'mesmo.pdf');
        $this->espelho->semear($deOutro, self::CONTEUDO);

        $this->assertComportamentoDeHoje($deOutro);
    }

    #[TestDox('categoria desligada: a linha de liberação não liga a leitura pelo R2')]
    public function testCategoriaDesligada(): void
    {
        $chave = $this->liberada('desligada.pdf', self::CONTEUDO);

        $this->assertComportamentoDeHoje($chave, new PoliticaDeEspelhamento('foto_perfil'));
    }

    private function assertComportamentoDeHoje(ChaveDeArquivo $chave, ?PoliticaDeEspelhamento $politica = null): void
    {
        $espelhado = $this->espelhado($politica);
        self::assertFalse($espelhado->existe($chave));
        self::assertNull($espelhado->metadados($chave));
        foreach ([fn () => $espelhado->paraLeitura($chave), fn () => $espelhado->ler($chave)] as $i => $leitura) {
            try {
                $leitura();
                self::fail('caso ' . $i . ' devia ser ArquivoNaoEncontrado');
            } catch (ArquivoNaoEncontrado) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->espelho->downloads, 'sem prova, o R2 não é chamado');
    }

    private function espelhado(?PoliticaDeEspelhamento $politica = null): ArmazenamentoEspelhado
    {
        return new ArmazenamentoEspelhado(
            $this->disco,
            $this->outbox,
            $politica ?? new PoliticaDeEspelhamento('pasta_documento'),
            new LoggerEspiao(),
            ProvedorDoEspelhoFixo::com($this->espelho),
            new CacheDeLeituraDoEspelho($this->diretorioDoCache, 10_000_000),
            $this->liberacoes,
        );
    }

    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
    }

    /** Uma chave fora do disco, no R2, com linha aberta de liberação e sem nenhuma linha na outbox. */
    private function liberada(string $nome, string $conteudo): ChaveDeArquivo
    {
        $chave = $this->chave($nome);
        $this->liberacoes->registrar('lote-teste', $chave, hash('sha256', $conteudo), \strlen($conteudo));
        $this->espelho->semear($chave, $conteudo);

        return $chave;
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
