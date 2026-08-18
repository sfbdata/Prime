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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Marcar o cliente principal da pasta — a rota, as guardas, e o efeito que interessa.
 *
 * O teste que prova a feature não é o que grava a coluna: é o que abre a TELA e confere que o
 * número da "Média por CPF" passou a ser o do cliente escolhido. Gravar sem mudar a tela seria
 * entregar uma marcação decorativa.
 */
#[CoversClass(PastaController::class)]
final class PastaClientePrincipalControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        return $this->criarUsuario(['ROLE_SUPER_ADMIN'], 'admin_cli_princ');
    }

    /** Usuário com vínculo no escritório mas sem papel — sem `resources.pasta.edit`. @return array{User, Tenant} */
    private function criarUsuarioSemPermissao(): array
    {
        return $this->criarUsuario(['ROLE_USER'], 'sem_perm_cli_princ');
    }

    /** @param list<string> $roles @return array{User, Tenant} */
    private function criarUsuario(array $roles, string $prefixo): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Cliente Principal ' . uniqid());
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

    private function criarPasta(Tenant $tenant, ?string $valorCausa = null): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-CP-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setValorCausa($valorCausa);
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

    private function gerarCsrf(Pasta $pasta, ClientePF $cliente): string
    {
        return 'TOKEN_pasta_cliente_principal_' . $pasta->getId() . '_' . $cliente->getId();
    }

    // ─────────────────────────── guardas ───────────────────────────

    #[TestDox('Sem autenticação redireciona para o login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client          = static::createClient();
        [, $tenant]      = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Fulano Sem Auth');
        $pasta->addCliente($cliente);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/principal");

        self::assertResponseRedirects();
    }

    #[TestDox('GET numa rota POST devolve 405')]
    public function testGetEmRotaPostRetorna405(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Fulano Metodo');
        $pasta->addCliente($cliente);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/principal");

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('CSRF inválido retorna 400 e NÃO grava a marcação')]
    public function testCsrfInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Fulano Csrf');
        $pasta->addCliente($cliente);
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/principal", [
            '_token' => 'token-errado',
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(400);
        $em->refresh($pasta);
        // Nao gravou = o principal continua sendo o primeiro vinculado. Com a regra nova nao
        // existe pasta com cliente e sem principal, entao assertNull() aqui passaria sempre.
        self::assertSame((int) $cliente->getId(), (int) $pasta->getClientePrincipal()?->getId());
    }

    #[TestDox('Usuário sem permissão de edição retorna 403 e NÃO grava')]
    public function testSemPermissaoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioSemPermissao();
        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->novoCliente($tenant, 'Fulano Sem Perm');
        $pasta->addCliente($cliente);
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$cliente->getId()}/principal", [
            '_token' => $this->gerarCsrf($pasta, $cliente),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
        $em->refresh($pasta);
        // Nao gravou = o principal continua sendo o primeiro vinculado. Com a regra nova nao
        // existe pasta com cliente e sem principal, entao assertNull() aqui passaria sempre.
        self::assertSame((int) $cliente->getId(), (int) $pasta->getClientePrincipal()?->getId());
    }

    #[TestDox('Cliente NÃO vinculado à pasta é recusado com 422 e nada é gravado')]
    public function testClienteNaoVinculadoRetorna422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $pasta           = $this->criarPasta($tenant);
        $vinculado       = $this->novoCliente($tenant, 'Fulano Vinculado');
        $deFora          = $this->novoCliente($tenant, 'Fulano De Fora');
        $pasta->addCliente($vinculado);
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$deFora->getId()}/principal", [
            '_token' => $this->gerarCsrf($pasta, $deFora),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
        $em->refresh($pasta);
        // Nao gravou = o principal continua sendo o primeiro vinculado. Com a regra nova nao
        // existe pasta com cliente e sem principal, entao assertNull() aqui passaria sempre.
        self::assertSame((int) $vinculado->getId(), (int) $pasta->getClientePrincipal()?->getId());
    }

    #[TestDox('Pasta de OUTRO escritório não é encontrada')]
    public function testPastaDeOutroTenantNaoEncontrada(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outroTenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $pastaAlheia = $this->criarPasta($outroTenant);
        $clienteAlheio = $this->novoCliente($outroTenant, 'Fulano Alheio');
        $pastaAlheia->addCliente($clienteAlheio);
        $em->flush();

        $idPasta   = (int) $pastaAlheia->getId();
        $idCliente = (int) $clienteAlheio->getId();
        $token     = $this->gerarCsrf($pastaAlheia, $clienteAlheio);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        // O clear() não é ritual: sem ele a pasta criada no setup fica no cache de objetos do
        // Doctrine, o `find` nem chega ao banco e o filtro de tenant não tem o que filtrar — o
        // teste passaria mesmo com o código vazando. Em produção cada request começa sem cache.
        $em->clear();

        $client->request('POST', "/pasta/{$idPasta}/cliente/{$idCliente}/principal", [
            '_token' => $token,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(404);
    }

    // ─────────────────────── o que a feature faz ───────────────────────

    #[TestDox('Marca o cliente e devolve a média JÁ recalculada — dele, não do anterior')]
    public function testMarcaEDevolveMediaRecalculada(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $antigo  = $this->novoCliente($tenant, 'Antonio Antigo');
        $recente = $this->novoCliente($tenant, 'Zulmira Recente');
        self::assertLessThan($recente->getId(), $antigo->getId(), 'o cenário depende da ordem de id');

        $pasta = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);

        // Outra pasta só da Zulmira, para a média dela diferir da do Antonio.
        $daZulmira = $this->criarPasta($tenant, '90000.00');
        $daZulmira->addCliente($recente);
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/{$recente->getId()}/principal", [
            '_token' => $this->gerarCsrf($pasta, $recente),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($dados['sucesso']);
        self::assertSame('R$ 50.000,00', $dados['mediaFormatada'], 'média da Zulmira: 10.000 e 90.000');
        self::assertSame('ZULMIRA RECENTE', $dados['clienteNome']);

        $em->refresh($pasta);
        self::assertSame($recente->getId(), $pasta->getClientePrincipal()?->getId());
    }

    #[TestDox('A TELA passa a mostrar a média do cliente marcado — a prova da feature')]
    public function testTelaMostraAMediaDoClienteMarcado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $antigo  = $this->novoCliente($tenant, 'Antonio Antigo');
        $recente = $this->novoCliente($tenant, 'Zulmira Recente');

        $pasta = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);

        $outraDoAntonio = $this->criarPasta($tenant, '30000.00');
        $outraDoAntonio->addCliente($antigo);
        $daZulmira = $this->criarPasta($tenant, '90000.00');
        $daZulmira->addCliente($recente);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        // ANTES: sem marcação, manda o de cadastro mais antigo (10.000 e 30.000).
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertSame('R$ 20.000,00', trim($crawler->filter('#financeiro-media-cpf')->text()));
        self::assertStringContainsString('ANTONIO ANTIGO', $crawler->filter('.financeiro-faixa')->text());

        // Marca a Zulmira.
        $pasta->definirClientePrincipal($recente);
        $em->flush();

        // DEPOIS: a tela mostra a média DELA (10.000 e 90.000).
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertSame(
            'R$ 50.000,00',
            trim($crawler->filter('#financeiro-media-cpf')->text()),
            'a média exibida tem de seguir a marcação, não a ordem de cadastro'
        );
        self::assertStringContainsString('ZULMIRA RECENTE', $crawler->filter('.financeiro-faixa')->text());
        self::assertStringNotContainsString('ANTONIO ANTIGO', $crawler->filter('.financeiro-faixa')->text());
    }

    #[TestDox('A REGRESSÃO QUE A FEATURE MATA: vincular depois um cliente mais antigo não muda a tela')]
    public function testVincularClienteMaisAntigoNaoTrocaONumeroNaTela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        // O escolhido é cadastrado PRIMEIRO aqui; o intruso vem depois, mas o cenário
        // é montado para que o intruso tenha id MENOR não ser possível — então em vez
        // disso criamos o intruso antes e marcamos o outro, que é o caso real.
        $intruso   = $this->novoCliente($tenant, 'Intruso Antigo');
        $escolhido = $this->novoCliente($tenant, 'Escolhido Dono');
        self::assertLessThan($escolhido->getId(), $intruso->getId());

        $pasta = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($escolhido);
        $pasta->definirClientePrincipal($escolhido);

        $doEscolhido = $this->criarPasta($tenant, '70000.00');
        $doEscolhido->addCliente($escolhido);
        $doIntruso = $this->criarPasta($tenant, '90000.00');
        $doIntruso->addCliente($intruso);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $antes   = trim($crawler->filter('#financeiro-media-cpf')->text());
        self::assertSame('R$ 40.000,00', $antes, 'média do escolhido: 10.000 e 70.000');

        // Chega o cliente de cadastro MAIS ANTIGO. Pelo critério de antes, ele roubaria a média.
        $pasta->addCliente($intruso);
        $em->flush();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertSame(
            $antes,
            trim($crawler->filter('#financeiro-media-cpf')->text()),
            'vincular um cliente mais antigo NÃO pode trocar o número — era exatamente esse o defeito'
        );
        self::assertStringContainsString('ESCOLHIDO DONO', $crawler->filter('.financeiro-faixa')->text());
    }

    #[TestDox('A estrela cheia marca quem manda, e os outros ganham botão para assumir')]
    public function testTelaTemEstrelaDeEstadoEBotaoDeAcao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $a = $this->novoCliente($tenant, 'Cliente Aaa');
        $b = $this->novoCliente($tenant, 'Cliente Bbb');
        $pasta = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($a);
        $pasta->addCliente($b);
        $pasta->definirClientePrincipal($b);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        // Estado: estrela cheia na linha do principal.
        self::assertCount(
            1,
            $crawler->filter(".cliente-row[data-cliente-id=\"{$b->getId()}\"] .js-cliente-principal-estrela i.bi-star-fill"),
            'o principal tem estrela cheia, e é estado — não botão'
        );
        // Ação: o outro tem o formulário com a estrela vazia.
        self::assertCount(
            1,
            $crawler->filter(".cliente-row[data-cliente-id=\"{$a->getId()}\"] form.js-ajax-cliente-principal button i.bi-star"),
            'quem não é principal tem botão para assumir'
        );
        // E o principal NÃO tem o formulário (não faz sentido marcar quem já é).
        self::assertCount(
            0,
            $crawler->filter(".cliente-row[data-cliente-id=\"{$b->getId()}\"] form.js-ajax-cliente-principal")
        );
    }

    #[TestDox('Desvincular o principal GRAVA o promovido na coluna — conferido no SQL, não pelo getter')]
    public function testDesvincularGravaOPromovidoNaColuna(): void
    {
        static::createClient();
        [, $tenant] = $this->criarUsuarioAdmin();
        $em         = static::getContainer()->get(EntityManagerInterface::class);

        $antigo  = $this->novoCliente($tenant, 'Antonio Coluna');
        $marcado = $this->novoCliente($tenant, 'Zulmira Coluna');
        $pasta   = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($antigo);
        $pasta->addCliente($marcado);
        $pasta->definirClientePrincipal($marcado);
        $em->flush();

        $idPasta = (int) $pasta->getId();

        // `fetchOne` devolve `false` para "não há linha" e `null` para "coluna nula". A primeira
        // versão deste helper achatava os dois em `null` — e aí a asserção decisiva da fatia
        // (`assertNull` depois do desvínculo) passaria também se a PASTA tivesse sumido do banco,
        // que é o oposto do que ela quer provar. A linha ausente agora FALHA o teste.
        $lerColuna = static function () use ($em, $idPasta): ?int {
            $valor = $em->getConnection()->fetchOne(
                'SELECT cliente_principal_id FROM pasta WHERE id = :id',
                ['id' => $idPasta]
            );

            self::assertNotFalse($valor, 'a pasta sumiu do banco — a asserção de coluna limpa não valeria nada');

            return $valor === null ? null : (int) $valor;
        };

        self::assertSame((int) $marcado->getId(), $lerColuna(), 'a marcação tem de estar gravada');

        $pasta->removeCliente($marcado);
        $em->flush();

        // Conferido no SQL de propósito, e não pelo getter: `getClientePrincipal()` tem fallback,
        // então se a promoção NÃO gravasse, o getter ainda devolveria o Antonio e o teste passaria
        // por acidente. A pergunta que importa é se o banco ficou com o valor certo — porque é o
        // banco que impede o número de mudar sozinho na próxima leitura.
        self::assertSame(
            (int) $antigo->getId(),
            $lerColuna(),
            'promover tem de GRAVAR o novo principal, não só deixar o getter respondendo certo'
        );
    }

    #[TestDox('Desvincular o principal PROMOVE quem sobrou — e a tela mostra a média do promovido')]
    public function testDesvincularOPrincipalPromoveQuemSobrou(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $antigo  = $this->novoCliente($tenant, 'Antonio Antigo');
        $recente = $this->novoCliente($tenant, 'Zulmira Recente');
        $pasta   = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);
        $pasta->definirClientePrincipal($recente);

        $outraDoAntonio = $this->criarPasta($tenant, '30000.00');
        $outraDoAntonio->addCliente($antigo);
        $em->flush();

        $pasta->removeCliente($recente);
        $em->flush();
        $em->refresh($pasta);

        self::assertSame(
            (int) $antigo->getId(),
            (int) $pasta->getClientePrincipal()?->getId(),
            'a pasta nao pode ficar com cliente e sem principal — tem de promover quem sobrou'
        );

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertSame('R$ 20.000,00', trim($crawler->filter('#financeiro-media-cpf')->text()));
    }

    #[TestDox('Cliente de OUTRO escritório não pode ser marcado como principal')]
    public function testClienteDeOutroEscritorioNaoPodeSerMarcado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outroTenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $pasta         = $this->criarPasta($tenant);
        $meuCliente    = $this->novoCliente($tenant, 'Fulano Meu');
        $clienteAlheio = $this->novoCliente($outroTenant, 'Fulano Do Outro Escritorio');
        $pasta->addCliente($meuCliente);
        $em->flush();

        $idPasta   = (int) $pasta->getId();
        $idAlheio  = (int) $clienteAlheio->getId();
        $token     = 'TOKEN_pasta_cliente_principal_' . $idPasta . '_' . $idAlheio;

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        // Sem o clear() o cliente alheio fica no cache do Doctrine e o find nem consulta o banco —
        // o teste passaria mesmo com o filtro de tenant desligado. Mesma armadilha do teste de pasta.
        $em->clear();

        $client->request('POST', "/pasta/{$idPasta}/cliente/{$idAlheio}/principal", [
            '_token' => $token,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        // Duas camadas seguram isto: o TenantFilter faz o cliente alheio nem existir (404) e,
        // ainda que existisse, ele não está vinculado à pasta (422). Qualquer uma das duas serve;
        // o que NÃO pode é gravar.
        self::assertContains($client->getResponse()->getStatusCode(), [404, 422]);

        $pastaRecarregada = $em->getRepository(Pasta::class)->find($idPasta);
        self::assertNotNull($pastaRecarregada);
        self::assertSame(
            (int) $meuCliente->getId(),
            (int) $pastaRecarregada->getClientePrincipal()?->getId(),
            'o principal tem de continuar sendo o cliente do proprio escritorio'
        );
    }

}
