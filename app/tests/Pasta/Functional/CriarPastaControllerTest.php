<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(PastaController::class)]
final class CriarPastaControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Criar ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_criar_' . uniqid() . '@test.com');
        $user->setFullName('Admin Criar');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        return [$user, $tenant];
    }

    private function criarUsuarioSemModuloPastas(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Restrito ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_restrito_' . uniqid() . '@test.com');
        $user->setFullName('User Restrito');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // UserTenant sem TenantRole → canAccessModule retorna false
        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        return [$user, $tenant];
    }

    #[TestDox('POST /nova com NUP válido cria pasta e redireciona para pasta_show')]
    public function testSucessoCriaPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $nup             = 'NUP-OK-' . strtoupper(uniqid());

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', [
            'nup'          => $nup,
            'nome_cliente' => 'Cliente Teste',
            'nome_acao'    => 'Ação Teste',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/pasta/\d+$#', $location);

        preg_match('#/pasta/(\d+)$#', $location, $m);
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = $em->find(Pasta::class, (int) $m[1]);

        self::assertNotNull($pasta);
        self::assertSame($tenant->getId(), $pasta->getTenant()->getId());
        self::assertSame($user->getId(), $pasta->getCriadoPor()->getId());
        self::assertSame('CLIENTE TESTE', $pasta->getNomeCliente());
        self::assertSame('AÇÃO TESTE', $pasta->getNomeAcao());
    }

    #[TestDox('POST /nova com NUP em branco agora GERA o número automaticamente (R1)')]
    public function testNupEmBrancoGeraNumeroAutomatico(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nup' => '   ']);

        // Antes do R1 isto era erro ("O NUP é obrigatório") e voltava para o expediente sem
        // criar nada. Agora o sistema atribui o próximo número livre do escritório.
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/pasta/\d+$#', $location);

        preg_match('#/pasta/(\d+)$#', $location, $m);
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = $em->find(Pasta::class, (int) $m[1]);

        self::assertNotNull($pasta);
        self::assertSame($tenant->getId(), $pasta->getTenant()->getId());
        // Escritório novo, sem nenhuma pasta: o primeiro número é 1.
        self::assertSame('1', $pasta->getNup());
    }

    #[TestDox('Números gerados não se repetem dentro do mesmo escritório')]
    public function testNumerosGeradosNaoSeRepetem(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);

        $nups = [];
        for ($i = 0; $i < 3; $i++) {
            $client->request('POST', '/pasta/nova', ['nup' => '']);
            self::assertResponseRedirects();
            preg_match('#/pasta/(\d+)$#', (string) $client->getResponse()->headers->get('Location'), $m);
            $em    = static::getContainer()->get(EntityManagerInterface::class);
            $pasta = $em->find(Pasta::class, (int) $m[1]);
            $nups[] = $pasta->getNup();
        }

        self::assertSame(['1', '2', '3'], $nups);
    }

    #[TestDox('R1: o número enviado pelo POST é IGNORADO — a tela não escolhe mais o número')]
    public function testNumeroEnviadoPeloPostEhIgnorado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $nupForjado      = 'NUP-DUP-' . strtoupper(uniqid());

        $this->logarComTenant($client, $user, $tenant);

        // O campo saiu do modal, mas o endpoint continua aceitando POST livre: alguém pode
        // reenviar `nup` à mão (curl, formulário salvo, extensão). Este teste fixa que isso não
        // reabre a porta da colisão — o servidor não olha para o valor.
        $client->request('POST', '/pasta/nova', ['nup' => $nupForjado]);
        self::assertResponseRedirects();
        $client->request('POST', '/pasta/nova', ['nup' => $nupForjado]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(
            0,
            $em->getRepository(Pasta::class)->findBy(['nup' => $nupForjado]),
            'o número veio do POST — a tela voltou a escolher o número e a colisão reabriu',
        );

        $nups = array_map(
            static fn (Pasta $p): ?string => $p->getNup(),
            $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant], ['id' => 'ASC']),
        );
        self::assertSame(['1', '2'], $nups);
    }

    // ───────────────────────────────────────────────────────────────────────────────────────
    // D12.5 — aviso de pasta duplicada. A numeração automática matou a colisão de NÚMERO, não a
    // pasta duplicada: duas pessoas abrindo o mesmo caso ao mesmo tempo ganham 1232 e 1233, e
    // somem da consulta que procura NUP repetido (§12.4). Este aviso é o que enxerga isso.
    // ───────────────────────────────────────────────────────────────────────────────────────

    #[TestDox('D12.5: cliente+ação já existentes mostram AVISO e NÃO criam a pasta ainda')]
    public function testClienteEAcaoRepetidosAvisamAntesDeCriar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'JORGE TEIXEIRA', 'nome_acao' => 'EXECUÇÃO']);
        self::assertResponseRedirects();

        // Segunda tentativa idêntica: não redireciona, mostra a tela de confirmação.
        $crawler = $client->request('POST', '/pasta/nova', ['nome_cliente' => 'JORGE TEIXEIRA', 'nome_acao' => 'EXECUÇÃO']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Já existe uma pasta parecida', $crawler->filter('body')->text());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(
            1,
            $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant]),
            'a segunda pasta foi criada sem o usuário confirmar',
        );
    }

    #[TestDox('D12.5: o aviso é tolerante a acento e caixa')]
    public function testAvisoIgnoraAcentoECaixa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'JOSÉ RICARDO', 'nome_acao' => 'DIVÓRCIO']);
        self::assertResponseRedirects();

        // Mesmo caso, digitado sem acento e em caixa baixa — é a mesma pasta para quem digita.
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'jose ricardo', 'nome_acao' => 'divorcio']);

        self::assertResponseIsSuccessful('acento/caixa passaram batido — o aviso não pegou a duplicata');
        self::assertCount(1, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('D12.5: com confirmar=1 a pasta é criada mesmo assim (aviso NÃO bloqueia)')]
    public function testConfirmarCriaMesmoAssim(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'MESMO CLIENTE', 'nome_acao' => 'MESMA ACAO']);
        self::assertResponseRedirects();

        $client->request('POST', '/pasta/nova', [
            'nome_cliente' => 'MESMO CLIENTE',
            'nome_acao'    => 'MESMA ACAO',
            'confirmar'    => '1',
        ]);

        self::assertResponseRedirects();
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $todas = $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant], ['id' => 'ASC']);

        self::assertCount(2, $todas, 'o aviso virou bloqueio — o dono pediu aviso, não trava');
        self::assertSame(['1', '2'], array_map(static fn (Pasta $p): ?string => $p->getNup(), $todas));
    }

    #[TestDox('D12.5: cliente diferente NÃO dispara aviso')]
    public function testClienteDiferenteNaoAvisa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'CLIENTE A', 'nome_acao' => 'COBRANÇA']);
        self::assertResponseRedirects();

        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'CLIENTE B', 'nome_acao' => 'COBRANÇA']);

        self::assertTrue(
            $client->getResponse()->isRedirect(),
            'o aviso disparou para cliente diferente — vira ruído e o usuário aprende a ignorar',
        );
        self::assertCount(2, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('D12.5: mesma ação para o mesmo cliente, mas ação diferente, NÃO avisa')]
    public function testAcaoDiferenteNaoAvisa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'CLIENTE UNICO', 'nome_acao' => 'EXECUÇÃO']);
        self::assertResponseRedirects();

        // Mesmo cliente pode ter vários casos — é legítimo e não pode incomodar.
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'CLIENTE UNICO', 'nome_acao' => 'DIVÓRCIO']);

        self::assertResponseRedirects();
        self::assertCount(2, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('D12.5: sem cliente informado NÃO avisa (senão casaria com toda pasta sem cliente)')]
    public function testSemClienteNaoAvisa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', []);
        self::assertResponseRedirects();

        $client->request('POST', '/pasta/nova', []);

        self::assertResponseRedirects();
        self::assertCount(2, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('ISOLAMENTO: pasta igual em OUTRO escritório não dispara aviso aqui')]
    public function testAvisoNaoVazaEntreEscritorios(): void
    {
        $client                  = static::createClient();
        [$user, $tenant]         = $this->criarUsuarioAdmin();
        [$outroUser, $outroTen]  = $this->criarUsuarioAdmin();

        // O vizinho cria a pasta primeiro.
        $this->logarComTenant($client, $outroUser, $outroTen);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'CLIENTE COMPARTILHADO', 'nome_acao' => 'COBRANÇA']);
        self::assertResponseRedirects();

        // No meu escritório é pasta nova — o aviso não pode enxergar a do vizinho.
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'CLIENTE COMPARTILHADO', 'nome_acao' => 'COBRANÇA']);

        self::assertTrue(
            $client->getResponse()->isRedirect(),
            'o aviso vazou entre escritórios — vazamento de existência de cliente',
        );
        self::assertCount(1, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('POST /nova sem módulo pastas redireciona para expediente_index sem criar pasta')]
    public function testSemModuloPastasRedireciona(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioSemModuloPastas();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nup' => 'NUP-SEM-MOD-' . uniqid()]);

        self::assertResponseRedirects();
        self::assertStringContainsString('expediente', (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    // ───────────────────────────────────────────────────────────────────────────────────────
    // D12.5 — caminho AJAX. O aviso virou MODAL: a modal de nova pasta envia por fetch e o
    // controller devolve JSON {status: ok|duplicada|erro}. Sem JS, o POST cai no fallback de
    // página (coberto pelos testes acima). Aqui exercitamos o JSON.
    // ───────────────────────────────────────────────────────────────────────────────────────

    #[TestDox('D12.5 AJAX: cliente+ação repetidos devolvem JSON status=duplicada com o HTML da modal')]
    public function testAjaxDuplicadaDevolveJsonComModal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'JORGE TEIXEIRA', 'nome_acao' => 'EXECUÇÃO']);
        self::assertResponseRedirects();

        $client->request(
            'POST',
            '/pasta/nova',
            ['nome_cliente' => 'JORGE TEIXEIRA', 'nome_acao' => 'EXECUÇÃO'],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
        self::assertJson((string) $client->getResponse()->getContent());
        $json = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('duplicada', $json['status']);
        self::assertStringContainsString('Já existe uma pasta parecida', $json['html']);
        self::assertStringContainsString('Criar mesmo assim', $json['html']);

        // Não criou a segunda pasta sem o usuário confirmar.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('D12.5 AJAX: caso novo devolve JSON status=ok com a URL de redirecionamento')]
    public function testAjaxCriacaoDevolveJsonComRedirect(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request(
            'POST',
            '/pasta/nova',
            ['nome_cliente' => 'CLIENTE NOVO', 'nome_acao' => 'COBRANÇA'],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('ok', $json['status']);
        self::assertStringContainsString('/pasta/', (string) $json['redirect']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $criadas = $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant]);
        self::assertCount(1, $criadas);
        // O Expediente fica na lista e avisa "Pasta N aberta para CLIENTE" (desenho 03, dc `salvarNovaFn`).
        self::assertSame($criadas[0]->getNup(), $json['nup']);
        self::assertSame('CLIENTE NOVO', $json['cliente']);
    }

    #[TestDox('D12.5 AJAX: confirmar=1 cria mesmo assim e devolve status=ok (aviso NÃO bloqueia)')]
    public function testAjaxConfirmarCriaMesmoAssim(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nome_cliente' => 'MESMO CLIENTE', 'nome_acao' => 'MESMA ACAO']);
        self::assertResponseRedirects();

        $client->request(
            'POST',
            '/pasta/nova',
            ['nome_cliente' => 'MESMO CLIENTE', 'nome_acao' => 'MESMA ACAO', 'confirmar' => '1'],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('ok', $json['status']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(2, $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('D12.5 AJAX: sem permissão no módulo pastas devolve JSON status=erro com HTTP 403')]
    public function testAjaxSemPermissaoDevolveJson403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioSemModuloPastas();

        $this->logarComTenant($client, $user, $tenant);
        $client->request(
            'POST',
            '/pasta/nova',
            ['nome_cliente' => 'QUALQUER', 'nome_acao' => 'COISA'],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(403);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('erro', $json['status']);

        // Continua sem criar nada — a modal recebe o erro, não uma pasta.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }

    // ── Mesmo cadastro (desenho 03, L276) ───────────────────────────────

    #[TestDox('nome de cliente com um cadastro só por trás: a nova pasta entra ligada ao mesmo cadastro')]
    public function testNovaPastaVinculadaAoMesmoCadastro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $cadastro        = $this->novoCliente($tenant, 'Joana Prado');
        $this->pastaDoCliente($tenant, 'JOANA PRADO', $cadastro);
        $this->pastaDoCliente($tenant, 'JOANA PRADO', null);

        $this->logarComTenant($client, $user, $tenant);
        $nova = $this->criarPeloModal($client, '  joana prado ');

        self::assertSame([$cadastro->getId()], $this->idsDosClientes($nova));
        self::assertSame($cadastro->getId(), $nova->getClientePrincipal()?->getId());
    }

    #[TestDox('homônimos (dois cadastros com o mesmo nome de pasta) não ligam a nova pasta a nenhum')]
    public function testHomonimosNaoVinculam(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->pastaDoCliente($tenant, 'JOSE SILVA', $this->novoCliente($tenant, 'José Silva'));
        $this->pastaDoCliente($tenant, 'JOSE SILVA', $this->novoCliente($tenant, 'José Silva'));

        $this->logarComTenant($client, $user, $tenant);

        self::assertSame([], $this->idsDosClientes($this->criarPeloModal($client, 'JOSE SILVA')));
    }

    #[TestDox('nome usado só como texto, sem cadastro, e o cadastro de outro escritório não ligam nada')]
    public function testSemCadastroOuDeOutroEscritorioNaoVincula(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $this->pastaDoCliente($tenant, 'MARIA SO TEXTO', null);
        $this->pastaDoCliente($outro, 'CARLOS DE FORA', $this->novoCliente($outro, 'Carlos de Fora'));

        $this->logarComTenant($client, $user, $tenant);

        self::assertSame([], $this->idsDosClientes($this->criarPeloModal($client, 'MARIA SO TEXTO')));
        self::assertSame([], $this->idsDosClientes($this->criarPeloModal($client, 'CARLOS DE FORA')));
    }

    private function novoCliente(Tenant $tenant, string $nome): ClientePF
    {
        return ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => $nome])->_real();
    }

    private function pastaDoCliente(Tenant $tenant, string $nomeCliente, ?ClientePF $cadastro): void
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('CAD-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setNomeCliente($nomeCliente);
        if ($cadastro !== null) {
            $pasta->addCliente($em->getReference(ClientePF::class, $cadastro->getId()));
        }
        $em->persist($pasta);
        $em->flush();
    }

    private function criarPeloModal(KernelBrowser $client, string $nomeCliente): Pasta
    {
        $client->xmlHttpRequest('POST', '/pasta/nova', [
            'nome_cliente' => $nomeCliente,
            'nome_acao'    => 'Ação ' . uniqid(),
            'confirmar'    => '1',
        ]);
        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        preg_match('#/pasta/(\d+)$#', (string) $dados['redirect'], $m);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->find(Pasta::class, (int) $m[1]);
        self::assertNotNull($pasta);

        return $pasta;
    }

    /** @return list<int> */
    private function idsDosClientes(Pasta $pasta): array
    {
        return array_values(array_map(static fn ($c): int => (int) $c->getId(), $pasta->getClientes()->toArray()));
    }
}
