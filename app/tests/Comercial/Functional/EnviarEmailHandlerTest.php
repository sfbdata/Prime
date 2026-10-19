<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\MessageHandler\EnviarEmailHandler;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\Carteiro;
use App\Repository\TenantRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Comercial\Support\BusQueRegistra;
use App\Tests\Comercial\Support\MailerQueFalha;
use App\Tests\Comercial\Support\MontaOMontador;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * O handler contra o banco: reivindicação, descadastro relido, vazão por contagem (minuto e dia), prioridade dos
 * transacionais do ciclo da conta, exceção devolvendo à fila. Relógio fixo no futuro, longe de qualquer dado de base.
 */
#[CoversClass(EnviarEmailHandler::class)]
#[CoversClass(EnvioDeEmailRepository::class)]
final class EnviarEmailHandlerTest extends KernelTestCase
{
    use MontaOMontador;

    private const AGORA = '2030-01-15 10:00:00';

    private Connection $conexao;
    private EnvioDeEmailRepository $envios;
    private DescadastroDeEmailInterface $descadastro;
    private BusQueRegistra $bus;
    private MockClock $relogio;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->envios = static::getContainer()->get(EnvioDeEmailRepository::class);
        $this->descadastro = static::getContainer()->get(DescadastroDeEmailInterface::class);
        $this->bus = new BusQueRegistra($this->conexao);
        $this->relogio = new MockClock(self::AGORA);
    }

    private function handler(int $porMinuto = 20, int $teto = 1500, ?MailerInterface $mailer = null): EnviarEmailHandler
    {
        $c = static::getContainer();

        return new EnviarEmailHandler(
            $this->envios, $this->descadastro, $c->get(TenantRepository::class), $this->montador(),
            $mailer ?? $c->get(MailerInterface::class), $this->bus, $this->relogio, new NullLogger(), $porMinuto, $teto,
        );
    }

    private function enfileirar(TipoDeEnvio $tipo = TipoDeEnvio::Lista, string $origem = 'campanha:1', string $para = 'ana@exemplo.com'): int
    {
        $mensagem = new MensagemDeEmail(
            $para, 'Assunto do teste', '<p>Olá, {nome}. Segredo do corpo.</p>', $tipo, $origem,
            $tipo === TipoDeEnvio::Lista ? 'comercial_dicas' : null, nome: 'Ana Souza',
        );
        $carteiro = new Carteiro($this->envios, $this->descadastro, new BusQueRegistra($this->conexao), $this->relogio);

        return $carteiro->enviar($mensagem)->envioId;
    }

    /** Envios já saídos, para ocupar a vazão. */
    private function semearEnviados(int $quantos, string $enviadoEm): void
    {
        for ($i = 0; $i < $quantos; ++$i) {
            $this->conexao->executeStatement(
                "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, origem, situacao, criado_em, enviado_em, aberturas, cliques)
                 VALUES (:token, 'semente@exemplo.com', 'x', 'lista', 'campanha:9', 'enviado', :em, :em, 0, 0)",
                ['token' => bin2hex(random_bytes(16)), 'em' => $enviadoEm],
            );
        }
    }

    private function situacao(int $id): string
    {
        return (string) $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$id]);
    }

    private function proximaTentativa(int $id): ?string
    {
        $valor = $this->conexao->fetchOne('SELECT proxima_tentativa_em FROM comercial_envio WHERE id = ?', [$id]);

        return $valor === null || $valor === false ? null : substr((string) $valor, 0, 19);
    }

    private function temConteudo(int $id): bool
    {
        return $this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$id]) !== false;
    }

    private function unicoAtraso(): int
    {
        self::assertCount(1, $this->bus->envelopes, 'um redespacho');
        $atraso = $this->bus->envelopes[0]->last(DelayStamp::class);
        self::assertInstanceOf(DelayStamp::class, $atraso);

        return $atraso->getDelay();
    }

    #[TestDox('Envia: situação enviado, enviado_em gravado e o conteúdo (o corpo) apagado')]
    public function testEnviaEApagaConteudo(): void
    {
        $id = $this->enfileirar();

        ($this->handler())(new EnviarEmail($id));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, 'Olá, Ana.');
        self::assertSame('enviado', $this->situacao($id));
        self::assertNotNull($this->conexao->fetchOne('SELECT enviado_em FROM comercial_envio WHERE id = ?', [$id]));
        self::assertFalse($this->temConteudo($id), 'o corpo não fica guardado depois do estado final');
    }

    #[TestDox('Reivindicação: a segunda entrega da mesma mensagem não manda de novo')]
    public function testDuasEntregasUmEnvio(): void
    {
        $id = $this->enfileirar();
        $handler = $this->handler();

        $handler(new EnviarEmail($id));
        $handler(new EnviarEmail($id));

        self::assertEmailCount(1);
        self::assertFalse($this->envios->reivindicar($id, $this->relogio->now()), 'enviado não volta a ser reivindicado');
    }

    #[TestDox('Descadastro relido: quem saiu da lista depois de enfileirar fica bloqueado, sem e-mail e sem conteúdo')]
    public function testDescadastroRelido(): void
    {
        $id = $this->enfileirar();
        $this->descadastro->descadastrar(null, 'ana@exemplo.com', 'comercial_dicas', 'link_do_email');

        ($this->handler())(new EnviarEmail($id));

        self::assertEmailCount(0);
        self::assertSame('bloqueado_descadastro', $this->situacao($id));
        self::assertFalse($this->temConteudo($id));
    }

    #[TestDox('Vazão por minuto: sem vaga, volta a enfileirado e reenfileira com 60 s de atraso')]
    public function testVazaoPorMinuto(): void
    {
        $this->semearEnviados(2, '2030-01-15 09:59:30');
        $id = $this->enfileirar();

        ($this->handler(porMinuto: 2))(new EnviarEmail($id));

        self::assertEmailCount(0);
        self::assertSame('enfileirado', $this->situacao($id));
        self::assertSame(60_000, $this->unicoAtraso());
        self::assertSame('2030-01-15 10:01:00', $this->proximaTentativa($id));
        self::assertTrue($this->temConteudo($id), 'o conteúdo espera junto');
    }

    #[TestDox('Vazão por minuto vale também para os transacionais do ciclo da conta')]
    public function testMinutoValeParaOCiclo(): void
    {
        $this->semearEnviados(2, '2030-01-15 09:59:30');
        $id = $this->enfileirar(TipoDeEnvio::Transacional, 'automacao:val');

        ($this->handler(porMinuto: 2))(new EnviarEmail($id));

        self::assertEmailCount(0);
        self::assertSame(60_000, $this->unicoAtraso());
    }

    #[TestDox('Envios de mais de 60 s atrás não ocupam a vaga do minuto')]
    public function testMinutoSoContaOsUltimos60s(): void
    {
        $this->semearEnviados(2, '2030-01-15 09:58:00');
        $id = $this->enfileirar();

        ($this->handler(porMinuto: 2))(new EnviarEmail($id));

        self::assertEmailCount(1);
        self::assertSame('enviado', $this->situacao($id));
    }

    #[TestDox('Teto diário: sem vaga no dia, a lista espera até 00:05 do dia seguinte')]
    public function testTetoDiario(): void
    {
        $this->semearEnviados(3, '2030-01-15 08:00:00');
        $this->semearEnviados(5, '2030-01-14 23:00:00');
        $id = $this->enfileirar();

        ($this->handler(porMinuto: 100, teto: 3))(new EnviarEmail($id));

        self::assertEmailCount(0);
        self::assertSame('enfileirado', $this->situacao($id));
        self::assertSame((14 * 3600 + 5 * 60) * 1000, $this->unicoAtraso());
        self::assertSame('2030-01-16 00:05:00', $this->proximaTentativa($id));
    }

    #[TestDox('Teto diário só conta o que saiu hoje: ontem não ocupa vaga')]
    public function testTetoDiarioIgnoraOntem(): void
    {
        $this->semearEnviados(5, '2030-01-14 23:00:00');
        $id = $this->enfileirar();

        ($this->handler(porMinuto: 100, teto: 3))(new EnviarEmail($id));

        self::assertEmailCount(1);
    }

    #[TestDox('Transacional do ciclo da conta não espera o teto diário (prioridade N6)')]
    public function testCicloIgnoraTetoDiario(): void
    {
        $this->semearEnviados(3, '2030-01-15 08:00:00');
        $id = $this->enfileirar(TipoDeEnvio::Transacional, 'automacao:val');

        ($this->handler(porMinuto: 100, teto: 3))(new EnviarEmail($id));

        self::assertEmailCount(1);
        self::assertSame('enviado', $this->situacao($id));
    }

    #[TestDox('Transacional fora do ciclo da conta (consumidor, teste) respeita o teto diário')]
    public function testTransacionalForaDoCicloRespeitaTeto(): void
    {
        $this->semearEnviados(3, '2030-01-15 08:00:00');
        $id = $this->enfileirar(TipoDeEnvio::Transacional, 'consumidor:push');

        ($this->handler(porMinuto: 100, teto: 3))(new EnviarEmail($id));

        self::assertEmailCount(0);
        self::assertSame('enfileirado', $this->situacao($id));
    }

    #[TestDox('Exceção no envio: volta a enfileirado (próxima tentativa agora), conteúdo guardado e relança')]
    public function testExcecaoDevolveAFila(): void
    {
        $id = $this->enfileirar();
        $mailer = new MailerQueFalha();

        try {
            ($this->handler(mailer: $mailer))(new EnviarEmail($id));
            self::fail('A exceção do envio devia ser relançada.');
        } catch (TransportException) {
        }

        self::assertSame(1, $mailer->tentativas);
        self::assertSame('enfileirado', $this->situacao($id));
        self::assertSame(self::AGORA, $this->proximaTentativa($id));
        self::assertTrue($this->temConteudo($id), 'o retry precisa do corpo');
        self::assertTrue($this->envios->reivindicar($id, $this->relogio->now()), 'o retry consegue reivindicar de novo');
    }

    #[TestDox('Mensagem de envio que não existe (ou já saiu) não faz nada')]
    public function testEnvioInexistente(): void
    {
        ($this->handler())(new EnviarEmail(999_999_999));

        self::assertEmailCount(0);
        self::assertSame([], $this->bus->envelopes);
    }
}
