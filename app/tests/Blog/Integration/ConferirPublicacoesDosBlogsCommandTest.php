<?php

declare(strict_types=1);

namespace App\Tests\Blog\Integration;

use App\Blog\Command\ConferirPublicacoesDosBlogsCommand;
use App\Blog\Command\GerarBlogsDeNovoCommand;
use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDaPublicacao;
use App\Blog\Message\MontarPacoteDosBlogs;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Blog\Service\PastaDosPedidos;
use App\Blog\UseCase\ConferirPublicacoesDosBlogsUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * `blog:conferir-publicacoes` contra o banco de verdade (DAMA desfaz cada teste), com a pasta dos pedidos numa pasta
 * temporária do processo, escrita como o builder escreve (spec painel-artigos-blogs, seção 6.1). E o
 * `blog:gerar-de-novo`, que pede uma geração com motivo manual.
 */
#[CoversClass(ConferirPublicacoesDosBlogsCommand::class)]
#[CoversClass(ConferirPublicacoesDosBlogsUseCase::class)]
#[CoversClass(GerarBlogsDeNovoCommand::class)]
final class ConferirPublicacoesDosBlogsCommandTest extends KernelTestCase
{
    private string $raiz;
    private MockClock $relogio;
    private PublicacaoDosBlogsRepository $publicacoes;
    private CommandTester $comando;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->raiz = sys_get_temp_dir() . '/conferir-blogs-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->raiz, 0o775, true);
        $this->relogio = new MockClock('2026-11-03 15:00:00', 'America/Sao_Paulo');
        $this->publicacoes = static::getContainer()->get(PublicacaoDosBlogsRepository::class);
        $conferir = new ConferirPublicacoesDosBlogsUseCase(
            $this->publicacoes,
            new PastaDosPedidos($this->raiz),
            static::getContainer()->get(EscopoDeAuditoriaDePlataforma::class),
            $this->relogio,
        );
        $this->comando = new CommandTester(new ConferirPublicacoesDosBlogsCommand($conferir));
    }

    protected function tearDown(): void
    {
        $this->apagar($this->raiz);
        parent::tearDown();
    }

    #[TestDox('resultado.json no_ar: grava situação, commit, log, início e fim, e apaga a pasta do pedido')]
    public function testNoAr(): void
    {
        $p = $this->pedido('2026-11-03 14:55');
        $this->builderEscreve($p, 'iniciado', '2026-11-03T14:56:00-03:00');
        $this->builderEscreve($p, 'resultado.json', (string) json_encode([
            'versao' => 1, 'id' => $p->getId(), 'situacao' => 'no_ar', 'iniciadaEm' => '2026-11-03T14:56:00-03:00',
            'terminadaEm' => '2026-11-03T14:58:30-03:00', 'commit' => 'a1b2c3d', 'log' => "npm ci\nnpm run build\n",
        ]));

        self::assertSame(Command::SUCCESS, $this->comando->execute([]), $this->comando->getDisplay());

        $lida = $this->reler($p);
        self::assertSame(SituacaoDaPublicacao::NoAr, $lida->getSituacao());
        self::assertSame('a1b2c3d', $lida->getCommitDosSites());
        self::assertSame("npm ci\nnpm run build", $lida->getLog());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 14:56:00'), $lida->getIniciadaEm());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 14:58:30'), $lida->getTerminadaEm());
        self::assertDirectoryDoesNotExist($this->raiz . '/' . $p->getId());
        self::assertStringContainsString('No ar: 1.', $this->comando->getDisplay());
    }

    #[TestDox('resultado.json falhou: o pedido falha e o comando sai com erro (o aviso de falha do agendador chega ao Master)')]
    public function testFalhou(): void
    {
        $p = $this->pedido('2026-11-03 14:55');
        $this->builderEscreve($p, 'resultado.json', (string) json_encode(['versao' => 1, 'situacao' => 'falhou', 'commit' => 'a1b2c3d', 'log' => 'npm test falhou']));

        self::assertSame(Command::FAILURE, $this->comando->execute([]));

        $lida = $this->reler($p);
        self::assertSame(SituacaoDaPublicacao::Falhou, $lida->getSituacao());
        self::assertSame('npm test falhou', $lida->getLog());
        self::assertEquals($this->relogio->now(), $lida->getTerminadaEm(), 'sem terminadaEm no resultado, vale a hora da leitura');
        self::assertDirectoryDoesNotExist($this->raiz . '/' . $p->getId());
        self::assertStringContainsString('A geração dos blogs falhou.', $this->comando->getDisplay());
    }

    #[TestDox('Só o iniciado: o pedido passa a gerando e a pasta fica; uma segunda rodada não muda nada')]
    public function testIniciado(): void
    {
        $p = $this->pedido('2026-11-03 14:55');
        $this->builderEscreve($p, 'iniciado', '2026-11-03T14:59:00-03:00');

        self::assertSame(Command::SUCCESS, $this->comando->execute([]));
        $lida = $this->reler($p);
        self::assertSame(SituacaoDaPublicacao::Gerando, $lida->getSituacao());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 14:59:00'), $lida->getIniciadaEm());
        self::assertDirectoryExists($this->raiz . '/' . $p->getId());

        self::assertSame(Command::SUCCESS, $this->comando->execute([]));
        self::assertSame('', $this->comando->getDisplay());
    }

    #[TestDox('30 minutos depois do iniciado sem resultado: falhou ("o builder não respondeu") e a pasta sai')]
    public function testBuilderNaoRespondeu(): void
    {
        $p = $this->pedido('2026-11-03 14:20');
        $this->builderEscreve($p, 'iniciado', '2026-11-03T14:30:01-03:00');

        self::assertSame(Command::SUCCESS, $this->comando->execute([]), 'às 15h, 30 minutos ainda não passaram');
        self::assertSame(SituacaoDaPublicacao::Gerando, $this->reler($p)->getSituacao());

        $this->relogio->modify('+1 second');
        self::assertSame(Command::FAILURE, $this->comando->execute([]));
        $lida = $this->reler($p);
        self::assertSame(SituacaoDaPublicacao::Falhou, $lida->getSituacao());
        self::assertSame('O builder não respondeu em 30 minutos.', $lida->getLog());
        self::assertDirectoryDoesNotExist($this->raiz . '/' . $p->getId());
    }

    #[TestDox('Na fila sem iniciado: espera 30 minutos a partir do pedido, depois falha')]
    public function testBuilderNaoComecou(): void
    {
        $recente = $this->pedido('2026-11-03 14:40');
        $antigo = $this->pedido('2026-11-03 14:29');

        self::assertSame(Command::FAILURE, $this->comando->execute([]));

        self::assertSame(SituacaoDaPublicacao::NaFila, $this->reler($recente)->getSituacao());
        self::assertSame(SituacaoDaPublicacao::Falhou, $this->reler($antigo)->getSituacao());
        self::assertSame('O builder não começou a gerar os blogs em 30 minutos.', $this->reler($antigo)->getLog());
    }

    #[TestDox('Resultado que o BlueJus não entende conta como falha')]
    public function testResultadoEstranho(): void
    {
        $p = $this->pedido('2026-11-03 14:55');
        $this->builderEscreve($p, 'resultado.json', '"ok"');

        self::assertSame(Command::FAILURE, $this->comando->execute([]));
        self::assertSame('O builder devolveu um resultado que o BlueJus não entende.', $this->reler($p)->getLog());
    }

    #[TestDox('blog:gerar-de-novo: cria um pedido manual na fila e manda montar; com um na fila sem pacote, reaproveita')]
    public function testGerarDeNovo(): void
    {
        $gerar = new CommandTester((new Application(self::$kernel))->find('blog:gerar-de-novo'));
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');

        self::assertSame(Command::SUCCESS, $gerar->execute([]));
        $abertas = $this->publicacoes->abertas();
        self::assertCount(1, $abertas);
        self::assertSame(MotivoDaPublicacao::Manual, $abertas[0]->getMotivo());
        self::assertNull($abertas[0]->getPedidaPor());
        self::assertStringContainsString(sprintf('pedido nº %d, na fila', $abertas[0]->getId()), $gerar->getDisplay());
        self::assertCount(1, $fila->getSent());
        $mensagem = $fila->getSent()[0]->getMessage();
        self::assertInstanceOf(MontarPacoteDosBlogs::class, $mensagem);
        self::assertSame($abertas[0]->getId(), $mensagem->publicacaoId);

        self::assertSame(Command::SUCCESS, $gerar->execute([]));
        self::assertCount(1, $this->publicacoes->abertas(), 'reaproveita o pedido na fila');
        self::assertCount(1, $fila->getSent());
    }

    private function pedido(string $pedidaEm): PublicacaoDosBlogs
    {
        $p = new PublicacaoDosBlogs(new \DateTimeImmutable($pedidaEm), null, MotivoDaPublicacao::Publicar);
        $p->registrarPacote(['blog' => ['um-artigo'], 'blog-gratis' => []]);
        $this->publicacoes->salvar($p, flush: true);
        mkdir($this->raiz . '/' . $p->getId());

        return $p;
    }

    private function builderEscreve(PublicacaoDosBlogs $p, string $arquivo, string $conteudo): void
    {
        file_put_contents($this->raiz . '/' . $p->getId() . '/' . $arquivo, $conteudo);
    }

    private function reler(PublicacaoDosBlogs $p): PublicacaoDosBlogs
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $this->publicacoes->porId((int) $p->getId()) ?? throw new \LogicException('sumiu');
    }

    private function apagar(string $caminho): void
    {
        if (is_file($caminho)) {
            unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }
        foreach (scandir($caminho) ?: [] as $nome) {
            if ($nome !== '.' && $nome !== '..') {
                $this->apagar($caminho . '/' . $nome);
            }
        }
        rmdir($caminho);
    }
}
