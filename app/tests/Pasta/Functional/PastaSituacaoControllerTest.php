<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DefinirSituacaoDaPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Situação da pasta com as 4 opções do desenho 1.2.3 (`situacaoVals`): pílula, cartão do sistema no Registro,
 * carimbo, arquivar levando o andamento a Finalizada, e os caminhos antigos que devolviam Suspenso/Cancelado a Ativo.
 */
#[CoversClass(DefinirSituacaoDaPastaUseCase::class)]
final class PastaSituacaoControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    #[TestDox('escolher Suspenso na pílula grava e escreve "Situação da pasta: alterada de "Ativo" para "Suspenso"." no Registro')]
    public function testSuspenderPelaPilula(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 'suspenso');

        self::assertResponseIsSuccessful();
        self::assertSame('suspenso', $this->resposta()['novaSituacao']);
        self::assertSame('Suspenso', $this->resposta()['rotulo']);
        self::assertSame('suspenso', $this->situacao($pasta));
        $cartao = (string) $this->em()->getConnection()->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'situacao'", [$pasta->getId()]);
        self::assertStringContainsString('Situação da pasta:', $cartao);
        self::assertStringContainsString('alterada de &quot;Ativo&quot; para &quot;Suspenso&quot;.', $cartao);
    }

    #[TestDox('escolher a mesma situação não grava cartão')]
    public function testMesmaSituacaoNaoRegistra(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 'ativo');

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'situacao'", [$pasta->getId()]));
    }

    #[TestDox('Arquivar/Desarquivar do menu ⋮: suspensa vai a Arquivado (não a Ativo); arquivada volta a Ativo')]
    public function testAlternarNaoReativaSuspensa(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->mudar($pasta, 'suspenso');

        $this->mudar($pasta, null);
        self::assertSame('arquivado', $this->situacao($pasta));

        $this->mudar($pasta, null);
        self::assertSame('ativo', $this->situacao($pasta));
    }

    #[TestDox('arquivar leva o andamento a Finalizada com "Motivo: pasta arquivada."; desarquivar não reverte a fase')]
    public function testArquivarFinalizaOAndamento(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 'arquivado');

        $conn = $this->em()->getConnection();
        self::assertSame(9, (int) $conn->fetchOne('SELECT atual FROM pasta_andamento WHERE pasta_id = ?', [$pasta->getId()]));
        $fase = (string) $conn->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'fase'", [$pasta->getId()]);
        self::assertStringContainsString('Pasta finalizada', $fase);
        self::assertStringContainsString('Motivo: pasta arquivada.', $fase);

        $this->mudar($pasta, 'ativo');
        self::assertSame(9, (int) $conn->fetchOne('SELECT atual FROM pasta_andamento WHERE pasta_id = ?', [$pasta->getId()]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'fase'", [$pasta->getId()]));
    }

    #[TestDox('situação inválida: 422; sem permissão de editar: 403; nada muda')]
    public function testRecusas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $leitor = $this->criarLeitor($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->mudar($pasta, 'xpto');
        self::assertResponseStatusCodeSame(422);

        $this->logarComTenant($this->client, $leitor, $tenant);
        $this->mudar($pasta, 'cancelado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ativo', $this->situacao($pasta));
    }

    #[TestDox('pasta suspensa: pílula "Suspenso", carimbo SUSPENSO, menu com as 4 situações e o modal Editar dados com Suspenso marcado')]
    public function testTelaDaPastaSuspensa(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->mudar($pasta, 'suspenso');
        $this->em()->clear();

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $pilula = $c->filter('#btn-alternar-status');
        self::assertStringContainsString('ps-situacao-pill--suspenso', (string) $pilula->attr('class'));
        self::assertSame('Suspenso', trim($pilula->filter('.ps-situacao-label')->text()));
        $selo = $c->filter('.ps-cab-dados > .ps-cab-selo--suspenso');
        self::assertCount(1, $selo);
        self::assertSame('SUSPENSO', trim($selo->filter('.ps-cab-selo-faixa')->text()));
        self::assertCount(1, $selo->filter('.bi-pause-circle-fill'));
        self::assertCount(0, $c->filter('.ps-cab-dados > .ps-cab-selo--arquivado'));
        $opcoes = $c->filter('#psSituacao [role="menuitemradio"]');
        self::assertSame(['ativo', 'suspenso', 'cancelado', 'arquivado'], $opcoes->each(static fn ($o) => $o->attr('data-situacao')));
        self::assertSame('true', $c->filter('#psSituacao [data-situacao="suspenso"]')->attr('aria-checked'));
        self::assertSame('suspenso', $c->filter('#edit_situacao option[selected]')->attr('value'));
        self::assertStringContainsString('Arquivar pasta', $c->filter('.js-situacao-alternar')->text());
    }

    #[TestDox('pasta cancelada: carimbo CANCELADO')]
    public function testCarimboCancelado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->mudar($pasta, 'cancelado');
        $this->em()->clear();

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $selo = $c->filter('.ps-cab-dados > .ps-cab-selo--cancelado');
        self::assertCount(1, $selo);
        self::assertSame('Pasta cancelada', $selo->attr('aria-label'));
        self::assertCount(1, $selo->filter('.bi-x-octagon-fill'));
    }

    #[TestDox('o menu ⋮ manda o destino: "Arquivar" numa pasta que outra aba já arquivou não a desarquiva')]
    public function testArquivarComTelaDesatualizadaNaoDesarquiva(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->mudar($pasta, 'arquivado');

        $this->mudar($pasta, 'arquivado');

        self::assertResponseIsSuccessful();
        self::assertSame('arquivado', $this->situacao($pasta));
    }

    #[TestDox('pasta de outro escritório: 404 e nada muda')]
    public function testOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $alheia = $this->criarPasta($outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($alheia, 'cancelado');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('ativo', $this->situacao($alheia));
    }

    #[TestDox('"Editar dados" com Suspenso passa pelo mesmo caso de uso (cartão no Registro); sem o campo, não mexe')]
    public function testEditarDados(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $nup = $pasta->getNup();

        $this->editar($pasta, ['nup' => $nup, 'situacao' => 'suspenso']);
        self::assertSame('suspenso', $this->situacao($pasta));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'situacao'", [$pasta->getId()]));

        $this->editar($pasta, ['nup' => $nup]);
        self::assertSame('suspenso', $this->situacao($pasta), 'campo ausente não reativa');

        $this->editar($pasta, ['nup' => $nup, 'situacao' => 'arquivado']);
        self::assertSame(9, (int) $this->em()->getConnection()->fetchOne('SELECT atual FROM pasta_andamento WHERE pasta_id = ?', [$pasta->getId()]), 'arquivar pelo modal também finaliza');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param array<string, string> $dados */
    private function editar(Pasta $pasta, array $dados): void
    {
        $this->em()->clear();
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/editar', $dados + ['_token' => 'TOKEN_edit_pasta_' . $pasta->getId()]);
        self::assertResponseRedirects();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function mudar(Pasta $pasta, ?string $situacao): void
    {
        $this->em()->clear();
        $dados = ['_token' => 'TOKEN_pasta_alternar_situacao_' . $pasta->getId()];
        if ($situacao !== null) {
            $dados['situacao'] = $situacao;
        }
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/alternar-situacao', $dados);
    }

    private function situacao(Pasta $pasta): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT situacao FROM pasta WHERE id = ?', [$pasta->getId()]);
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Prazo ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin Situacao');
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

    /** Vê pastas e o Expediente, mas não edita. */
    private function criarLeitor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('Leitor Situacao');
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
        $user->setEmail('prazo_' . uniqid() . '@test.com');
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
        $pasta->setNup('PZ' . $rotulo . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
