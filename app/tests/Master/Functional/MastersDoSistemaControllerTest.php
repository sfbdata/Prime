<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\MastersDoSistemaController;
use App\Master\Controller\PainelMasterController;
use App\Repository\AuditLogRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Masters primário e secundários pela tela (spec docs/specs/perfis-1.2-masters.md §4 e §5).
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): assim a
 * recusa do Master secundário e o 403 do usuário comum vêm da regra, não do token. O CSRF inválido
 * tem teste próprio.
 */
#[CoversClass(MastersDoSistemaController::class)]
#[CoversClass(PainelMasterController::class)]
final class MastersDoSistemaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $primario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();

        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório do Master ' . uniqid()])->_real();
        $this->primario   = $this->usuario('Farlei Primário', ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->vincular($this->primario, $this->escritorio, sistema: true);
    }

    #[TestDox('o Master primário promove um secundário do escritório: vira Master secundário, com flash e auditoria')]
    public function testPrimarioPromove(): void
    {
        $alvo = $this->usuario('Ana Secundária', ['ROLE_USER']);
        $this->vincular($alvo, $this->escritorio, sistema: false);
        $this->entrar($this->primario);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'TOKEN_master_promover', 'usuario' => $alvo->getId()]);

        self::assertResponseRedirects('/master#aba=perm');
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($alvo)->getRoles());
        self::assertNotContains('ROLE_MASTER_PRIMARIO', $this->recarregar($alvo)->getRoles());
        self::assertNotNull($this->auditoriaDeRoles($alvo), 'a promoção vai para a auditoria (M7)');

        $this->client->followRedirect();
        self::assertStringContainsString('&quot;Ana Secundária&quot; agora é Master secundário.', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('o Master primário promove também o primário do escritório (perfil de sistema): vira Master secundário e o vínculo fica')]
    public function testPrimarioPromoveOPrimarioDoEscritorio(): void
    {
        $alvo = $this->usuario('Paulo Primário', ['ROLE_USER']);
        $vinculo = $this->vincular($alvo, $this->escritorio, sistema: true);
        $this->entrar($this->primario);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'TOKEN_master_promover', 'usuario' => $alvo->getId()]);

        self::assertResponseRedirects('/master#aba=perm');
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($alvo)->getRoles());
        self::assertNotContains('ROLE_MASTER_PRIMARIO', $this->recarregar($alvo)->getRoles());
        self::assertTrue($this->em->find(UserTenant::class, $vinculo->getId())?->isActive());
        self::assertNotNull($this->auditoriaDeRoles($alvo));
    }

    #[TestDox('o Master primário remove um Master secundário: só o ROLE_SUPER_ADMIN sai e o vínculo fica')]
    public function testPrimarioRemove(): void
    {
        $alvo = $this->usuario('Bruno Master', ['ROLE_SUPER_ADMIN']);
        $vinculo = $this->vincular($alvo, $this->escritorio, sistema: false);
        $this->entrar($this->primario);

        $this->client->request('POST', '/master/masters/' . $alvo->getId() . '/remover', ['_token' => 'TOKEN_master_remover_' . $alvo->getId()]);

        self::assertResponseRedirects('/master#aba=perm');
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($alvo)->getRoles());
        $vinculoRecarregado = $this->em->find(UserTenant::class, $vinculo->getId());
        self::assertNotNull($vinculoRecarregado);
        self::assertTrue($vinculoRecarregado->isActive(), 'remover Master não mexe no vínculo (M6)');
        self::assertNotNull($this->auditoriaDeRoles($alvo), 'a remoção vai para a auditoria (M7)');

        $this->client->followRedirect();
        self::assertStringContainsString('&quot;Bruno Master&quot; deixou de ser Master.', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('promover e remover gravam a troca de roles sem escritório: fora da /auditoria do escritório, na trilha do Master como "Plataforma (sem conta)"')]
    public function testAuditoriaDePlataforma(): void
    {
        $alvo = $this->usuario('Ana Secundária', ['ROLE_USER']);
        $this->vincular($alvo, $this->escritorio, sistema: false);
        $this->entrar($this->primario);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'TOKEN_master_promover', 'usuario' => $alvo->getId()]);
        self::assertResponseRedirects('/master#aba=perm');
        $this->client->request('POST', '/master/masters/' . $alvo->getId() . '/remover', ['_token' => 'TOKEN_master_remover_' . $alvo->getId()]);
        self::assertResponseRedirects('/master#aba=perm');

        $linhasDeRoles = array_values(array_filter(
            $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => User::class, 'entityId' => (string) $alvo->getId(), 'action' => 'update']),
            static fn (AuditLog $a): bool => str_contains((string) json_encode($a->getChanges()), 'roles'),
        ));
        self::assertCount(2, $linhasDeRoles, 'uma linha da promoção e uma da remoção');
        foreach ($linhasDeRoles as $linha) {
            self::assertNull($linha->getTenantId(), 'ato de plataforma grava sem escritório');
        }

        // A /auditoria do escritório lista pelo AuditLogRepository filtrado pelo tenant da sessão.
        $doEscritorio = static::getContainer()->get(AuditLogRepository::class)->findByFilters(User::class, null, null, null, (int) $this->escritorio->getId(), 1, 500);
        $idsDoEscritorio = array_map(static fn (AuditLog $a): ?int => $a->getId(), $doEscritorio);
        foreach ($linhasDeRoles as $linha) {
            self::assertNotContains($linha->getId(), $idsDoEscritorio, 'a troca de roles não aparece na auditoria do escritório');
        }
        $this->client->request('GET', '/auditoria');
        self::assertResponseIsSuccessful();
        self::assertSame(0, static::getContainer()->get(AuditLogRepository::class)->countByFilters(User::class, (string) $this->primario->getId(), null, null, (int) $this->escritorio->getId()));

        $this->client->request('GET', '/master/dados/auditoria');
        self::assertResponseIsSuccessful();
        $registros = json_decode((string) $this->client->getResponse()->getContent(), true)['registros'];
        $daPlataforma = array_values(array_filter($registros, fn (array $r): bool => $r['quem'] === 'Farlei Primário' && $r['conta'] === 'Plataforma (sem conta)'));
        $acoes = array_column($daPlataforma, 'acao');
        self::assertContains('Promovido a Master', $acoes);
        self::assertContains('Removido de Master', $acoes);
    }

    #[TestDox('o Master primário não se remove: cadeado também no servidor')]
    public function testPrimarioNaoSeRemove(): void
    {
        $this->entrar($this->primario);

        $this->client->request('POST', '/master/masters/' . $this->primario->getId() . '/remover', ['_token' => 'TOKEN_master_remover_' . $this->primario->getId()]);

        self::assertResponseRedirects('/master#aba=perm');
        self::assertContains('ROLE_MASTER_PRIMARIO', $this->recarregar($this->primario)->getRoles());
        $this->client->followRedirect();
        self::assertStringContainsString('O Master primário não pode ser excluído.', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('usuário de OUTRO escritório não é promovido, mesmo com o id forjado')]
    public function testNaoPromoveDeOutroEscritorio(): void
    {
        $outro = TenantFactory::createOne(['name' => 'Outro escritório ' . uniqid()])->_real();
        $alvo  = $this->usuario('Carla de Fora', ['ROLE_USER']);
        $this->vincular($alvo, $outro, sistema: false);
        $this->entrar($this->primario);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'TOKEN_master_promover', 'usuario' => $alvo->getId()]);

        self::assertResponseRedirects('/master#aba=perm');
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($alvo)->getRoles());
        $this->client->followRedirect();
        self::assertStringContainsString('Só usuários ativos desta conta podem ser promovidos a Master.', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Master secundário recebe a recusa ao promover e ao remover; nada muda')]
    public function testSecundarioRecebeRecusa(): void
    {
        $secundario = $this->usuario('Master Secundário', ['ROLE_SUPER_ADMIN']);
        $this->vincular($secundario, $this->escritorio, sistema: false);
        $outroMaster = $this->usuario('Outro Secundário', ['ROLE_SUPER_ADMIN']);
        $alvo = $this->usuario('Ana Secundária', ['ROLE_USER']);
        $this->vincular($alvo, $this->escritorio, sistema: false);
        $this->entrar($secundario);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'TOKEN_master_promover', 'usuario' => $alvo->getId()]);
        self::assertResponseRedirects('/master#aba=perm');
        $this->client->followRedirect();
        self::assertStringContainsString('Só o Master primário pode promover ou remover Masters.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/master/masters/' . $outroMaster->getId() . '/remover', ['_token' => 'TOKEN_master_remover_' . $outroMaster->getId()]);
        self::assertResponseRedirects('/master#aba=perm');
        $this->client->followRedirect();
        self::assertStringContainsString('Só o Master primário pode promover ou remover Masters.', (string) $this->client->getResponse()->getContent());

        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($alvo)->getRoles());
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($outroMaster)->getRoles());
    }

    #[TestDox('Master secundário não promove nem o primário do escritório: só o Master primário promove')]
    public function testSecundarioNaoPromoveOPrimarioDoEscritorio(): void
    {
        $secundario = $this->usuario('Master Secundário', ['ROLE_SUPER_ADMIN']);
        $this->vincular($secundario, $this->escritorio, sistema: false);
        $alvo = $this->usuario('Paulo Primário', ['ROLE_USER']);
        $this->vincular($alvo, $this->escritorio, sistema: true);
        $this->entrar($secundario);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'TOKEN_master_promover', 'usuario' => $alvo->getId()]);

        self::assertResponseRedirects('/master#aba=perm');
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($alvo)->getRoles());
    }

    #[TestDox('usuário comum (primário de escritório) recebe 403 nos dois POST')]
    public function testUsuarioComumRecebe403(): void
    {
        $comum = $this->usuario('Primário do Escritório', ['ROLE_USER']);
        $this->vincular($comum, $this->escritorio, sistema: true);
        $master = $this->usuario('Master Secundário', ['ROLE_SUPER_ADMIN']);
        $this->entrar($comum);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'TOKEN_master_promover', 'usuario' => $comum->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/master/masters/' . $master->getId() . '/remover', ['_token' => 'TOKEN_master_remover_' . $master->getId()]);
        self::assertResponseStatusCodeSame(403);

        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($comum)->getRoles());
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($master)->getRoles());
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrfInvalido(): void
    {
        $alvo = $this->usuario('Ana Secundária', ['ROLE_USER']);
        $this->vincular($alvo, $this->escritorio, sistema: false);
        $master = $this->usuario('Bruno Master', ['ROLE_SUPER_ADMIN']);
        $this->entrar($this->primario);

        $this->client->request('POST', '/master/masters/promover', ['_token' => 'errado', 'usuario' => $alvo->getId()]);
        self::assertResponseStatusCodeSame(403);

        // Token de outro alvo não serve: o id do token é por usuário.
        $this->client->request('POST', '/master/masters/' . $master->getId() . '/remover', ['_token' => 'TOKEN_master_remover_' . $this->primario->getId()]);
        self::assertResponseStatusCodeSame(403);

        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($alvo)->getRoles());
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($master)->getRoles());
    }

    #[TestDox('HTML do primário: primário primeiro com cadeado, secundário com lixeira, formulário com os usuários ativos do escritório (primário incluído)')]
    public function testHtmlDoPrimario(): void
    {
        $secundarioMaster = $this->usuario('Aaron Master', ['ROLE_SUPER_ADMIN']);
        $candidato = $this->usuario('Ana Secundária', ['ROLE_USER']);
        $this->vincular($candidato, $this->escritorio, sistema: false);
        $primarioDoEscritorio = $this->usuario('Paulo Primário', ['ROLE_USER']);
        $this->vincular($primarioDoEscritorio, $this->escritorio, sistema: true);
        $deFora = $this->usuario('Carla de Fora', ['ROLE_USER']);
        $this->vincular($deFora, TenantFactory::createOne(['name' => 'Outro ' . uniqid()])->_real(), sistema: false);
        $inativo = $this->usuario('Ivo Inativo', ['ROLE_USER'], ativo: false);
        $this->vincular($inativo, $this->escritorio, sistema: false);
        $this->entrar($this->primario);

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $linhas = $crawler->filter('.mp-masters > .mp-master');
        self::assertSame($this->primario->getFullName(), $linhas->first()->filter('.mp-master-id > b')->text(), 'primário primeiro');
        self::assertSame('MASTER PRIMÁRIO', $linhas->first()->filter('.mp-tag-master')->text());
        self::assertCount(1, $linhas->first()->filter('i.mp-cadeado[title="O Master primário não pode ser excluído"]'));
        self::assertCount(0, $linhas->first()->filter('[data-mp-remover-master]'));
        self::assertCount(1, $crawler->filter('.mp-tag-master:not(.mp-tag-master-sec)'), 'selo PRIMÁRIO só no primário');

        $linhaSec = $crawler->filter('.mp-master-secundario')->reduce(fn ($l) => str_contains($l->text(), 'Aaron Master'));
        self::assertSame('MASTER SECUNDÁRIO', $linhaSec->filter('.mp-tag-master')->text());
        self::assertSame('/master/masters/' . $secundarioMaster->getId() . '/remover', $linhaSec->filter('form')->attr('action'));
        self::assertSame('Remover este Master', $linhaSec->filter('[data-mp-remover-master]')->attr('title'));

        $form = $crawler->filter('form[data-mp-form-promover]');
        self::assertSame('/master/masters/promover', $form->attr('action'));
        self::assertSame('Escolha um usuário da conta *', $form->filter('option')->first()->text());
        $opcoes = $form->filter('option[value!=""]')->each(fn ($o) => $o->text());
        self::assertSame(['Ana Secundária · Advogado (a)', 'Paulo Primário · Usuário Primário'], $opcoes, 'secundário e primário do escritório; nem de outro escritório, nem inativo, nem Master');
        self::assertStringContainsString('Precisa estar cadastrado antes como usuário da conta.', $form->text());
        self::assertStringContainsString('Master secundário tem os poderes do sistema, mas não pode excluir o Master primário. Toda promoção e remoção vai para a auditoria.', $form->text());

        self::assertCount(8, $crawler->filter('.mp-poderes .mp-poder'));
        self::assertStringContainsString('Promover e remover Masters secundários', $crawler->filter('.mp-poderes')->text());
        self::assertStringContainsString('O criador do BlueJus (Master primário) e quem ele promover (Master secundário).', $crawler->filter('.mp-papeis')->text());
        self::assertCount(1, $crawler->filter('[data-mp-conf][hidden]'), 'confirmação interna, fechada');
    }

    #[TestDox('HTML do Master secundário: lista sem lixeira e sem formulário de promover')]
    public function testHtmlDoSecundario(): void
    {
        $secundario = $this->usuario('Master Secundário', ['ROLE_SUPER_ADMIN']);
        $this->vincular($secundario, $this->escritorio, sistema: false);
        $candidato = $this->usuario('Ana Secundária', ['ROLE_USER']);
        $this->vincular($candidato, $this->escritorio, sistema: false);
        $this->entrar($secundario);

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[data-mp-form-promover]'));
        self::assertCount(0, $crawler->filter('[data-mp-remover-master]'));
        self::assertStringNotContainsString('Promover a Master', (string) $crawler->filter('.mp-masters')->text());
        self::assertStringNotContainsString('Ana Secundária', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('.mp-tag-master:not(.mp-tag-master-sec)'));
        self::assertCount(1, $crawler->filter('.mp-master-primario i.mp-cadeado'));
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    /** @param list<string> $papeis */
    private function usuario(string $nome, array $papeis, bool $ativo = true): User
    {
        return UserFactory::createOne(['fullName' => $nome, 'roles' => $papeis, 'isActive' => $ativo])->_real();
    }

    private function vincular(User $user, Tenant $tenant, bool $sistema): UserTenant
    {
        $perfil = (new TenantRole())->setTenant($tenant)->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)')->setIsSystem($sistema);
        $this->em->persist($perfil);
        $vinculo = (new UserTenant($user, $tenant))->setTenantRole($perfil);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $vinculo;
    }

    private function recarregar(User $user): User
    {
        $this->em->clear();
        $recarregado = $this->em->find(User::class, $user->getId());
        self::assertNotNull($recarregado);

        return $recarregado;
    }

    private function auditoriaDeRoles(User $alvo): ?AuditLog
    {
        foreach ($this->em->getRepository(AuditLog::class)->findBy(['entityClass' => User::class, 'entityId' => (string) $alvo->getId(), 'action' => 'update']) as $registro) {
            if (str_contains((string) json_encode($registro->getChanges()), 'roles')) {
                return $registro;
            }
        }

        return null;
    }

    private function instalarCsrfFixo(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        });
    }
}
