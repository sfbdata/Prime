<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * P13: desvincular o cliente PRINCIPAL faz o servidor promover outro (`Pasta::removeCliente`), e a
 * resposta XHR diz QUAL — `novoPrincipalId`, a linha dele re-renderizada (`html`, selo e estrela
 * cheia) e o `principal` com a Média por CPF já recalculada. Sem isso a tela ficava sem principal
 * até o F5.
 */
#[CoversClass(PastaController::class)]
final class PastaClienteDesvincularPrincipalControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    #[TestDox('desvincular o principal: devolve o promovido (id, linha com selo e estrela cheia, média dele)')]
    public function testDesvincularPrincipalDevolveOPromovido(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $em = $this->em();

        $antigo  = $this->novoCliente($tenant, 'Antonio Antigo');
        $recente = $this->novoCliente($tenant, 'Zulmira Recente');
        $pasta   = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);
        // Outra pasta só da Zulmira, para a média dela (10.000 e 90.000) ser reconhecível.
        $this->criarPasta($tenant, '90000.00')->addCliente($recente);
        $em->flush();
        self::assertSame($antigo->getId(), $pasta->getClientePrincipal()?->getId(), 'o cenário começa com o Antonio principal');

        $dados = $this->desvincular($client, $pasta, $antigo);

        self::assertTrue($dados['sucesso']);
        self::assertSame($antigo->getId(), $dados['clienteId']);
        self::assertSame($recente->getId(), $dados['novoPrincipalId']);

        $linha = new Crawler((string) $dados['html']);
        self::assertCount(1, $linha->filter(".cliente-linha[data-cliente-id=\"{$recente->getId()}\"]"), 'a linha é a do promovido');
        self::assertCount(1, $linha->filter('.cliente-nome .ps-selo-principal'), 'com o selo Principal');
        self::assertCount(1, $linha->filter('.js-cliente-principal-estrela i.bi-star-fill'), 'e a estrela cheia');
        self::assertCount(0, $linha->filter('form.js-ajax-cliente-principal'));

        self::assertSame($recente->getId(), $dados['principal']['clienteId']);
        self::assertSame('R$ 50.000,00', $dados['principal']['mediaFormatada']);
        self::assertSame('ZULMIRA RECENTE', $dados['principal']['clienteNome']);

        $em->clear();
        $gravada = $em->find(Pasta::class, $pasta->getId());
        self::assertSame($recente->getId(), $gravada?->getClientePrincipal()?->getId());
    }

    #[TestDox('desvincular quem NÃO é o principal: o principal não muda e a resposta não manda mover nada')]
    public function testDesvincularOutroNaoMexeNoPrincipal(): void
    {
        [$client, , $tenant] = $this->preparar();

        $antigo  = $this->novoCliente($tenant, 'Antonio Antigo');
        $recente = $this->novoCliente($tenant, 'Zulmira Recente');
        $pasta   = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);
        $this->em()->flush();

        $dados = $this->desvincular($client, $pasta, $recente);

        self::assertTrue($dados['sucesso']);
        self::assertNull($dados['novoPrincipalId']);
        self::assertNull($dados['html']);
        self::assertNull($dados['principal']);
    }

    #[TestDox('desvincular o único cliente: ninguém é promovido (novoPrincipalId nulo)')]
    public function testDesvincularUnicoNaoPromoveNinguem(): void
    {
        [$client, , $tenant] = $this->preparar();

        $unico = $this->novoCliente($tenant, 'Unico Cliente');
        $pasta = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($unico);
        $this->em()->flush();

        $dados = $this->desvincular($client, $pasta, $unico);

        self::assertTrue($dados['sucesso']);
        self::assertNull($dados['novoPrincipalId']);
        self::assertNull($dados['principal']);
    }

    #[TestDox('pasta de OUTRO escritório: 404, nada é desvinculado')]
    public function testPastaDeOutroEscritorioNaoEncontrada(): void
    {
        [$client] = $this->preparar();
        [, $outroTenant] = $this->criarUsuarioAdmin();

        $alheio = $this->novoCliente($outroTenant, 'Fulano Alheio');
        $pasta  = $this->criarPasta($outroTenant, null);
        $pasta->addCliente($alheio);
        $this->em()->flush();
        $pastaId   = (int) $pasta->getId();
        $clienteId = (int) $alheio->getId();
        // Sem o clear o find nem chega ao banco e o filtro de tenant não tem o que filtrar.
        $this->em()->clear();

        $client->request('POST', "/pasta/{$pastaId}/cliente/{$clienteId}/desvincular", [
            '_token' => "TOKEN_pasta_cliente_desvincular_{$pastaId}_{$clienteId}",
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = :p AND cliente_id = :c',
            ['p' => $pastaId, 'c' => $clienteId],
        ));
    }

    #[TestDox('cliente que não está na pasta: 422 no XHR e aviso no form, nada muda')]
    public function testClienteNaoVinculado(): void
    {
        [$client, , $tenant] = $this->preparar();

        $vinculado = $this->novoCliente($tenant, 'Vinculado Fica');
        $fora      = $this->novoCliente($tenant, 'Fora Da Pasta');
        $pasta     = $this->criarPasta($tenant, null);
        $pasta->addCliente($vinculado);
        $this->em()->flush();

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$fora->getId()}/desvincular", [
            '_token' => "TOKEN_pasta_cliente_desvincular_{$pasta->getId()}_{$fora->getId()}",
        ], [], self::XHR);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Cliente não vinculado a esta pasta.'], json_decode((string) $client->getResponse()->getContent(), true));

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$fora->getId()}/desvincular", [
            '_token' => "TOKEN_pasta_cliente_desvincular_{$pasta->getId()}_{$fora->getId()}",
        ]);
        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#partes');
        self::assertSame(1, $this->vinculosDaPasta($pasta));
    }

    #[TestDox('form sem XHR: desvincula e volta para a aba Partes')]
    public function testFormSemXhrDesvincula(): void
    {
        [$client, , $tenant] = $this->preparar();

        $cliente = $this->novoCliente($tenant, 'Form Comum');
        $pasta   = $this->criarPasta($tenant, null);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/desvincular", [
            '_token' => "TOKEN_pasta_cliente_desvincular_{$pasta->getId()}_{$cliente->getId()}",
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#partes');
        self::assertSame(0, $this->vinculosDaPasta($pasta));
    }

    #[TestDox('CSRF inválido: 400 no XHR, nada é desvinculado')]
    public function testCsrfInvalido(): void
    {
        [$client, , $tenant] = $this->preparar();

        $cliente = $this->novoCliente($tenant, 'Csrf Errado');
        $pasta   = $this->criarPasta($tenant, null);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/desvincular", ['_token' => 'errado'], [], self::XHR);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->vinculosDaPasta($pasta));
    }

    #[TestDox('sem permissão de editar a pasta: vai para o pedido de acesso e nada é desvinculado')]
    public function testSemPermissao(): void
    {
        [$client, , $tenant] = $this->preparar();

        $cliente = $this->novoCliente($tenant, 'Sem Permissao');
        $pasta   = $this->criarPasta($tenant, null);
        $pasta->addCliente($cliente);
        $this->em()->flush();
        $semPapel = $this->usuarioSemPapel($tenant);
        $this->logarComTenant($client, $semPapel, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/desvincular", [
            '_token' => "TOKEN_pasta_cliente_desvincular_{$pasta->getId()}_{$cliente->getId()}",
        ], [], self::XHR);

        self::assertResponseRedirects();
        self::assertStringContainsString('requestAccess=1', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->vinculosDaPasta($pasta));
    }

    // ── apoio ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function desvincular(KernelBrowser $client, Pasta $pasta, ClientePF $cliente): array
    {
        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/desvincular", [
            '_token' => "TOKEN_pasta_cliente_desvincular_{$pasta->getId()}_{$cliente->getId()}",
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados);

        return $dados;
    }

    /** @return array{KernelBrowser, User, Tenant} */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user, $tenant];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Desvincular Principal ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('desv_princ_' . uniqid() . '@test.com');
        $user->setFullName('Admin Desvincular');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function vinculosDaPasta(Pasta $pasta): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = :p', ['p' => $pasta->getId()]);
    }

    /** Vínculo no escritório sem papel nenhum: sem `resources.pasta.edit`. */
    private function usuarioSemPapel(Tenant $tenant): User
    {
        $em   = $this->em();
        $user = new User();
        $user->setEmail('desv_sem_papel_' . uniqid() . '@test.com');
        $user->setFullName('Sem Papel');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant, ?string $valorCausa): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-DESV-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setValorCausa($valorCausa);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function novoCliente(Tenant $tenant, string $nome): ClientePF
    {
        return ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => $nome])->_real();
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
}
