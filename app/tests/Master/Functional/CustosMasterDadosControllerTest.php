<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\CustosMasterDadosController;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/** GET /master/dados/custos (spec master-custos PR 1): só o Master, mês AAAA-MM, outro valor cai no mês atual. */
#[CoversClass(CustosMasterDadosController::class)]
final class CustosMasterDadosControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('quem não é Master recebe 403')]
    public function testUsuarioComumNaoEntra(): void
    {
        $user = UserFactory::createOne(['fullName' => 'Fulano', 'roles' => ['ROLE_USER'], 'isActive' => true])->_real();
        $this->logarComTenant($this->client, $user, $this->contaDe($user));

        $this->client->request('GET', '/master/dados/custos');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('devolve só os lançamentos do mês pedido, com totais em centavos')]
    public function testDoMes(): void
    {
        $this->em->persist(new LancamentoDeCusto('2031-03', CategoriaDeCusto::Armazenamento, 12345, OrigemDoCusto::Importado, 'AWS', 'S3', new \DateTimeImmutable()));
        $this->em->persist(new LancamentoDeCusto('2031-03', CategoriaDeCusto::Outros, 100, OrigemDoCusto::Estimado, null, null, new \DateTimeImmutable()));
        $this->em->persist(new LancamentoDeCusto('2031-04', CategoriaDeCusto::Outros, 999, OrigemDoCusto::Medido, null, null, new \DateTimeImmutable()));
        $this->em->flush();

        $dados = $this->consultar('2031-03');

        self::assertSame('2031-03', $dados['mes']);
        self::assertSame(12445, $dados['kpis']['custoCentavos']);
        self::assertSame(2, $dados['kpis']['lancamentos']);
        self::assertCount(2, $dados['lancamentos']);
        self::assertSame('AWS', $dados['lancamentos'][1]['fornecedor']);
        self::assertSame('importado', $dados['lancamentos'][1]['origem']);
        self::assertCount(9, $dados['categorias']);
    }

    #[TestDox('mês inválido cai no mês atual')]
    public function testMesInvalido(): void
    {
        self::assertSame((new \DateTimeImmutable())->format('Y-m'), $this->consultar('13-2026')['mes']);
        self::assertSame((new \DateTimeImmutable())->format('Y-m'), $this->consultar()['mes']);
    }

    /** @return array<string, mixed> */
    private function consultar(?string $mes = null): array
    {
        $master = UserFactory::createOne(['fullName' => 'Master', 'roles' => ['ROLE_SUPER_ADMIN'], 'isActive' => true])->_real();
        $this->logarComTenant($this->client, $master, $this->contaDe($master));

        $this->client->request('GET', '/master/dados/custos' . ($mes !== null ? '?mes=' . $mes : ''));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function contaDe(User $user): Tenant
    {
        $conta = (new Tenant())->setName('Conta ' . uniqid())->setCriadoPor($user);
        $this->em->persist($conta);
        $perfil = (new TenantRole())->setTenant($conta)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $conta))->setTenantRole($perfil));
        $this->em->flush();

        return $conta;
    }
}
