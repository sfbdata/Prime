<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\AgendaNovaController;
use App\Agenda\Entity\Evento;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\PastaPrazo;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(AgendaNovaController::class)]
final class LembretesTest extends JusPrimeWebTestCase
{
    #[TestDox('GET /agenda/lembretes: os meus com lembrete e o prazo de hoje com hora (só com Ver prazos na agenda); não os do colega')]
    public function testLembretes(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $colega = $this->criarUsuario($tenant, true);
        $daqui = (new \DateTimeImmutable('+3 hours'))->setTime((int) (new \DateTimeImmutable('+3 hours'))->format('H'), 0);
        $this->criarEvento($tenant, $eu, 'Meu atendimento', $daqui);
        $this->criarEvento($tenant, $colega, 'Do colega', $daqui);
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'LEM-1'])->_real();
        $prazo = new PastaPrazo($tenant, $pasta, null);
        $prazo->definir(PastaPrazo::TIPO_AUDIENCIA, 'Conciliação', new \DateTimeImmutable('today'), '23:30', 'Maria');
        $this->em()->persist($prazo);
        $outro = $this->criarTenant();
        $pastaDeFora = PastaFactory::createOne(['tenant' => $outro, 'nup' => 'FORA-1'])->_real();
        $deFora = new PastaPrazo($outro, $pastaDeFora, null);
        $deFora->definir(PastaPrazo::TIPO_AUDIENCIA, 'Outra', new \DateTimeImmutable('today'), '23:30', null);
        $this->em()->persist($deFora);
        $this->em()->flush();
        $soAgenda = $this->criarUsuario($tenant, false, ['modules.agenda.view']);
        $soPastas = $this->criarUsuario($tenant, false, ['modules.agenda.view', 'modules.pastas.view']);
        $soPrazos = $this->criarUsuario($tenant, false, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);

        $this->logarComTenant($client, $eu, $tenant);
        $client->request('GET', '/agenda/lembretes');

        self::assertResponseIsSuccessful();
        $titulos = array_column(json_decode((string) $client->getResponse()->getContent(), true)['itens'], 'titulo');
        self::assertContains('Meu atendimento', $titulos);
        self::assertContains('Audiência · Pasta LEM-1', $titulos);
        self::assertNotContains('Do colega', $titulos);
        self::assertNotContains('Audiência · Pasta FORA-1', $titulos, 'prazo de outro escritório não vaza');

        $this->logarComTenant($client, $soAgenda, $tenant);
        $client->request('GET', '/agenda/lembretes');
        self::assertNotContains('Audiência · Pasta LEM-1', array_column(json_decode((string) $client->getResponse()->getContent(), true)['itens'], 'titulo'), 'sem ver prazos, sem prazos');

        $this->logarComTenant($client, $soPastas, $tenant);
        $client->request('GET', '/agenda/lembretes');
        self::assertNotContains('Audiência · Pasta LEM-1', array_column(json_decode((string) $client->getResponse()->getContent(), true)['itens'], 'titulo'), 'o módulo Pastas sozinho não traz prazos');

        $this->logarComTenant($client, $soPrazos, $tenant);
        $client->request('GET', '/agenda/lembretes');
        self::assertContains('Audiência · Pasta LEM-1', array_column(json_decode((string) $client->getResponse()->getContent(), true)['itens'], 'titulo'), 'com ver prazos, mesmo sem Pastas');
    }

    #[TestDox('O parcial global de lembretes aparece em qualquer tela para quem tem a Agenda, e não para quem não tem')]
    public function testParcialGlobal(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);
        $crawler = $client->request('GET', '/agenda');
        self::assertCount(1, $crawler->filter('#bjLembretes[data-url-lembretes="/agenda/lembretes"]'));
        self::assertCount(1, $crawler->filter('script[src*="js/lembretes.js"]'));

        $outro = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($outro, false, ['modules.expediente.view']), $outro);
        $client->request('GET', '/agenda/lembretes');
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/perfil');
        self::assertCount(0, $crawler->filter('#bjLembretes'));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $t = (new Tenant())->setName('Tenant LEM ' . uniqid());
        $this->em()->persist($t);
        $this->em()->flush();

        return $t;
    }

    private function criarEvento(Tenant $tenant, User $criador, string $titulo, \DateTimeImmutable $inicio): void
    {
        $e = (new Evento())->setTitulo($titulo)->setDataInicio($inicio)->setDataFim($inicio->modify('+1 hour'))->setCriador($criador)->setTenant($tenant)->setLembreteMinutos(1440);
        $this->em()->persist($e);
        $this->em()->flush();
    }

    /** @param list<string> $permissoes */
    private function criarUsuario(Tenant $tenant, bool $gestor, array $permissoes = []): User
    {
        $em = $this->em();
        $user = new User();
        $user->setEmail('lem_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($gestor);
        $em->persist($role);
        foreach ($permissoes as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]) ?? (new Permission())->setCode($codigo)->setDescription($codigo)->setGroup(explode('.', $codigo)[0]);
            $em->persist($perm);
            $v = new TenantRolePermission();
            $v->setTenantRole($role);
            $v->setPermission($perm);
            $em->persist($v);
            $role->getTenantRolePermissions()->add($v);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }
}
