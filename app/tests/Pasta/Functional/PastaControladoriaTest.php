<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\PastaControladoriaController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Service\AlertasDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Controladoria da pasta, fatia A (spec central-documentos-controladoria): alertas somente leitura e o botão na pasta. */
#[CoversClass(PastaControladoriaController::class)]
#[CoversClass(AlertasDaPasta::class)]
final class PastaControladoriaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('pasta sem nada vencido: sem alertas e contador zero')]
    public function testSemAlertas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $r = $this->alertas($pasta);

        self::assertSame([], $r['alertas']);
        self::assertSame(0, $r['urgentes']);
    }

    #[TestDox('meta e prazo vencidos viram alertas de nível alta, com título, fato e providência')]
    public function testMetaEPrazoVencidos(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $meta = new Tarefa();
        $meta->setTitulo('Juntar procuração');
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('-5 days'));
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($user);
        $meta->addResponsavel($user);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $prazo = new PastaPrazo($tenant, $pasta, $user);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, 'Contestação', new \DateTimeImmutable('-2 days'), null, null);
        $this->em()->persist($prazo);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $r = $this->alertas($pasta);

        $titulos = array_column($r['alertas'], 'titulo');
        self::assertContains('1 meta(s) vencida(s)', $titulos);
        self::assertContains('1 prazo(s) vencido(s)', $titulos);
        self::assertSame(2, $r['urgentes']);
        foreach ($r['alertas'] as $a) {
            self::assertSame('alta', $a['nivel']);
            self::assertNotSame('', $a['detalhe']);
            self::assertNotSame('', $a['sugestao']);
        }
        self::assertStringContainsString('Juntar procuração', json_encode($r['alertas'], JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('prazo cumprido ou no futuro não gera alerta')]
    public function testPrazoCumpridoOuFuturo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $futuro = new PastaPrazo($tenant, $pasta, $user);
        $futuro->definir(PastaPrazo::TIPO_PRAZO, 'Futuro', new \DateTimeImmutable('+3 days'), null, null);
        $this->em()->persist($futuro);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertSame([], $this->alertas($pasta)['alertas']);
    }

    #[TestDox('pasta parada: 100 dias sem movimento é alta; 45 dias é baixa; 70 dias é média')]
    public function testPastaParada(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->logarComTenant($this->client, $user, $tenant);

        foreach ([100 => 'alta', 70 => 'media', 45 => 'baixa'] as $dias => $nivel) {
            $pasta = $this->criarPasta($this->em()->find(Tenant::class, $tenant->getId()));   // a requisição soltou a entidade
            $pasta->setDataAbertura(new \DateTimeImmutable('-' . $dias . ' days'));
            $this->em()->flush();
            $achados = array_values(array_filter($this->alertas($pasta)['alertas'], static fn (array $a): bool => str_starts_with($a['titulo'], 'Pasta parada')));
            self::assertCount(1, $achados, $dias . ' dias');
            self::assertSame($nivel, $achados[0]['nivel'], $dias . ' dias');
            self::assertSame('Pasta parada há ' . $dias . ' dias', $achados[0]['titulo']);
        }
    }

    #[TestDox('pasta de outro escritório responde 404')]
    public function testOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $dele = $this->criarPasta($outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('GET', '/pasta/' . $dele->getId() . '/controladoria');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('a pasta mostra o botão Controladoria, filho direto do corpo, e continua com a linha do tempo e as abas')]
    public function testBotaoNaPasta(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $c->filter('.js-ctl-abrir'));
        self::assertSame('Controladoria', trim($c->filter('.js-ctl-abrir')->text()));
        self::assertStringContainsString('/pasta/' . $pasta->getId() . '/controladoria', (string) $c->filter('.js-ctl-abrir')->attr('data-url'));
        self::assertCount(1, $c->filter('.ps-and'), 'a linha do tempo continua na pasta');
        self::assertCount(1, $c->filter('.js-ctl-fundo > .ctl-painel > .ctl-topo'));
        self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) $c->filter('.js-ctl-fundo')->html());
    }

    /** @return array{alertas: list<array<string, string>>, urgentes: int} */
    private function alertas(Pasta $pasta): array
    {
        $this->client->xmlHttpRequest('GET', '/pasta/' . $pasta->getId() . '/controladoria');
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Controladoria ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin Controladoria');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return [$user, $tenant];
    }

    private function criarLeitor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('Leitor Controladoria');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Leitor ' . uniqid());
        $role->setIsSystem(false);
        $this->em()->persist($role);
        foreach (['modules.pastas.view', 'resources.pasta.view'] as $codigo) {
            $perm = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em()->persist($perm);
            }
            $v = new TenantRolePermission();
            $v->setTenantRole($role);
            $v->setPermission($perm);
            $this->em()->persist($v);
            $role->getTenantRolePermissions()->add($v);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('ctl_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $rotulo = ''): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('CT' . $rotulo . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
