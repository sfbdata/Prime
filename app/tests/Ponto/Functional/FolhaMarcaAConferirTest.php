<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O dia ambíguo aparece marcado "a conferir" na tela do colaborador e na ficha do admin — e só
 * nelas: o PDF e o XLSX assinados não mudam (decisão do dono, `docs/specs/ponto-folha-uma-batida-por-tipo.md`
 * §9.9). Na ficha, o admin alcança as batidas que ficaram fora da conta, que antes desta mudança não
 * apareciam em lugar nenhum da folha.
 *
 * Os seletores são da LINHA do dia (`tr[data-chave-dia]`): o selo existir "em algum lugar da página"
 * não prova que ele está no dia certo.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class FolhaMarcaAConferirTest extends JusPrimeWebTestCase
{
    #[TestDox('o colaborador ve o dia de dois repousos marcado a conferir, com o repouso que a conta usa')]
    public function testColaboradorVeODiaAmbiguoMarcado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);
        $dia = $this->diaDoMesCorrente();
        $this->criarDiaComDoisRepousos($colaborador, $tenant, $dia);

        $this->logarComTenant($client, $colaborador, $tenant);
        $crawler = $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $linha = $this->linhaDoDia($crawler, $dia);
        self::assertCount(1, $linha->filter('.a-conferir'), 'o dia ambíguo tem de vir marcado na linha dele');
        self::assertSame('repousos_distintos', $linha->filter('.a-conferir')->attr('data-a-conferir'));
        self::assertStringContainsString('mais de um repouso', (string) $linha->filter('.a-conferir')->attr('title'));
        self::assertStringNotContainsString('Corrija', (string) $linha->filter('.a-conferir')->attr('title'), 'o colaborador não corrige batida');
        self::assertStringContainsString('09:00:00', $linha->text(), 'a célula mostra o repouso que a conta usa');
        self::assertStringNotContainsString('12:00:00', $linha->text(), 'o colaborador não vê a batida desconsiderada');
        self::assertCount(0, $linha->filter('.batida-desconsiderada'), 'o colaborador não edita batidas');
    }

    #[TestDox('o admin ve a marca e alcanca a batida que ficou fora da conta, com editar e excluir')]
    public function testAdminAlcancaABatidaDesconsiderada(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaboradorComum($tenant);
        $dia = $this->diaDoMesCorrente();
        $desconsiderada = $this->criarDiaComDoisRepousos($colaborador, $tenant, $dia);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful();
        $linha = $this->linhaDoDia($crawler, $dia);
        self::assertCount(1, $linha->filter('.a-conferir'));

        $link = $linha->filter(sprintf('a.batida-desconsiderada[data-registro-id="%d"]', $desconsiderada->getId()));
        self::assertCount(1, $link, 'o admin precisa alcançar a batida que ficou fora da conta');
        self::assertStringContainsString(sprintf('/ponto/%d/edit', $desconsiderada->getId()), (string) $link->attr('href'));
        self::assertStringContainsString('12:00:00', $link->text());
        self::assertCount(
            1,
            $linha->filter(sprintf('form[action*="/ponto/%d/delete"]', $desconsiderada->getId())),
            'e excluí-la, com o mesmo formulário das batidas da célula'
        );
    }

    #[TestDox('o admin exclui de fato a batida desconsiderada pelo formulario da linha')]
    public function testAdminExcluiABatidaDesconsideradaPeloFormularioDaLinha(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaboradorComum($tenant);
        $dia = $this->diaDoMesCorrente();
        $desconsiderada = $this->criarDiaComDoisRepousos($colaborador, $tenant, $dia);
        $id = $desconsiderada->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));
        $formulario = $this->linhaDoDia($crawler, $dia)->filter(sprintf('form[action*="/ponto/%d/delete"]', $id))->form();
        $client->submit($formulario);

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(RegistroPonto::class)->find($id), 'o token e a rota do formulário novo têm de funcionar');

        // Sem o segundo repouso, o dia deixa de ser ambíguo.
        $crawler = $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));
        self::assertCount(0, $this->linhaDoDia($crawler, $dia)->filter('.a-conferir'));
    }

    #[TestDox('repetir o mesmo toque em segundos nao marca o dia, mas o admin ainda alcanca a repeticao')]
    public function testRepeticaoDoMesmoToqueNaoMarcaODia(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaboradorComum($tenant);
        $dia = $this->diaDoMesCorrente();
        $this->criarBatida($colaborador, $tenant, $dia, RegistroPonto::TIPO_ENTRADA, '08:00:00');
        $this->criarBatida($colaborador, $tenant, $dia, RegistroPonto::TIPO_REPOUSO, '12:00:00');
        $repeticao = $this->criarBatida($colaborador, $tenant, $dia, RegistroPonto::TIPO_REPOUSO, '12:00:20');
        $this->criarBatida($colaborador, $tenant, $dia, RegistroPonto::TIPO_RETORNO, '13:00:00');
        $this->criarBatida($colaborador, $tenant, $dia, RegistroPonto::TIPO_SAIDA, '18:00:00');

        $this->logarComTenant($client, $colaborador, $tenant);
        $linhaColaborador = $this->linhaDoDia($client->request('GET', '/ponto/'), $dia);
        self::assertCount(0, $linhaColaborador->filter('.a-conferir'), 'repetição do mesmo toque não é ambiguidade');

        $this->logarComTenant($client, $admin, $tenant);
        $linhaAdmin = $this->linhaDoDia(
            $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId())),
            $dia
        );
        self::assertCount(0, $linhaAdmin->filter('.a-conferir'));
        self::assertCount(1, $linhaAdmin->filter(sprintf('a.batida-desconsiderada[data-registro-id="%d"]', $repeticao->getId())));
    }

    /** Um dia do mês corrente que não é hoje: 'ontem' no dia 1º cai fora da competência exibida. */
    private function diaDoMesCorrente(): \DateTimeImmutable
    {
        $hoje = new \DateTimeImmutable('today');

        return (int) $hoje->format('d') === 1 ? $hoje->modify('+1 day') : $hoje->modify('-1 day');
    }

    private function linhaDoDia(Crawler $crawler, \DateTimeImmutable $dia): Crawler
    {
        $linha = $crawler->filter(sprintf('tr[data-chave-dia="%s"]', $dia->format('Y-m-d')));
        self::assertCount(1, $linha, 'a linha do dia deveria existir na folha');

        return $linha;
    }

    /** @return RegistroPonto a batida que fica fora da conta (o segundo repouso) */
    private function criarDiaComDoisRepousos(User $user, Tenant $tenant, \DateTimeImmutable $dia): RegistroPonto
    {
        $this->criarBatida($user, $tenant, $dia, RegistroPonto::TIPO_ENTRADA, '08:00:00');
        $this->criarBatida($user, $tenant, $dia, RegistroPonto::TIPO_REPOUSO, '09:00:00');
        $segundo = $this->criarBatida($user, $tenant, $dia, RegistroPonto::TIPO_REPOUSO, '12:00:00');
        $this->criarBatida($user, $tenant, $dia, RegistroPonto::TIPO_RETORNO, '13:00:00');
        $this->criarBatida($user, $tenant, $dia, RegistroPonto::TIPO_SAIDA, '18:00:00');

        return $segundo;
    }

    private function criarBatida(User $user, Tenant $tenant, \DateTimeImmutable $dia, string $tipo, string $hora): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($dia->format('Y-m-d') . ' ' . $hora));
        $registro->setSedeNomeSnapshot('Teste');
        $em->persist($registro);
        $em->flush();

        return $registro;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant A CONFERIR ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('admin_conferir_' . uniqid() . '@test.com');
        $user->setFullName('Admin A Conferir');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarColaboradorComum(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $codigoPermissao = 'modules.ponto.view';
        $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => $codigoPermissao]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigoPermissao);
            $permissao->setDescription('Permissão de teste ' . $codigoPermissao);
            $permissao->setGroup(explode('.', $codigoPermissao)[0]);
            $em->persist($permissao);
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador comum ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($role);
        $vinculo->setPermission($permissao);
        $em->persist($vinculo);
        $role->getTenantRolePermissions()->add($vinculo);

        $user = new User();
        $user->setEmail('colab_conferir_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador A Conferir');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }
}
