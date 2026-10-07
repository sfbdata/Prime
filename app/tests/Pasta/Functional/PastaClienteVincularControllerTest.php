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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Vincular cliente à pasta por XHR (`pasta_cliente_vincular`) — item 6 das pendências da Trilha B:
 * a resposta traz o `html` da linha, renderizado pelo MESMO `pasta/_cliente_linha.html.twig` da
 * página, para a linha nova entrar sem recarregar e COM o ícone de cadastro, como as existentes.
 *
 * As guardas (CSRF, permissão, tenant, duplicado) são as de antes; o que se prova aqui é que
 * continuam valendo e que em erro não sai linha nenhuma (sem `html`) nem vínculo gravado.
 * A cadastro-e-vincula (`pasta_cliente_novo`) devolve o mesmo `html` — último teste.
 */
#[CoversClass(PastaController::class)]
final class PastaClienteVincularControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        return $this->criarUsuario(['ROLE_SUPER_ADMIN'], 'admin_cli_vinc');
    }

    /** Usuário com vínculo no escritório mas sem papel — sem `resources.pasta.edit`. @return array{User, Tenant} */
    private function criarUsuarioSemPermissao(): array
    {
        return $this->criarUsuario(['ROLE_USER'], 'sem_perm_cli_vinc');
    }

    /** @param list<string> $roles @return array{User, Tenant} */
    private function criarUsuario(array $roles, string $prefixo): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Cliente Vincular ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail("test_{$prefixo}_" . uniqid() . '@test.com');
        $user->setFullName('Usuario ' . $prefixo);
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-CV-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

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

    private function tokenVincular(Pasta $pasta): string
    {
        return 'TOKEN_pasta_cliente_vincular_' . $pasta->getId();
    }

    /** @return array<string, mixed> */
    private function respostaJson(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados, 'a resposta é JSON');

        return $dados;
    }

    private function contarVinculos(Pasta $pasta): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($pasta);

        return $pasta->getClientes()->count();
    }

    // ─────────────────────── o que a feature faz ───────────────────────

    #[TestDox('Vincular devolve o html da linha COM o ícone de cadastro, a estrela vazia e a contagem')]
    public function testDevolveHtmlDaLinhaComIcone(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $pasta           = $this->criarPasta($tenant);
        $primeiro        = $this->novoCliente($tenant, 'Antonio Primeiro');
        $novo            = $this->novoCliente($tenant, 'Bruna Segunda');
        $pasta->addCliente($primeiro);
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/vincular", [
            '_token'     => $this->tokenVincular($pasta),
            'cliente_id' => (string) $novo->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $dados = $this->respostaJson($client);
        self::assertTrue($dados['sucesso']);
        self::assertSame(2, $dados['total'], 'a contagem do cabeçalho do cartão');
        self::assertSame((int) $primeiro->getId(), (int) $dados['principal']['clienteId'], 'o principal não mudou');
        self::assertIsString($dados['html']);

        $linha = (new Crawler($dados['html']))->filter('.cliente-linha');
        self::assertCount(1, $linha, 'uma linha, a do partial');
        self::assertSame((string) $novo->getId(), $linha->attr('data-cliente-id'));
        self::assertNotSame('', (string) $linha->attr('data-token-principal'), 'o token de marcar principal vai na linha');

        // O ícone que faltava na linha montada pelo espelho JS (B17 / L7).
        $icone = $linha->filter('.cliente-nome > button.cliente-cad.js-ps-cli-detalhes');
        self::assertCount(1, $icone, 'a linha nova vem com o ícone de cadastro');
        self::assertCount(1, $icone->filter('i.bi-person-vcard'));
        self::assertMatchesRegularExpression('/cliente-cad--(completo|pendente)/', (string) $icone->attr('class'));
        self::assertNotSame('', (string) $icone->attr('title'), 'o title diz o que falta');

        // Não é o principal: estrela vazia (form de marcar), sem selo.
        self::assertCount(0, $linha->filter('.ps-selo-principal'));
        self::assertCount(0, $linha->filter('.js-cliente-principal-estrela'));
        self::assertCount(1, $linha->filter('form.js-ajax-cliente-principal'));
        self::assertCount(1, $linha->filter('form.js-ajax-desvincular-cliente input[name="_token"]'));
        self::assertStringContainsString('BRUNA SEGUNDA', $linha->filter('.cliente-nome-texto')->text());

        self::assertSame(2, $this->contarVinculos($pasta));
    }

    #[TestDox('Primeiro cliente da pasta: a linha vem como principal (estrela cheia + selo)')]
    public function testPrimeiroClienteVemComoPrincipal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Carla Unica');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/vincular", [
            '_token'     => $this->tokenVincular($pasta),
            'cliente_id' => (string) $cliente->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $dados = $this->respostaJson($client);
        self::assertSame(1, $dados['total']);
        self::assertSame((int) $cliente->getId(), (int) $dados['principal']['clienteId']);

        $linha = (new Crawler($dados['html']))->filter('.cliente-linha');
        self::assertCount(1, $linha->filter('.ps-selo-principal'));
        self::assertCount(1, $linha->filter('.js-cliente-principal-estrela i.bi-star-fill'));
        self::assertCount(0, $linha->filter('form.js-ajax-cliente-principal'));
        self::assertCount(1, $linha->filter('button.cliente-cad i.bi-person-vcard'));
    }

    #[TestDox('O nome entra escapado pelo Twig: HTML no nome não vira marcação')]
    public function testNomeEscapadoNoHtml(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Zeca <img src=x onerror=alert(1)>');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/vincular", [
            '_token'     => $this->tokenVincular($pasta),
            'cliente_id' => (string) $cliente->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->respostaJson($client)['html'];
        // O setter grava em maiúsculas: compara sem caixa.
        self::assertStringNotContainsStringIgnoringCase('<img', $html);
        self::assertStringContainsStringIgnoringCase('&lt;img', $html);
        self::assertCount(0, (new Crawler($html))->filter('img'));
    }

    // ─────────────────────────── guardas ───────────────────────────

    #[TestDox('CSRF inválido: 400, sem html e nada vinculado')]
    public function testCsrfInvalido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Davi Csrf');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/vincular", [
            '_token'     => 'token-errado',
            'cliente_id' => (string) $cliente->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayNotHasKey('html', $this->respostaJson($client));
        self::assertSame(0, $this->contarVinculos($pasta));
    }

    #[TestDox('Sem permissão de edição: 403, sem html e nada vinculado')]
    public function testSemPermissao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioSemPermissao();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Eva Sem Perm');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/vincular", [
            '_token'     => $this->tokenVincular($pasta),
            'cliente_id' => (string) $cliente->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
        self::assertArrayNotHasKey('html', $this->respostaJson($client));
        self::assertSame(0, $this->contarVinculos($pasta));
    }

    #[TestDox('Cliente de OUTRO escritório: 404, sem html e nada vinculado')]
    public function testClienteDeOutroTenant(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outroTenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $pasta           = $this->criarPasta($tenant);
        $alheio          = $this->novoCliente($outroTenant, 'Fabio Alheio');
        $idPasta         = (int) $pasta->getId();
        $idAlheio        = (int) $alheio->getId();
        $token           = $this->tokenVincular($pasta);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        // Sem o clear() o cliente alheio ficaria no cache de objetos do Doctrine e o `find` nem
        // chegaria ao banco — o filtro de tenant não teria o que filtrar e o teste passaria mesmo
        // com o código vazando.
        $em->clear();

        $client->request('POST', "/pasta/{$idPasta}/cliente/vincular", [
            '_token'     => $token,
            'cliente_id' => (string) $idAlheio,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(404);
        $dados = $this->respostaJson($client);
        self::assertArrayNotHasKey('html', $dados);
        self::assertStringNotContainsStringIgnoringCase('FABIO', (string) $client->getResponse()->getContent(), 'nada do cliente alheio vaza');

        $pastaRelida = $em->getRepository(Pasta::class)->find($idPasta);
        self::assertNotNull($pastaRelida);
        self::assertSame(0, $pastaRelida->getClientes()->count());
    }

    #[TestDox('Pasta de OUTRO escritório: 404')]
    public function testPastaDeOutroTenant(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outroTenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $pastaAlheia     = $this->criarPasta($outroTenant);
        $clienteAlheio   = $this->novoCliente($outroTenant, 'Gil Alheio');
        $idPasta         = (int) $pastaAlheia->getId();
        $idCliente       = (int) $clienteAlheio->getId();
        $token           = $this->tokenVincular($pastaAlheia);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $em->clear();

        $client->request('POST', "/pasta/{$idPasta}/cliente/vincular", [
            '_token'     => $token,
            'cliente_id' => (string) $idCliente,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('cliente-linha', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Cliente JÁ vinculado: 422, sem html e sem vínculo em dobro')]
    public function testDuplicado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Helena Repetida');
        $pasta->addCliente($cliente);
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/vincular", [
            '_token'     => $this->tokenVincular($pasta),
            'cliente_id' => (string) $cliente->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
        $dados = $this->respostaJson($client);
        self::assertArrayNotHasKey('html', $dados);
        self::assertSame('Este cliente já está vinculado à pasta.', $dados['erro']);
        self::assertSame(1, $this->contarVinculos($pasta));
    }

    // ─────────────── cadastrar e vincular (modal) ───────────────

    #[TestDox('Cadastrar e vincular pelo modal também devolve o html da linha com o ícone')]
    public function testCadastrarEVincularDevolveHtml(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/novo", [
            '_token'           => 'TOKEN_pasta_cliente_novo_' . $pasta->getId(),
            'tipo'             => 'pf',
            'nomeCompleto'     => 'Iara Cadastrada',
            'cpf'              => '39053344705',
            'rg'               => '123456',
            'rgOrgaoExpedidor' => 'SSP/SP',
            'email'            => 'iara_' . uniqid() . '@test.com',
            'cep'              => '01310100',
            'endereco'         => 'Av. Paulista, 1000',
            'cidade'           => 'São Paulo',
            'estado'           => 'SP',
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $dados = $this->respostaJson($client);
        self::assertTrue($dados['sucesso']);
        self::assertSame(1, $dados['total']);

        $linha = (new Crawler($dados['html']))->filter('.cliente-linha');
        self::assertCount(1, $linha);
        self::assertSame((string) $dados['cliente']['id'], $linha->attr('data-cliente-id'));
        self::assertCount(1, $linha->filter('button.cliente-cad.js-ps-cli-detalhes i.bi-person-vcard'));
        self::assertCount(1, $linha->filter('.ps-selo-principal'), 'primeiro cliente da pasta = principal');
    }
}
