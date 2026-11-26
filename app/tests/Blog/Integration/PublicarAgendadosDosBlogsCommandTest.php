<?php

declare(strict_types=1);

namespace App\Tests\Blog\Integration;

use App\Blog\Command\PublicarAgendadosDosBlogsCommand;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Tests\Blog\ArtigosDeTeste;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `blog:publicar-agendados` contra o banco de verdade (DAMA desfaz cada teste): a consulta dos vencidos, a publicação
 * com motivo agendado e o artigo que falha voltando a rascunho com saída de erro (spec painel-artigos-blogs, P5).
 */
#[CoversClass(PublicarAgendadosDosBlogsCommand::class)]
final class PublicarAgendadosDosBlogsCommandTest extends KernelTestCase
{
    private ArtigoDoBlogRepository $artigos;
    private CommandTester $comando;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->artigos = static::getContainer()->get(ArtigoDoBlogRepository::class);
        $this->comando = new CommandTester((new Application(self::$kernel))->find('blog:publicar-agendados'));
    }

    #[TestDox('Publica só o agendado vencido: o futuro e o rascunho ficam, o pedido ao builder sai com motivo agendado')]
    public function testPublicaSoOVencido(): void
    {
        $vencido = $this->agendado('vencido');
        $futuro = $this->agendado('futuro');
        $this->marcar($vencido, '-1 hour');
        $this->marcar($futuro, '+1 day');
        $rascunho = $this->salvo(ArtigosDeTeste::rascunhoCompleto($this->slug('rascunho')));

        $codigo = $this->comando->execute([]);

        self::assertSame(Command::SUCCESS, $codigo);
        self::assertStringContainsString('Publicados: 1. Falharam: 0.', $this->comando->getDisplay());
        self::assertSame(SituacaoDoArtigo::Publicado, $this->reler($vencido)->getSituacao());
        self::assertSame(SituacaoDoArtigo::Agendado, $this->reler($futuro)->getSituacao());
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($rascunho)->getSituacao());
        $ultima = static::getContainer()->get(PublicacaoDosBlogsRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame(MotivoDaPublicacao::Agendado, $ultima?->getMotivo());
        self::assertNull($ultima->getPedidaPor(), 'o agendador não tem ator');
    }

    #[TestDox('Agendado vencido que não passa nas regras: volta a rascunho e o comando sai com erro')]
    public function testFalhaVoltaARascunho(): void
    {
        $ruim = $this->agendado('ruim');
        $ruim->editarTextos('', 'Curto', 'SEO', 'Descrição.', 'Linha.', 'Resumo.');
        $this->marcar($ruim, '-30 minutes');

        $codigo = $this->comando->execute([]);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('voltou a rascunho', $this->comando->getDisplay());
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($ruim)->getSituacao());
    }

    #[TestDox('Nada vencido: sucesso e sem saída')]
    public function testNadaVencido(): void
    {
        $this->marcar($this->agendado('longe'), '+2 days');

        self::assertSame(Command::SUCCESS, $this->comando->execute([]));
        self::assertSame('', $this->comando->getDisplay());
    }

    private function slug(string $base): string
    {
        return 'ag-' . $base . '-' . bin2hex(random_bytes(3));
    }

    /** Agendado para daqui a pouco; `marcar` troca a data (o agendar() recusa passado). */
    private function agendado(string $base): ArtigoDoBlog
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto($this->slug($base));
        $artigo->agendar(new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable());

        return $this->salvo($artigo);
    }

    private function marcar(ArtigoDoBlog $artigo, string $relativo): void
    {
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement(
            'UPDATE artigo_blog SET publicar_em = :em WHERE id = :id',
            ['em' => (new \DateTimeImmutable($relativo))->format('Y-m-d H:i:s'), 'id' => $artigo->getId()],
        );
        $this->em()->clear();
    }

    private function salvo(ArtigoDoBlog $artigo): ArtigoDoBlog
    {
        $this->artigos->salvar($artigo, flush: true);

        return $artigo;
    }

    private function reler(ArtigoDoBlog $artigo): ArtigoDoBlog
    {
        $this->em()->clear();

        return $this->artigos->porId((int) $artigo->getId()) ?? throw new \LogicException('sumiu');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
