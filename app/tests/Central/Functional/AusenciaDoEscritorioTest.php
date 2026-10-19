<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Central\Twig\CentralExtension;
use App\Central\UseCase\AlterarAusenciaDoEscritorioUseCase;
use App\Central\UseCase\ListarPresencaDoEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Presença, fase 3 (spec `presenca-e-ponto-no-cabecalho.md` §4; desenho 03 L.306 e L.685): o primário escolhe depois de
 * quanto tempo sem atividade a equipe aparece ausente (15, 30, 60 ou 120 min); só ele; só os valores do desenho; o
 * valor vale para o navegador (`data-inativo-apos-min`) e para o "sem atividade há N min" do servidor.
 */
#[CoversClass(CentralController::class)]
#[CoversClass(AlterarAusenciaDoEscritorioUseCase::class)]
#[CoversClass(ListarPresencaDoEscritorioUseCase::class)]
#[CoversClass(CentralExtension::class)]
final class AusenciaDoEscritorioTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('O primário escolhe 15 min: gravado, auditado e usado no "sem atividade há N min" do batimento')]
    public function testPrimarioEscolhe(): void
    {
        $client = $this->cliente();
        [$primario, $tenant] = $this->primario();
        $bia = $this->colega($tenant, 'bia');
        $this->logarComTenant($client, $primario, $tenant);

        $this->post($client, '/central/presenca/ausencia', ['minutos' => 15]);
        self::assertResponseIsSuccessful();
        self::assertSame(['minutos' => 15], $this->json($client));
        self::assertSame(15, (int) $this->em()->getConnection()->fetchOne('SELECT minutos_ausente FROM central_configuracao_presenca WHERE tenant_id = ?', [$tenant->getId()]));
        self::assertGreaterThan(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_class LIKE '%ConfiguracaoDePresenca'"));

        $agora = (new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s');
        $desde = (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO central_presenca (tenant_id, usuario_id, ultima_atividade, status, status_desde) VALUES (?, ?, ?, 'inativo', ?)",
            [$tenant->getId(), $bia->getId(), $agora, $desde],
        );
        $this->post($client, '/central/presenca', ['status' => 'disponivel']);
        $online = array_column($this->json($client)['online'], 'semAtividadeMin', 'id');
        self::assertSame(20, $online[$bia->getId()], '5 min desde o inativo + o limite do escritório (15)');
    }

    #[TestDox('Quem não é primário: 403, nada gravado; valor fora da lista: 422')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        [$primario, $tenant] = $this->primario();
        $secundario = $this->colega($tenant, 'sec');

        $this->logarComTenant($client, $secundario, $tenant);
        $this->post($client, '/central/presenca/ausencia', ['minutos' => 30]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_configuracao_presenca WHERE tenant_id = ?', [$tenant->getId()]));

        $this->logarComTenant($client, $primario, $tenant);
        $this->post($client, '/central/presenca/ausencia', ['minutos' => 45]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha um dos tempos da lista.', $this->json($client)['erro']);
    }

    #[TestDox('A página: o primário recebe a URL e o valor do seletor; o secundário não; a janela da Central usa o limite do escritório')]
    public function testPagina(): void
    {
        $client = $this->cliente();
        [$primario, $tenant] = $this->primario();
        $secundario = $this->colega($tenant, 'sec');
        $this->logarComTenant($client, $primario, $tenant);
        $this->post($client, '/central/presenca/ausencia', ['minutos' => 120]);

        $crawler = $client->request('GET', '/central');
        self::assertSame('120', $crawler->filter('[data-presenca-cabecalho]')->attr('data-ausencia-min'));
        self::assertNotNull($crawler->filter('[data-presenca-cabecalho]')->attr('data-ausencia-url'));
        self::assertSame('120', $crawler->filter('[data-inativo-apos-min]')->attr('data-inativo-apos-min'));

        $this->logarComTenant($client, $secundario, $tenant);
        $crawler = $client->request('GET', '/central');
        self::assertNull($crawler->filter('[data-presenca-cabecalho]')->attr('data-ausencia-url'), 'só o primário vê o seletor');
        self::assertSame('120', $crawler->filter('[data-inativo-apos-min]')->attr('data-inativo-apos-min'), 'a regra vale para toda a equipe');
    }

    /** @return array{0: User, 1: Tenant} */
    private function primario(): array
    {
        $em = $this->em();
        $tenant = (new Tenant())->setName('Escritório Ausência ' . uniqid());
        $em->persist($tenant);
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Administrador ' . uniqid());
        $papel->setIsSystem(true);
        $em->persist($papel);
        $user = new User();
        $user->setEmail('primario_' . uniqid() . '@test.com');
        $user->setFullName('Primário Ausência');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();

        return [$user, $tenant];
    }
}
