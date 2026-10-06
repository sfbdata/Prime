<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteResumoController;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fragmento da janela "Detalhes do cliente" (`GET /clientes/{id}/resumo`).
 *
 * Três barreiras, cada uma com o seu teste:
 *  - tenant: cliente de outro escritório = 404 (o `find()` passa pelo TenantFilter);
 *  - cliente: sem `canAccessResource('cliente', view)` = 403;
 *  - POR PASTA: a lista "Pastas com o mesmo CPF" só traz pasta que o usuário pode
 *    abrir. O teste usa as DUAS pastas do MESMO cliente no MESMO tenant, uma com
 *    grant e outra sem — se o filtro fosse só por tenant, a segunda apareceria.
 *
 * `em->clear()` antes de cada request: sem isso o `find()` devolveria a entidade
 * da identity map sem passar pelo filtro (ver ClienteIsolamentoControllerTest).
 */
#[CoversClass(ClienteResumoController::class)]
final class ClienteResumoControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('dono do escritório recebe o fragmento: nome, CPF formatado, contatos, cliente desde e as pastas com a atual marcada')]
    public function testFragmentoDoDono(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Maria Resumo');
        $atual   = $this->criarPasta($tenant, $gestor, $cliente, 'NUP-RES-A', 'Usucapião extraordinária');
        $outra   = $this->criarPasta($tenant, $gestor, $cliente, 'NUP-RES-B', 'Revisional de aluguel');
        $outra->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->em()->flush();
        $ids = [(int) $cliente->getId(), (int) $atual->getId(), (int) $outra->getId()];
        $this->em()->clear();

        $this->logarComTenant($client, $gestor, $tenant);
        $crawler = $client->request('GET', '/clientes/' . $ids[0] . '/resumo?pasta=' . $ids[1]);

        self::assertResponseIsSuccessful();
        $janela = $crawler->filter('.ps-cli-janela[role="dialog"]');
        self::assertCount(1, $janela, 'o fragmento é UMA janela, com papel de diálogo');
        self::assertSame('Detalhes do cliente', trim($janela->filter('.ps-cli-cab > .ps-cli-cab-corpo > .ps-cli-cab-rotulo')->text()));
        self::assertSame('MARIA RESUMO', trim($janela->filter('[data-campo="nome"] .ps-cli-campo-valor')->text()));
        self::assertSame('CPF', trim($janela->filter('[data-campo="documento"] .ps-cli-campo-rotulo')->text()));
        self::assertSame('123.456.789-0' . $this->seq, trim($janela->filter('[data-campo="documento"] .ps-cli-campo-valor')->text()));
        self::assertSame(
            '123.456.789-0' . $this->seq,
            $janela->filter('[data-campo="documento"] .js-ps-copiar')->attr('data-ps-copiar'),
            'o botão copiar leva o documento formatado'
        );

        // Pastas: as duas do cliente, na ordem do número; a atual marcada e sem link.
        $pastas = $janela->filter('.ps-cli-pastas > .ps-cli-pasta');
        self::assertCount(2, $pastas);
        self::assertSame('Pastas com o mesmo CPF', trim($janela->filter('.ps-cli-pastas > .ps-cli-secao')->text()));
        self::assertSame('span', $pastas->eq(0)->nodeName(), 'a pasta atual não é link para ela mesma');
        self::assertSame('Atual', trim($pastas->eq(0)->filter('.ps-cli-tag')->text()));
        self::assertSame('a', $pastas->eq(1)->nodeName());
        self::assertSame('/pasta/' . $ids[2], $pastas->eq(1)->attr('href'));
        self::assertSame('Arquivada', trim($pastas->eq(1)->filter('.ps-cli-tag')->text()));
        // O sistema grava o nome da ação em maiúsculas.
        self::assertSame('REVISIONAL DE ALUGUEL', trim($pastas->eq(1)->filter('.ps-cli-pasta-acao')->text()));

        // KPIs: 2 pastas, 1 ativa, 0 com processo.
        self::assertSame(
            ['2', '1', '0'],
            $janela->filter('.ps-cli-numeros > .ps-cli-numero > .ps-cli-numero-v')->each(fn ($n) => trim($n->text()))
        );

        self::assertStringContainsString('Cliente desde ' . date('d/m/Y'), $janela->filter('.ps-cli-desde')->text());
        self::assertCount(1, $janela->filter('.ps-cli-contato[data-contato="E-mail"]'));

        // Rodapé: qualificação (com os dados reais) e o atalho para a ficha.
        $qual = (string) $janela->filter('.ps-cli-acoes > .js-ps-copiar-qualificacao')->attr('data-ps-copiar');
        self::assertStringStartsWith('MARIA RESUMO, ', $qual);
        self::assertStringContainsString('inscrito(a) no CPF nº 123.456.789-0' . $this->seq, $qual);
        self::assertSame('/clientes/' . $ids[0] . '/editar', $janela->filter('.ps-cli-acoes > a.ps-cli-acao')->attr('href'));

        // Somente leitura: nenhum formulário, nenhum campo editável.
        self::assertCount(0, $janela->filter('form, input, textarea'));
    }

    #[TestDox('cliente de outro escritório: 404, e não 403 — 403 confirmaria que o id existe')]
    public function testOutroTenantRecebe404(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $gestorA  = $this->criarGestor($tenantA);
        $gestorB  = $this->criarGestor($tenantB);
        $clienteB = $this->criarClientePF($tenantB, 'Cliente De B');
        $this->criarPasta($tenantB, $gestorB, $clienteB, 'NUP-RES-X', 'Ação de B');
        $this->em()->flush();
        $id = (int) $clienteB->getId();
        $this->em()->clear();

        // Controle: o dono vê.
        $this->logarComTenant($client, $gestorB, $tenantB);
        $client->request('GET', '/clientes/' . $id . '/resumo');
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', '/clientes/' . $id . '/resumo');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('CLIENTE DE B', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('NUP-RES-X', (string) $client->getResponse()->getContent());
    }

    #[TestDox('pasta do MESMO cliente e do MESMO escritório, mas sem permissão, não aparece na lista')]
    public function testPastaSemPermissaoNaoAparece(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Cliente Compartilhado');
        $liberada  = $this->criarPasta($tenant, $gestor, $cliente, 'NUP-RES-LIB', 'Ação liberada');
        $proibida  = $this->criarPasta($tenant, $gestor, $cliente, 'NUP-RES-PROIB', 'Ação proibida');
        $this->em()->flush();

        // Colaborador: vê CLIENTE pelo papel; de pasta, só o grant explícito na "liberada".
        $colaborador = $this->criarColaborador($tenant, ['resources.cliente.view']);
        $this->conceder($colaborador, $tenant, ResourceAccess::RESOURCE_PASTA, (int) $liberada->getId());
        $id = (int) $cliente->getId();
        $this->em()->clear();

        // Controle: o gestor (isSystem) vê as duas — prova que a proibida EXISTE na lista sem o filtro.
        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('GET', '/clientes/' . $id . '/resumo');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('NUP-RES-PROIB', (string) $client->getResponse()->getContent());

        $this->em()->clear();
        $this->logarComTenant($client, $colaborador, $tenant);
        $crawler = $client->request('GET', '/clientes/' . $id . '/resumo');
        self::assertResponseIsSuccessful();

        $nups = $crawler->filter('.ps-cli-pastas > .ps-cli-pasta .ps-cli-pasta-num')->each(fn ($n) => trim($n->text()));
        self::assertCount(1, $nups, 'só a pasta com grant');
        self::assertStringStartsWith('NUP-RES-LIB-', $nups[0]);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('NUP-RES-PROIB', $html, 'número da pasta sem permissão não vaza');
        self::assertStringNotContainsString('Ação proibida', $html, 'nem a ação dela');
        self::assertSame(
            '1',
            trim($crawler->filter('.ps-cli-numeros > .ps-cli-numero > .ps-cli-numero-v')->first()->text()),
            'a contagem também é só das pastas visíveis'
        );
        self::assertCount(
            0,
            $crawler->filter('.ps-cli-acoes > a.ps-cli-acao'),
            'sem permissão de editar o cliente, o atalho "Editar cadastro" não aparece'
        );
    }

    #[TestDox('usuário do escritório sem permissão de ver o cliente: 403')]
    public function testSemPermissaoDoClienteRecebe403(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Cliente Fechado');
        $this->criarPasta($tenant, $gestor, $cliente, 'NUP-RES-F', 'Ação fechada');
        $this->em()->flush();
        $semNada = $this->criarColaborador($tenant, []);
        $id = (int) $cliente->getId();
        $this->em()->clear();

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', '/clientes/' . $id . '/resumo');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('NUP-RES-F', (string) $client->getResponse()->getContent());
    }

    // ----------------------------------------------------------------- helpers

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Resumo ' . uniqid());
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
        $user = $this->novoUsuario('gestor');

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
        $em   = $this->em();
        $user = $this->novoUsuario('colab');

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

    private function conceder(User $user, Tenant $tenant, string $tipo, int $recursoId): void
    {
        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setTenant($tenant);
        $ra->setResourceType($tipo);
        $ra->setResourceId($recursoId);
        $ra->setCanView(true);
        $this->em()->persist($ra);
        $this->em()->flush();
    }

    private function criarClientePF(Tenant $tenant, string $nome): ClientePF
    {
        $n = ++$this->seq;

        $cliente = new ClientePF();
        $cliente->setNomeCompleto($nome);
        // CPF de 11 dígitos, distinto por teste (unicidade cpf+tenant).
        $cliente->setCpf('1234567890' . $n);
        $cliente->setRg('123456' . $n);
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('resumo_' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function criarPasta(Tenant $tenant, User $criador, ClientePF $cliente, string $nup, string $acao): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup . '-' . strtoupper(substr(uniqid(), -5)));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $pasta->setResponsavel($criador);
        $pasta->setNomeAcao($acao);
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
