<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\PastaReuController;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\GerenciarReusDaPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Aba Réu do Cadastro (desenho 1.2.3, `reuVals`; E12h): réus da pasta, ordem com o principal no topo, a coluna Réu da
 * Lista do Expediente passando a ler o cadastro, permissão e escritório.
 */
#[CoversClass(PastaReuController::class)]
#[CoversClass(GerenciarReusDaPastaUseCase::class)]
final class PastaReuControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    #[TestDox('adicionar grava em maiúsculas, na ordem; o primeiro é o principal; a resposta traz a lista com o documento formatado')]
    public function testAdicionar(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->post($pasta, '', ['nome' => '  banco do   brasil s.a. ', 'documento' => '00.000.000/0001-91']);
        $this->post($pasta, '', ['nome' => 'União', 'documento' => '']);

        self::assertResponseIsSuccessful();
        $reus = $this->resposta()['reus'];
        self::assertSame(['BANCO DO BRASIL S.A.', 'UNIÃO'], array_column($reus, 'nome'));
        self::assertSame('CNPJ 00.000.000/0001-91', $reus[0]['documento']);
        self::assertSame('Documento não informado', $reus[1]['documento']);
        self::assertSame([['00000000000191', 0], [null, 1]], array_map(static fn (array $l): array => [$l['documento'], (int) $l['ordem']],
            $this->em()->getConnection()->fetchAllAssociative('SELECT documento, ordem FROM pasta_reu WHERE pasta_id = ? ORDER BY ordem', [$pasta->getId()])));
    }

    #[TestDox('subir troca com o de cima (vira principal); remover tira e renumera')]
    public function testSubirERemover(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->post($pasta, '', ['nome' => 'A']);
        $this->post($pasta, '', ['nome' => 'B']);
        $this->post($pasta, '', ['nome' => 'C']);
        $ids = array_column($this->resposta()['reus'], 'id', 'nome');

        $this->post($pasta, '/' . $ids['B'] . '/subir');
        self::assertSame(['B', 'A', 'C'], array_column($this->resposta()['reus'], 'nome'));

        $this->post($pasta, '/' . $ids['B'] . '/remover');
        self::assertSame(['A', 'C'], array_column($this->resposta()['reus'], 'nome'));
        self::assertSame([0, 1], array_map('intval', $this->em()->getConnection()->fetchFirstColumn('SELECT ordem FROM pasta_reu WHERE pasta_id = ? ORDER BY ordem', [$pasta->getId()])));
    }

    #[TestDox('recusas com mensagem: sem nome, documento de tamanho errado, dígito errado, réu repetido')]
    public function testRecusas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        foreach ([
            [['nome' => ' '], 'Informe o nome ou a razão social do réu.'],
            [['nome' => 'X', 'documento' => '123'], 'CPF com 11 ou CNPJ com 14 dígitos'],
            [['nome' => 'X', 'documento' => '529.982.247-26'], 'CPF inválido: confira os dígitos.'],
        ] as [$dados, $erro]) {
            $this->post($pasta, '', $dados);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($erro, $this->resposta()['erro']);
        }
        $this->post($pasta, '', ['nome' => 'Banco X']);
        $this->post($pasta, '', ['nome' => 'banco x']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este réu já está na lista desta pasta.', $this->resposta()['erro']);
    }

    #[TestDox('pasta de outro escritório: 404; quem só vê a pasta: 403; nada gravado')]
    public function testEscritorioEPermissao(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $alheia = $this->criarPasta($outro);
        $pasta = $this->criarPasta($tenant);
        $leitor = $this->criarLeitor($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->post($alheia, '', ['nome' => 'Invasor']);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $leitor, $tenant);
        $this->post($pasta, '', ['nome' => 'Sem permissão']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_reu'));
    }

    #[TestDox('a coluna Réu da Lista do Expediente mostra o principal cadastrado e "e outros"')]
    public function testColunaDaLista(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, 'REU');
        $this->logarComTenant($this->client, $user, $tenant);
        $this->post($pasta, '', ['nome' => 'Banco do Brasil S.A.']);
        $this->post($pasta, '', ['nome' => 'União']);

        $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('BANCO DO BRASIL S.A. e outros', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('janela do Cadastro aberta da pasta tem a aba Réu com a lista; sem pasta, não tem')]
    public function testAbaNaJanela(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Cliente Reu')->setCpf('529.982.247-25')->setRg('1')->setRgOrgaoExpedidor('SSP');
        $cliente->setEmail('reu_' . uniqid() . '@t.com')->setCep('')->setEndereco('')->setCidade('')->setEstado('');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);
        $this->post($pasta, '', ['nome' => 'Banco X']);
        $this->em()->clear();

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?pasta=' . $pasta->getId());
        self::assertSame(['pessoais', 'endereco', 'contato', 'docs', 'reu', 'qual', 'hist'], $c->filter('[data-cad-aba]')->each(fn ($b) => $b->attr('data-cad-aba')));
        self::assertSame('BANCO X', json_decode((string) $c->filter('[data-cad-painel="reu"]')->attr('data-reus'), true)[0]['nome']);
        self::assertCount(1, $c->filter('.js-cad-reu-add'));
        self::assertNull($c->filter('.js-cad-reu-nome')->attr('name'), 'sem name: não entra no Salvar do cliente');

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro');
        self::assertCount(0, $c->filter('[data-cad-aba="reu"]'));
    }

    #[TestDox('regra do dono: pasta com réu cadastrado usa o cadastro; pasta sem cadastro continua com o réu do Datajud (Lista e CSV)')]
    public function testCadastroMandaDatajudDeReserva(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $comCadastro = $this->criarPasta($tenant, 'CAD');
        $semCadastro = $this->criarPasta($tenant, 'DJ');
        foreach ([[$comCadastro, 'DATAJUD DA PRIMEIRA'], [$semCadastro, 'DATAJUD DA SEGUNDA']] as $i => [$pasta, $nome]) {
            $processo = new \App\Processo\Entity\Processo();
            $processo->setTenant($tenant);
            $processo->setNumeroProcesso('0701134572025807000' . $i);
            $processo->setClasseProcessual('Procedimento Comum');
            $this->em()->persist($processo);
            $parte = new \App\Processo\Entity\ParteProcesso();
            $parte->setTipo('parte')->setNome($nome)->setPapel('RÉU')->setProcesso($processo)->setTenant($tenant);
            $this->em()->persist($parte);
            $pasta->vincularProcesso($processo);
        }
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);
        $this->post($comCadastro, '', ['nome' => 'Banco Cadastrado']);

        $reus = static::getContainer()->get(\App\Pasta\Repository\PastaRepository::class)
            ->reusPorPasta([(int) $comCadastro->getId(), (int) $semCadastro->getId()], $tenant);

        self::assertSame(['BANCO CADASTRADO'], $reus[(int) $comCadastro->getId()]);
        self::assertSame(['DATAJUD DA SEGUNDA'], $reus[(int) $semCadastro->getId()]);
    }

    #[TestDox('pasta excluída: a aba Réu só mostra a lista, sem formulário nem ações')]
    public function testPastaExcluidaSoMostra(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Cliente Lapide')->setCpf('529.982.247-25')->setRg('1')->setRgOrgaoExpedidor('SSP');
        $cliente->setEmail('lap_' . uniqid() . '@t.com')->setCep('')->setEndereco('')->setCidade('')->setEstado('');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);
        $this->em()->clear();

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?pasta=' . $pasta->getId());

        self::assertCount(1, $c->filter('[data-cad-painel="reu"]'));
        self::assertSame('', (string) $c->filter('[data-cad-painel="reu"]')->attr('data-pode-reu'));
        self::assertCount(0, $c->filter('.js-cad-reu-add'));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, string> $dados */
    private function post(Pasta $pasta, string $sufixo, array $dados = []): void
    {
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/reus' . $sufixo,
            $dados + ['_token' => 'TOKEN_' . PastaReuController::idDoToken((int) $pasta->getId())]);
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
        $user = $this->novoUsuario('Admin Reu');
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
        $user = $this->novoUsuario('Leitor Reu');
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
