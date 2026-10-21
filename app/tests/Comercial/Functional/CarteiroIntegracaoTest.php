<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\Carteiro;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Comercial\Support\BusQueRegistra;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** O Carteiro contra o banco: gravação isolada (sem flush), atômica com o despacho, e o descadastro real da 0d. */
#[CoversClass(Carteiro::class)]
#[CoversClass(EnvioDeEmailRepository::class)]
final class CarteiroIntegracaoTest extends KernelTestCase
{
    private Connection $conexao;
    private EnvioDeEmailRepository $envios;
    private DescadastroDeEmailInterface $descadastro;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->envios = static::getContainer()->get(EnvioDeEmailRepository::class);
        $this->descadastro = static::getContainer()->get(DescadastroDeEmailInterface::class);
    }

    private static function mensagem(string $para = 'ana@exemplo.com', TipoDeEnvio $tipo = TipoDeEnvio::Lista): MensagemDeEmail
    {
        return new MensagemDeEmail(
            $para,
            'Dicas da semana',
            '<p>Corpo com <a href="https://exemplo.com">link</a></p>',
            $tipo,
            $tipo === TipoDeEnvio::Lista ? 'campanha:1' : 'automacao:val',
            $tipo === TipoDeEnvio::Lista ? 'comercial_dicas' : null,
            replyTo: 'resp@exemplo.com',
            cc: ['cc@exemplo.com'],
            selo: 'NOVIDADE',
            titulo: 'Olá, {nome}',
        );
    }

    /** @return array<string, mixed> */
    private function linha(int $id): array
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM comercial_envio WHERE id = ?', [$id]);
        self::assertIsArray($linha);

        return $linha;
    }

    private function temConteudo(int $id): bool
    {
        return $this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$id]) !== false;
    }

    #[TestDox('O container entrega o Carteiro, que grava enfileirado com conteúdo e põe EnviarEmail na fila comercial')]
    public function testEnfileiraPeloContainer(): void
    {
        $resultado = static::getContainer()->get(CarteiroInterface::class)->enviar(self::mensagem());

        self::assertTrue($resultado->foiEnfileirado());
        $linha = $this->linha($resultado->envioId);
        self::assertSame('enfileirado', $linha['situacao']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $linha['token']);
        self::assertNotNull($linha['proxima_tentativa_em']);
        self::assertTrue($this->temConteudo($resultado->envioId));
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.comercial');
        $enviadas = array_map(static fn (Envelope $e): object => $e->getMessage(), $fila->getSent());
        self::assertEquals([new EnviarEmail($resultado->envioId)], $enviadas);
    }

    #[TestDox('Descadastrado (também em todos): grava bloqueado_descadastro, sem conteúdo e sem mensagem na fila')]
    public function testDescadastradoBloqueado(): void
    {
        $this->descadastro->descadastrar(null, 'bia@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');
        $bus = new BusQueRegistra($this->conexao);

        $resultado = $this->carteiro($bus)->enviar(self::mensagem('bia@exemplo.com'));

        self::assertSame('bloqueado_descadastro', $resultado->situacao);
        self::assertSame('bloqueado_descadastro', $this->linha($resultado->envioId)['situacao']);
        self::assertFalse($this->temConteudo($resultado->envioId));
        self::assertSame([], $bus->despachos);
    }

    #[TestDox('Transacional não consulta o descadastro: sai mesmo com o endereço em todos')]
    public function testTransacionalIgnoraDescadastro(): void
    {
        $this->descadastro->descadastrar(null, 'bia@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');

        $resultado = $this->carteiro(new BusQueRegistra($this->conexao))->enviar(self::mensagem('bia@exemplo.com', TipoDeEnvio::Transacional));

        self::assertTrue($resultado->foiEnfileirado());
    }

    #[TestDox('O despacho acontece dentro da transação, com o registro já visível nela')]
    public function testDespachoDentroDaTransacao(): void
    {
        $base = $this->conexao->getTransactionNestingLevel();
        $bus = new BusQueRegistra($this->conexao);

        $resultado = $this->carteiro($bus)->enviar(self::mensagem());

        self::assertCount(1, $bus->despachos);
        self::assertGreaterThan($base, $bus->despachos[0]['nivel'], 'dentro de uma transação a mais que a de base');
        self::assertTrue($bus->despachos[0]['registroVisivel']);
        self::assertSame($resultado->envioId, $bus->despachos[0]['envioId']);
    }

    #[TestDox('Despacho que falha desfaz o registro e o conteúdo: sem registro órfão')]
    public function testFalhaNoDespachoDesfaz(): void
    {
        $antes = (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio');
        $bus = new BusQueRegistra($this->conexao, falhar: true);

        try {
            $this->carteiro($bus)->enviar(self::mensagem());
            self::fail('O despacho devia ter falhado.');
        } catch (\RuntimeException) {
        }

        self::assertSame($antes, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio'));
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio_conteudo c LEFT JOIN comercial_envio e ON e.id = c.envio_id WHERE e.id IS NULL'));
    }

    #[TestDox('Grava sem flush do EntityManager: uma entidade pendente do chamador não vai ao banco')]
    public function testNaoGravaPendenciaDoChamador(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pendente = new Tenant();
        $pendente->setName('Pendente do chamador ' . uniqid());
        $em->persist($pendente);

        $this->carteiro(new BusQueRegistra($this->conexao))->enviar(self::mensagem());

        self::assertFalse($this->conexao->fetchOne('SELECT 1 FROM tenant WHERE name = ?', [$pendente->getName()]));
    }

    private function carteiro(MessageBusInterface $bus): Carteiro
    {
        return new Carteiro($this->envios, $this->descadastro, $bus, new MockClock('2030-01-15 10:00:00', date_default_timezone_get()));
    }
}
