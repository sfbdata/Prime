<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Entity\IndicacaoConfig;
use App\Indicacao\Entity\IndicacaoPausa;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Exception\IndicacaoRecusada;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\RegistroDeIndicacao;
use App\Indicacao\UseCase\RegistrarIndicacaoPorCanalUseCase;
use App\Profile\Entity\UserProfile;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** As consultas do Indicar o BlueJus contra o banco (DAMA desfaz no fim de cada teste). */
#[CoversClass(IndicacaoRepository::class)]
#[CoversClass(IndicacaoCodigoRepository::class)]
#[CoversClass(IndicacaoConfigRepository::class)]
#[CoversClass(RegistroDeIndicacao::class)]
final class IndicacaoRepositoryTest extends KernelTestCase
{
    private const AGORA = '2026-10-19 15:00:00';

    private EntityManagerInterface $em;
    private IndicacaoRepository $repo;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(IndicacaoRepository::class);
        $this->agora = new \DateTimeImmutable(self::AGORA);
    }

    private function usuario(string $nome, ?string $email = null, ?\DateTimeImmutable $criadoEm = null): User
    {
        $user = new User();
        $user->setEmail($email ?? uniqid('ind_') . '@exemplo.com');
        $user->setFullName($nome);
        $user->setPassword('x');
        $user->setCreatedAt($criadoEm ?? new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($user);

        return $user;
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function porEmail(User $por, string $email, string $quando, ?Tenant $origem = null): Indicacao
    {
        $i = Indicacao::porEmail($por, $origem, $email, null, $this->agora->modify($quando));
        $this->em->persist($i);

        return $i;
    }

    private function porCanal(User $por, CanalDeIndicacao $canal, string $quando, ?Tenant $origem = null): void
    {
        $this->em->persist(Indicacao::porCanal($por, $origem, $canal, $this->agora->modify($quando)));
    }

    #[TestDox('contagens por pessoa: total e janelas móveis (24h e 30 dias), os três canais')]
    public function testContagens(): void
    {
        $ana = $this->usuario('Ana Souza');
        $bia = $this->usuario('Bia Lima');
        $this->porEmail($ana, 'x1@exemplo.com', '-1 hour');
        $this->porCanal($ana, CanalDeIndicacao::Whatsapp, '-23 hours');
        $this->porCanal($ana, CanalDeIndicacao::Link, '-25 hours');
        $this->porCanal($ana, CanalDeIndicacao::Link, '-31 days');
        $this->porCanal($bia, CanalDeIndicacao::Link, '-1 hour');
        $this->em->flush();

        self::assertSame(4, $this->repo->contarDoUsuario((int) $ana->getId()));
        self::assertSame(2, $this->repo->contarDoUsuarioDesde((int) $ana->getId(), $this->agora->modify('-24 hours')));
        self::assertSame(3, $this->repo->contarDoUsuarioDesde((int) $ana->getId(), $this->agora->modify('-30 days')));
    }

    #[TestDox('virou conta: só conta criada DEPOIS da indicação, com o mesmo e-mail sem diferença de caixa')]
    public function testVirouConta(): void
    {
        $antes = $this->repo->totais($this->agora->modify('-24 hours'))['viraramConta'];
        $ana = $this->usuario('Ana Souza');
        $this->porEmail($ana, 'depois@exemplo.com', '-2 days');
        $this->porEmail($ana, 'antes@exemplo.com', '-2 days');
        $this->porEmail($ana, 'nunca@exemplo.com', '-1 day');
        $this->porCanal($ana, CanalDeIndicacao::Link, '-1 hour');
        $this->usuario('Depois', 'Depois@Exemplo.com', $this->agora->modify('-1 day'));
        $this->usuario('Antes', 'antes@exemplo.com', $this->agora->modify('-3 days'));
        $this->em->flush();

        $ultimas = $this->repo->ultimasDoUsuario((int) $ana->getId(), 8);

        self::assertSame('link', $ultimas[0]['canal']);
        self::assertSame('nunca@exemplo.com', $ultimas[1]['email']);
        $porEmail = array_column($ultimas, 'conta_criada', 'email');
        self::assertTrue($porEmail['depois@exemplo.com']);
        self::assertFalse($porEmail['antes@exemplo.com']);
        self::assertFalse($porEmail['nunca@exemplo.com']);
        self::assertSame(1, $this->repo->totais($this->agora->modify('-24 hours'))['viraramConta'] - $antes);
    }

    #[TestDox('as últimas respeitam o limite e vêm da mais recente para a mais antiga')]
    public function testUltimasComLimite(): void
    {
        $ana = $this->usuario('Ana Souza');
        for ($h = 1; $h <= 10; ++$h) {
            $this->porCanal($ana, CanalDeIndicacao::Link, sprintf('-%d hours', $h));
        }
        $this->em->flush();

        $ultimas = $this->repo->ultimasDoUsuario((int) $ana->getId(), 8);

        self::assertCount(8, $ultimas);
        self::assertGreaterThan($ultimas[1]['criada_em'], $ultimas[0]['criada_em']);
    }

    #[TestDox('membro do escritório: só vínculo ATIVO naquele escritório; e-mail sem diferença de caixa')]
    public function testMembroDoEscritorio(): void
    {
        $meu = $this->escritorio('Meu');
        $outro = $this->escritorio('Outro');
        $colega = $this->usuario('Colega', 'Colega@Exemplo.com');
        $exColega = $this->usuario('Ex', 'ex@exemplo.com');
        $deFora = $this->usuario('Fora', 'fora@exemplo.com');
        $this->em->persist(new UserTenant($colega, $meu));
        $inativo = new UserTenant($exColega, $meu);
        $this->em->persist($inativo);
        $this->em->persist(new UserTenant($deFora, $outro));
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE id = ?', [$inativo->getId()]);

        self::assertTrue($this->repo->emailEhMembroDoEscritorio('colega@exemplo.com', (int) $meu->getId()));
        self::assertFalse($this->repo->emailEhMembroDoEscritorio('ex@exemplo.com', (int) $meu->getId()));
        self::assertFalse($this->repo->emailEhMembroDoEscritorio('fora@exemplo.com', (int) $meu->getId()), 'conta de fora não é consultada');
    }

    #[TestDox('painel: totais e indicadores na ordem (24h desc, total desc) com escritório, pausa e contas criadas')]
    public function testPainel(): void
    {
        $antes = $this->repo->totais($this->agora->modify('-24 hours'));
        $alfa = $this->escritorio('Alfa');
        $ana = $this->usuario('Ana Souza');
        $bia = $this->usuario('Bia Lima');
        $caio = $this->usuario('Caio Reis');
        foreach (['-1 hour', '-2 hours'] as $q) {
            $this->porCanal($bia, CanalDeIndicacao::Link, $q);
        }
        foreach (['-1 hour', '-40 hours', '-41 hours'] as $q) {
            $this->porCanal($ana, CanalDeIndicacao::Whatsapp, $q);
        }
        $this->porEmail($ana, 'nova@exemplo.com', '-50 hours', $alfa);
        $this->porEmail($caio, 'nova@exemplo.com', '-60 hours');
        $this->usuario('Nova', 'nova@exemplo.com', $this->agora->modify('-10 hours'));
        $this->em->persist(new IndicacaoPausa($bia, null, $this->agora));
        $this->em->flush();

        $totais = $this->repo->totais($this->agora->modify('-24 hours'));
        self::assertSame(7, $totais['enviadas'] - $antes['enviadas']);
        self::assertSame(3, $totais['ultimas24h'] - $antes['ultimas24h']);
        self::assertSame(1, $totais['viraramConta'] - $antes['viraramConta'], 'o mesmo e-mail indicado por dois conta uma vez');

        $meus = array_values(array_filter(
            $this->repo->indicadores($this->agora->modify('-24 hours'), $this->agora->modify('-30 days'), 0, 1000),
            static fn (array $l): bool => in_array($l['user_id'], [$ana->getId(), $bia->getId(), $caio->getId()], true),
        ));
        self::assertSame(['Bia Lima', 'Ana Souza', 'Caio Reis'], array_column($meus, 'nome'));
        self::assertTrue($meus[0]['pausado']);
        self::assertSame([4, 1, 4, 1], [$meus[1]['total'], $meus[1]['ultimas24h'], $meus[1]['ultimos30d'], $meus[1]['contas_criadas']]);
        self::assertNull($meus[1]['escritorio'], 'escritório da indicação mais recente (sem escritório)');
        self::assertSame(1, $meus[2]['contas_criadas']);
    }

    #[TestDox('código do link curto: grava uma vez, não troca, e o mesmo código não serve para outra pessoa')]
    public function testCodigo(): void
    {
        $codigos = static::getContainer()->get(IndicacaoCodigoRepository::class);
        $ana = $this->usuario('Ana Souza');
        $bia = $this->usuario('Bia Lima');
        $this->em->flush();

        self::assertNull($codigos->codigoDoUsuario((int) $ana->getId()));
        self::assertSame('AAAA2222', $codigos->gravarSeNaoTiver((int) $ana->getId(), 'AAAA2222'));
        self::assertSame('AAAA2222', $codigos->gravarSeNaoTiver((int) $ana->getId(), 'BBBB3333'), 'quem já tem fica com o seu');
        self::assertNull($codigos->gravarSeNaoTiver((int) $bia->getId(), 'AAAA2222'), 'código repetido não vira de outra pessoa');
        self::assertTrue($codigos->existe('AAAA2222'));
        self::assertFalse($codigos->existe('BBBB3333'));
    }

    #[TestDox('a configuração vem da linha 1 da migration, com o padrão do desenho')]
    public function testConfigDaMigration(): void
    {
        $config = static::getContainer()->get(IndicacaoConfigRepository::class)->obter();

        self::assertInstanceOf(IndicacaoConfig::class, $config);
        self::assertNotNull($this->em->find(IndicacaoConfig::class, 1));
        self::assertSame([20, 200, 30], [$config->getLimiteDia(), $config->getLimiteMes(), $config->getAlerta24h()]);
    }

    #[TestDox('a transação travada devolve o resultado do trabalho e grava no fim')]
    public function testTransacaoTravada(): void
    {
        $ana = $this->usuario('Ana Souza');
        $this->em->flush();

        $resultado = $this->repo->emTransacaoTravada((int) $ana->getId(), function () use ($ana): string {
            $this->repo->salvar(Indicacao::porCanal($ana, null, CanalDeIndicacao::Link, $this->agora));

            return 'feito';
        });

        self::assertSame('feito', $resultado);
        self::assertSame(1, $this->repo->contarDoUsuario((int) $ana->getId()));
    }

    #[TestDox('recusa dentro da trava não fecha o EntityManager nem grava nada')]
    public function testRecusaNaoFechaOEntityManager(): void
    {
        $ana = $this->usuario('Ana Souza');
        $perfil = new UserProfile($ana);
        $perfil->setCpf('123.456.789-09');
        $ana->setProfile($perfil);
        $this->em->persist($perfil);
        $this->em->persist(new IndicacaoPausa($ana, null, $this->agora));
        $this->em->flush();

        try {
            static::getContainer()->get(RegistrarIndicacaoPorCanalUseCase::class)->executar(CanalDeIndicacao::Link, $ana, null);
            self::fail('Esperava a recusa.');
        } catch (IndicacaoRecusada $e) {
            self::assertSame('Seus envios de convite foram pausados pela administração do BlueJus.', $e->getMessage());
        }

        self::assertTrue($this->em->isOpen());
        self::assertSame(0, $this->repo->contarDoUsuario((int) $ana->getId()));
    }
}
