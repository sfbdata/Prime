<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Repository\IndicacaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As duas consultas agregadas das recompensas contra o banco (DAMA desfaz no fim de cada teste): indicadores e contas
 * criadas por indicação, com a conta contada uma vez e a pasta do escritório da conta.
 */
#[CoversClass(IndicacaoRepository::class)]
final class ConsultasDeRecompensaNoBancoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IndicacaoRepository $repo;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(IndicacaoRepository::class);
        $this->agora = new \DateTimeImmutable('2026-10-26 12:00:00');
    }

    private function usuario(string $nome, string $quando = '-1 year'): User
    {
        $user = new User();
        $user->setEmail(strtolower(uniqid('rc_')) . '@exemplo.com');
        $user->setFullName($nome);
        $user->setPassword('x');
        $user->setCreatedAt($this->agora->modify($quando));
        $this->em->persist($user);

        return $user;
    }

    /** Escritório da conta nova, com o usuário vinculado e as pastas pedidas (excluídas ou não). */
    private function escritorioDe(User $conta, int $vivas, int $excluidas = 0): void
    {
        $tenant = (new Tenant())->setName('Escritório da conta ' . uniqid());
        $this->em->persist($tenant);
        $this->em->persist(new UserTenant($conta, $tenant));
        $this->em->flush();
        $con = $this->em->getConnection();
        for ($i = 0; $i < $vivas + $excluidas; ++$i) {
            $con->executeStatement(
                'INSERT INTO pasta (nup, data_abertura, created_at, tenant_id, excluida_em) VALUES (?, now(), now(), ?, ?)',
                ['NUP-' . uniqid(), $tenant->getId(), $i < $vivas ? null : '2026-10-01 10:00:00'],
            );
        }
    }

    /** @return array<string, array{indicador_id: int, conta_criada_em: string, tem_pasta: bool}> pelo e-mail da conta, para achar no teste */
    private function contasDe(User ...$indicadores): array
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $indicadores);

        return array_values(array_filter($this->repo->contasParaRecompensas(), static fn (array $c): bool => in_array($c['indicador_id'], $ids, true)));
    }

    #[TestDox('contas: cada conta uma vez (para quem indicou primeiro), com a data de criação e se o escritório tem pasta não excluída')]
    public function testContasUmaVezEComPasta(): void
    {
        $ana = $this->usuario('Ana Indicadora');
        $bia = $this->usuario('Bia Indicadora');

        $c1 = $this->usuario('Conta Um', '-50 days');
        $this->em->persist(Indicacao::porEmail($ana, null, $c1->getEmail(), null, $this->agora->modify('-60 days')));
        $this->escritorioDe($c1, 2);

        $c2 = $this->usuario('Conta Dois', '-5 days');
        $this->escritorioDe($c2, 0);

        $c3 = $this->usuario('Conta Tres', '-39 days');
        $this->em->persist(Indicacao::porEmail($ana, null, strtoupper($c3->getEmail()), null, $this->agora->modify('-40 days')));
        $this->escritorioDe($c3, 0, 3);

        $c4 = $this->usuario('Conta Quatro', '-20 days');
        $this->escritorioDe($c4, 1);
        $this->em->flush();
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($ana, null, $c2, $this->agora->modify('-5 days'))));
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($bia, null, $c3, $this->agora->modify('-39 days'))));
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($bia, null, $c4, $this->agora->modify('-20 days'))));

        $da = $this->contasDe($ana);
        $db = $this->contasDe($bia);

        self::assertCount(3, $da, 'c1 (e-mail), c2 (link) e c3 (e-mail, antes do link da Bia)');
        self::assertCount(1, $db, 'c3 já é da Ana; a Bia fica só com c4');
        $porData = [];
        foreach ([...$da, ...$db] as $c) {
            $porData[substr($c['conta_criada_em'], 0, 10)] = [$c['indicador_id'], $c['tem_pasta']];
        }
        self::assertSame([$ana->getId(), true], $porData[$this->agora->modify('-50 days')->format('Y-m-d')], 'c1: pasta viva');
        self::assertSame([$ana->getId(), false], $porData[$this->agora->modify('-5 days')->format('Y-m-d')], 'c2: sem pasta');
        self::assertSame([$ana->getId(), false], $porData[$this->agora->modify('-39 days')->format('Y-m-d')], 'c3: só pasta excluída, e é da Ana');
        self::assertSame([$bia->getId(), true], $porData[$this->agora->modify('-20 days')->format('Y-m-d')], 'c4');
    }

    #[TestDox('indicadores: quem tem ao menos uma indicação (mesmo só a atribuição pelo link), com envios só dos envios')]
    public function testIndicadores(): void
    {
        $ana = $this->usuario('Ana Indicadora');
        $bia = $this->usuario('Bia Atribuida');
        $sem = $this->usuario('Sem Indicacao');
        $this->em->persist(Indicacao::porCanal($ana, null, CanalDeIndicacao::Whatsapp, $this->agora->modify('-2 days')));
        $this->em->persist(Indicacao::porCanal($ana, null, CanalDeIndicacao::Link, $this->agora->modify('-1 day')));
        $nova = $this->usuario('Conta Nova', '-3 days');
        $this->em->flush();
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($bia, null, $nova, $this->agora->modify('-3 days'))));

        $linhas = [];
        foreach ($this->repo->indicadoresParaRecompensas() as $l) {
            $linhas[$l['user_id']] = $l;
        }

        self::assertSame(2, $linhas[$ana->getId()]['envios']);
        self::assertSame('Ana Indicadora', $linhas[$ana->getId()]['nome']);
        self::assertSame(0, $linhas[$bia->getId()]['envios'], 'a atribuição pelo link não é envio');
        self::assertArrayNotHasKey($sem->getId(), $linhas);
        self::assertSame(['user_id', 'nome', 'email', 'escritorio', 'envios'], array_keys($linhas[$ana->getId()]), 'nada do indicado');
    }
}
