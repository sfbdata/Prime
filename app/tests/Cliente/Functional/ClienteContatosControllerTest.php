<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteResumoController;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Edição inline dos contatos na janela "Detalhes do cliente"
 * (`POST /clientes/{id}/contatos`, desenho 1.2.3 dc L.810-836 / L.5748-5786).
 *
 * Barreiras, cada uma com o seu teste: tenant (404), permissão de EDITAR o
 * cliente (403 — provada com o recurso irmão: o mesmo colaborador com
 * `resources.cliente.edit` grava), CSRF (403), valor (422). Toda recusa confere
 * no BANCO (DBAL, sem identity map) que nada mudou.
 *
 * O armazenamento de CSRF é trocado por um fixo ('TOKEN_<id>') com
 * `disableReboot()` (mesmo padrão de ExcluirClienteArquivosTest); o teste de
 * arranjo prova que a janela leva URL e token, e um POST usa o token lido dela.
 */
#[CoversClass(ClienteResumoController::class)]
final class ClienteContatosControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    /** @return iterable<string, array{string, string, string, string}> */
    public static function campos(): iterable
    {
        yield 'celular' => ['celular', '61984004003', 'telefone_celular', '(61) 98400-4003'];
        yield 'fixo'    => ['fixo', '61 3425-8844', 'telefone_fixo', '(61) 3425-8844'];
        yield 'e-mail'  => ['email', ' Novo.Contato@Exemplo.COM ', 'email', 'novo.contato@exemplo.com'];
    }

    #[DataProvider('campos')]
    #[TestDox('grava $campo normalizado e devolve a janela já com o valor novo')]
    public function testGravaCadaCampo(string $campo, string $valor, string $coluna, string $esperado): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarClientePF($tenant, 'Maria Contato');
        $id = (int) $cliente->getId();
        $this->em()->clear();

        $crawler = $client->request('POST', "/clientes/{$id}/contatos", [
            'campo'  => $campo,
            'valor'  => $valor,
            '_token' => 'TOKEN_cliente_contatos_' . $id,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($esperado, $this->coluna($coluna, $id));
        self::assertSame(
            $esperado,
            trim($crawler->filter('.ps-cli-janela > .ps-cli-corpo > .ps-cli-contatos > .ps-cli-contato[data-campo="' . $campo . '"] > .ps-cli-contato-valor')->text()),
            'a resposta é a janela re-renderizada com o valor gravado'
        );
    }

    #[TestDox('lixeira do telefone: valor vazio remove (coluna nula) e a linha some da janela')]
    public function testTelefoneVazioRemove(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarClientePF($tenant, 'Maria Remove', '(61) 98400-4003');
        $id = (int) $cliente->getId();
        $this->em()->clear();

        $crawler = $client->request('POST', "/clientes/{$id}/contatos", ['campo' => 'celular', 'valor' => '', '_token' => 'TOKEN_cliente_contatos_' . $id]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->coluna('telefone_celular', $id));
        self::assertCount(0, $crawler->filter('.ps-cli-contato[data-campo="celular"]'));
        self::assertCount(
            1,
            $crawler->filter('.ps-cli-corpo > .ps-cli-add > button.js-ps-ct-novo[data-campo="celular"]'),
            'o slot voltou a ficar livre: "+ Telefone" reaparece apontando para ele'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidos(): iterable
    {
        yield 'telefone sem DDD'   => ['celular', '98400-4003'];
        yield 'e-mail malformado'  => ['email', 'maria.exemplo.com'];
        yield 'e-mail vazio'       => ['email', ''];
        yield 'campo desconhecido' => ['cpf', '12345678901'];
    }

    #[DataProvider('invalidos')]
    #[TestDox('valor inválido ($campo = "$valor"): 422 com a mensagem, nada gravado')]
    public function testValorInvalidoRecebe422(string $campo, string $valor): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarClientePF($tenant, 'Maria Invalida', '(61) 98400-4003');
        $id = (int) $cliente->getId();
        $email = $cliente->getEmail();
        $this->em()->clear();

        $client->request('POST', "/clientes/{$id}/contatos", ['campo' => $campo, 'valor' => $valor, '_token' => 'TOKEN_cliente_contatos_' . $id]);

        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertNotSame('', (string) ($json['erro'] ?? ''));
        self::assertSame('(61) 98400-4003', $this->coluna('telefone_celular', $id));
        self::assertSame($email, $this->coluna('email', $id));
    }

    #[TestDox('token CSRF inválido: 403, nada gravado')]
    public function testCsrfInvalido(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarClientePF($tenant, 'Maria Csrf');
        $id = (int) $cliente->getId();
        $this->em()->clear();

        $client->request('POST', "/clientes/{$id}/contatos", ['campo' => 'celular', 'valor' => '61984004003', '_token' => 'TOKEN_outra_acao']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->coluna('telefone_celular', $id));
    }

    #[TestDox('sem permissão de editar o cliente: 403; o mesmo papel com resources.cliente.edit grava (controle)')]
    public function testSemPermissaoDeEditar(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarClientePF($tenant, 'Maria Fechada');
        $id = (int) $cliente->getId();
        $soVe   = $this->criarColaborador($tenant, ['resources.cliente.view']);
        $edita  = $this->criarColaborador($tenant, ['resources.cliente.view', 'resources.cliente.edit']);
        $this->em()->clear();

        $this->logarComTenant($client, $soVe, $tenant);
        $client->request('POST', "/clientes/{$id}/contatos", ['campo' => 'celular', 'valor' => '61984004003', '_token' => 'TOKEN_cliente_contatos_' . $id]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->coluna('telefone_celular', $id));

        // Quem só vê também não recebe os controles na janela.
        $this->em()->clear();
        $crawler = $client->request('GET', "/clientes/{$id}/resumo");
        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('.ps-cli-janela')->attr('data-contatos-url'));
        self::assertCount(0, $crawler->filter('.js-ps-ct-editar, .js-ps-ct-novo, .ps-cli-add'));

        // Controle (recurso irmão): mesma requisição, papel com edit — grava.
        $this->em()->clear();
        $this->logarComTenant($client, $edita, $tenant);
        $client->request('POST', "/clientes/{$id}/contatos", ['campo' => 'celular', 'valor' => '61984004003', '_token' => 'TOKEN_cliente_contatos_' . $id]);
        self::assertResponseIsSuccessful();
        self::assertSame('(61) 98400-4003', $this->coluna('telefone_celular', $id));
    }

    #[TestDox('cliente de outro escritório: 404 (não 403), nada gravado nem vazado')]
    public function testOutroTenantRecebe404(): void
    {
        [$client, $tenantA, $gestorA] = $this->gestorLogado();
        $tenantB  = $this->criarTenant();
        $gestorB  = $this->criarGestor($tenantB);
        $clienteB = $this->criarClientePF($tenantB, 'Cliente De B', '(61) 98400-4003');
        $id = (int) $clienteB->getId();
        $this->em()->clear();

        // Controle: o dono grava.
        $this->logarComTenant($client, $gestorB, $tenantB);
        $client->request('POST', "/clientes/{$id}/contatos", ['campo' => 'fixo', 'valor' => '6134258844', '_token' => 'TOKEN_cliente_contatos_' . $id]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('POST', "/clientes/{$id}/contatos", ['campo' => 'celular', 'valor' => '61999990000', '_token' => 'TOKEN_cliente_contatos_' . $id]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('(61) 98400-4003', $this->coluna('telefone_celular', $id));
        self::assertStringNotContainsString('CLIENTE DE B', (string) $client->getResponse()->getContent());
    }

    #[TestDox('arranjo: contatos editáveis, depois os "+", depois o rodapé — e a janela leva URL e token que o POST aceita')]
    public function testArranjoDaJanela(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarClientePF($tenant, 'Maria Arranjo', '(61) 98400-4003');
        $id = (int) $cliente->getId();
        $this->em()->clear();

        $crawler = $client->request('GET', "/clientes/{$id}/resumo");
        self::assertResponseIsSuccessful();

        $janela = $crawler->filter('.ps-cli-janela[role="dialog"]');
        self::assertSame('/clientes/' . $id . '/contatos', $janela->attr('data-contatos-url'));

        // Cada contato: ícone, valor, copiar e lápis, como filhos diretos da linha.
        $celular = '.ps-cli-janela > .ps-cli-corpo > .ps-cli-contatos > .ps-cli-contato[data-campo="celular"][data-tipo="tel"]';
        self::assertCount(1, $crawler->filter($celular . ' > i.bi-telephone'));
        self::assertCount(1, $crawler->filter($celular . ' > .ps-cli-contato-valor'));
        self::assertCount(1, $crawler->filter($celular . ' > button.js-ps-copiar'));
        self::assertCount(1, $crawler->filter($celular . ' > button.js-ps-ct-editar[title="Editar"] > i.bi-pencil'));
        self::assertSame('(61) 98400-4003', $crawler->filter($celular)->attr('data-valor'));
        self::assertCount(1, $crawler->filter('.ps-cli-corpo > .ps-cli-contatos > .ps-cli-contato[data-campo="email"][data-tipo="email"] > button.js-ps-ct-editar'));

        // "+ Telefone" aponta para o slot livre (o fixo); "+ E-mail" não existe (e-mail preenchido).
        $novos = $crawler->filter('.ps-cli-corpo > .ps-cli-add > button.ps-cli-acao.js-ps-ct-novo');
        self::assertCount(1, $novos);
        self::assertSame('fixo', $novos->attr('data-campo'));
        self::assertSame('Telefone', trim($novos->text()));

        // Ordem dos blocos no corpo: contatos → "+" → rodapé.
        $ordem = $crawler->filter('.ps-cli-janela > .ps-cli-corpo > *')->each(fn ($n) => (string) $n->attr('class'));
        $pos   = static fn (string $classe): int|false => array_search($classe, $ordem, true);
        self::assertNotFalse($pos('ps-cli-contatos'));
        self::assertGreaterThan($pos('ps-cli-contatos'), $pos('ps-cli-add'));
        self::assertGreaterThan($pos('ps-cli-add'), $pos('ps-cli-acoes'));

        // Sem campo no HTML servido: o input nasce no JS ao clicar no lápis.
        self::assertCount(0, $janela->filter('form, input, textarea'));

        // O token impresso na janela é o que o POST aceita.
        $token = (string) $janela->attr('data-contatos-token');
        self::assertNotSame('', $token);
        $this->em()->clear();
        $client->request('POST', "/clientes/{$id}/contatos", ['campo' => 'fixo', 'valor' => '6134258844', '_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame('(61) 3425-8844', $this->coluna('telefone_fixo', $id));
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{KernelBrowser, Tenant, User} */
    private function gestorLogado(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $this->logarComTenant($client, $gestor, $tenant);

        return [$client, $tenant, $gestor];
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function coluna(string $coluna, int $id): ?string
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT ' . $coluna . ' FROM cliente WHERE id = ?', [$id]);

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Contatos ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function novoUsuario(string $prefixo): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName(ucfirst($prefixo) . ' ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $tenant = $this->em()->getReference(Tenant::class, (int) $tenant->getId());
        $user   = $this->novoUsuario('gestor');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    /** @param list<string> $codigos permissões do papel (ex.: resources.cliente.view) */
    private function criarColaborador(Tenant $tenant, array $codigos): User
    {
        $em     = $this->em();
        $tenant = $em->getReference(Tenant::class, (int) $tenant->getId());
        $user   = $this->novoUsuario('colab');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup('resources');
                $em->persist($perm);
                $em->flush();
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function criarClientePF(Tenant $tenant, string $nome, ?string $celular = null): ClientePF
    {
        $n = ++$this->seq;

        $cliente = new ClientePF();
        $cliente->setNomeCompleto($nome);
        // CPF de 11 dígitos, distinto por teste (unicidade cpf+tenant).
        $cliente->setCpf('9876543210' . $n);
        $cliente->setRg('654321' . $n);
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('contatos_' . uniqid() . '@test.com');
        $cliente->setTelefoneCelular($celular);
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($this->em()->getReference(Tenant::class, (int) $tenant->getId()));
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }
}
