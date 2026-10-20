<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\OabReviewController;
use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Service\TenantBootstrapService;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Fila de revisão de OAB do Master por inscrição (spec docs/specs/oab-revisao-por-inscricao.md): principal e
 * suplementares, ações pelo id da inscrição. As listas filtram pelo e-mail do advogado do teste para não depender
 * das inscrições que já existam no banco.
 */
#[CoversClass(OabReviewController::class)]
final class OabReviewTest extends JusPrimeWebTestCase
{
    #[TestDox('Usuário comum (não super-admin) com escritório recebe 403 na fila de OAB')]
    public function testNaoSuperAdminRecebe403(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant OAB ' . uniqid());
        $em->persist($tenant);

        $comum = $this->criarUsuario(['ROLE_USER']);
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();

        // Com tenant no contexto, o usuário passa o gate de seleção de escritório e chega ao
        // IsGranted('ROLE_SUPER_ADMIN') do controller → 403 (e não o redirect de "sem tenant").
        $this->logarComTenant($client, $comum, $tenant);
        $client->request('GET', '/admin/platform/oab');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Usuário comum recebe 403 nas três ações por inscrição, e a inscrição não muda')]
    public function testNaoSuperAdminRecebe403NasAcoes(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // vários POST com o mesmo storage de CSRF trocado
        $this->instalarCsrfStorage();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant OAB ' . uniqid());
        $em->persist($tenant);
        $comum = $this->criarUsuario(['ROLE_USER']);
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();

        $this->logarComTenant($client, $comum, $tenant);
        $this->assertAcoesRecusadas($client);
    }

    #[TestDox('Admin de escritório (dono, sem ser super-admin) recebe 403 na fila e nas três ações')]
    public function testAdminDeEscritorioRecebe403(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant OAB ' . uniqid());
        $em->persist($tenant);
        $dono = $this->criarUsuario(['ROLE_USER']);
        // Bootstrap: perfil admin do escritório + vínculo de dono (o mesmo do criar escritório).
        static::getContainer()->get(TenantBootstrapService::class)->bootstrap($tenant, $dono);

        $this->logarComTenant($client, $dono, $tenant);
        $client->request('GET', '/admin/platform/oab');
        self::assertResponseStatusCodeSame(403);

        $this->assertAcoesRecusadas($client);
    }

    #[TestDox('Super-admin enxerga inscrições de usuários de qualquer escritório (identidade global)')]
    public function testSuperAdminVeInscricoesGlobais(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $a = $this->criarAdvogado(StatusOab::NaoVerificada);
        $b = $this->criarAdvogado(StatusOab::Divergente);

        foreach ([$a, $b] as $adv) {
            $client->request('GET', '/admin/platform/oab?status=todas&q=' . urlencode((string) $adv->getEmail()));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString((string) $adv->getEmail(), (string) $client->getResponse()->getContent());
        }
    }

    #[TestDox('A lista mostra a principal e a suplementar do mesmo usuário, cada uma com o seu selo')]
    public function testListaPrincipalESuplementarComSelo(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv = $this->criarAdvogado(StatusOab::Confirmada, '11111', 'SP');
        $this->adicionarSuplementar($adv, 'RJ', StatusOab::NaoVerificada, '22222');

        $crawler = $client->request('GET', '/admin/platform/oab?status=todas&q=' . urlencode((string) $adv->getEmail()));

        self::assertResponseIsSuccessful();
        $linhas = $crawler->filter('table.platform-table tbody tr');
        self::assertCount(2, $linhas);

        $porNumero = [];
        foreach ($linhas as $tr) {
            $texto = (string) $tr->textContent;
            self::assertStringContainsString((string) $adv->getEmail(), $texto);
            self::assertStringContainsString('Fulano Advogado', $texto);
            $porNumero[str_contains($texto, '11111/SP') ? 'principal' : 'suplementar'] = $texto;
        }

        self::assertStringContainsString('PRINCIPAL', $porNumero['principal']);
        self::assertStringNotContainsString('SUPLEMENTAR', $porNumero['principal']);
        self::assertStringContainsString('22222/RJ', $porNumero['suplementar']);
        self::assertStringContainsString('SUPLEMENTAR', $porNumero['suplementar']);
        self::assertSame(1, $crawler->filter('.oab-selo-tipo.text-bg-primary')->count());
        self::assertSame(1, $crawler->filter('.oab-selo-tipo.text-bg-secondary')->count());
        self::assertStringContainsString('2 inscrições', $crawler->filter('.platform-badge')->text());
    }

    #[TestDox('Os filtros separam as inscrições pela situação de cada uma')]
    public function testFiltrosPorSituacaoDaInscricao(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv = $this->criarAdvogado(StatusOab::Confirmada, '11111', 'SP');
        $this->adicionarSuplementar($adv, 'RJ', StatusOab::Divergente, '22222');
        $this->adicionarSuplementar($adv, 'MG', StatusOab::NaoVerificada, '33333');

        $esperado = [
            'pendentes'      => ['22222/RJ', '33333/MG'],
            'nao_verificada' => ['33333/MG'],
            'divergente'     => ['22222/RJ'],
            'confirmada'     => ['11111/SP'],
            'todas'          => ['11111/SP', '22222/RJ', '33333/MG'],
        ];
        $todos = ['11111/SP', '22222/RJ', '33333/MG'];

        foreach ($esperado as $filtro => $numeros) {
            $crawler = $client->request('GET', '/admin/platform/oab?status=' . $filtro . '&q=' . urlencode((string) $adv->getEmail()));
            self::assertResponseIsSuccessful();
            self::assertCount(\count($numeros), $crawler->filter('table.platform-table tbody tr .oab-selo-tipo'), 'filtro ' . $filtro);
            $tabela = $crawler->filter('table.platform-table tbody')->text();
            foreach ($todos as $numero) {
                if (\in_array($numero, $numeros, true)) {
                    self::assertStringContainsString($numero, $tabela, 'filtro ' . $filtro);
                } else {
                    self::assertStringNotContainsString($numero, $tabela, 'filtro ' . $filtro);
                }
            }
        }
    }

    #[TestDox('A busca também acha a inscrição pelo número da suplementar')]
    public function testBuscaPeloNumeroDaSuplementar(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $numero = (string) random_int(700000, 799999);
        $adv    = $this->criarAdvogado(StatusOab::NaoVerificada, '11111', 'SP');
        $this->adicionarSuplementar($adv, 'RJ', StatusOab::NaoVerificada, $numero);

        $crawler = $client->request('GET', '/admin/platform/oab?status=todas&q=' . $numero);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.platform-table tbody tr .oab-selo-tipo'));
        self::assertStringContainsString($numero . '/RJ', $crawler->filter('table.platform-table tbody')->text());
    }

    #[TestDox('Confirmar uma suplementar muda só ela: a principal e o espelho no usuário ficam como estavam; gera auditoria')]
    public function testConfirmarSuplementarMudaSoEla(): void
    {
        $client = static::createClient();
        $admin  = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $this->logar($client, $admin);

        $adv       = $this->criarAdvogado(StatusOab::NaoVerificada);
        $principal = $this->principalDe($adv);
        $sup       = $this->adicionarSuplementar($adv, 'RJ', StatusOab::NaoVerificada);
        $supId     = (int) $sup->getId();

        $crawler = $client->request('GET', '/admin/platform/oab?q=' . urlencode((string) $adv->getEmail()));
        $client->submit($crawler->filter('form[action*="/' . $supId . '/confirmar"]')->form());

        self::assertResponseRedirects();
        $depois = $this->recarregarInscricao($supId);
        self::assertSame(StatusOab::Confirmada, $depois->getStatus());
        self::assertNotNull($depois->getVerificadaEm());
        self::assertSame(StatusOab::NaoVerificada, $this->recarregarInscricao((int) $principal->getId())->getStatus());
        self::assertSame(StatusOab::NaoVerificada, $this->recarregarUsuario($adv)->getOabStatus());

        // Auditoria automática: InscricaoOab é Auditavel → o AuditLogSubscriber registra no flush, com o
        // super-admin logado como ator (quem/quando).
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => InscricaoOab::class,
            'entityId'    => (string) $supId,
            'actorUserId' => $admin->getId(),
        ]);
        self::assertNotNull($log, 'a confirmação deve gerar auditoria automática atribuída ao super-admin');
        self::assertSame('update', $log->getAction());
    }

    #[TestDox('Confirmar a principal espelha a situação no usuário')]
    public function testConfirmarPrincipalEspelhaNoUsuario(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv         = $this->criarAdvogado(StatusOab::Divergente);
        $principalId = (int) $this->principalDe($adv)->getId();
        $sup         = $this->adicionarSuplementar($adv, 'RJ', StatusOab::Divergente);

        $crawler = $client->request('GET', '/admin/platform/oab?status=divergente&q=' . urlencode((string) $adv->getEmail()));
        $client->submit($crawler->filter('form[action*="/' . $principalId . '/confirmar"]')->form());

        self::assertResponseRedirects();
        self::assertSame(StatusOab::Confirmada, $this->recarregarInscricao($principalId)->getStatus());
        $usuario = $this->recarregarUsuario($adv);
        self::assertSame(StatusOab::Confirmada, $usuario->getOabStatus());
        self::assertNotNull($usuario->getOabVerificadaEm());
        self::assertSame(StatusOab::Divergente, $this->recarregarInscricao((int) $sup->getId())->getStatus());
    }

    #[TestDox('Reverter uma suplementar confirmada para Divergente muda só ela')]
    public function testReverterSuplementarParaDivergente(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv         = $this->criarAdvogado(StatusOab::Confirmada);
        $principalId = (int) $this->principalDe($adv)->getId();
        $supId       = (int) $this->adicionarSuplementar($adv, 'RJ', StatusOab::Confirmada)->getId();

        $crawler = $client->request('GET', '/admin/platform/oab?status=confirmada&q=' . urlencode((string) $adv->getEmail()));
        // Duas opções de reverter por inscrição (nao_verificada, divergente); a segunda é "divergente".
        $client->submit($crawler->filter('form[action*="/' . $supId . '/reverter"]')->eq(1)->form());

        self::assertResponseRedirects();
        self::assertSame(StatusOab::Divergente, $this->recarregarInscricao($supId)->getStatus());
        self::assertSame(StatusOab::Confirmada, $this->recarregarInscricao($principalId)->getStatus());
        self::assertSame(StatusOab::Confirmada, $this->recarregarUsuario($adv)->getOabStatus());
    }

    #[TestDox('Reverter a principal para Não verificada espelha no usuário')]
    public function testReverterPrincipalEspelhaNoUsuario(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv         = $this->criarAdvogado(StatusOab::Confirmada);
        $principalId = (int) $this->principalDe($adv)->getId();

        $crawler = $client->request('GET', '/admin/platform/oab?status=confirmada&q=' . urlencode((string) $adv->getEmail()));
        $client->submit($crawler->filter('form[action*="/' . $principalId . '/reverter"]')->eq(0)->form());

        self::assertResponseRedirects();
        self::assertSame(StatusOab::NaoVerificada, $this->recarregarInscricao($principalId)->getStatus());
        self::assertSame(StatusOab::NaoVerificada, $this->recarregarUsuario($adv)->getOabStatus());
    }

    #[TestDox('Re-verificar uma suplementar com backend dormente a deixa Não verificada e não mexe na principal')]
    public function testReverificarSuplementarDormente(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv         = $this->criarAdvogado(StatusOab::Divergente);
        $principalId = (int) $this->principalDe($adv)->getId();
        $supId       = (int) $this->adicionarSuplementar($adv, 'RJ', StatusOab::Divergente)->getId();

        $crawler = $client->request('GET', '/admin/platform/oab?status=divergente&q=' . urlencode((string) $adv->getEmail()));
        $client->submit($crawler->filter('form[action*="/' . $supId . '/reverificar"]')->form());

        self::assertResponseRedirects();
        self::assertSame(StatusOab::NaoVerificada, $this->recarregarInscricao($supId)->getStatus());
        self::assertSame(StatusOab::Divergente, $this->recarregarInscricao($principalId)->getStatus());
        self::assertSame(StatusOab::Divergente, $this->recarregarUsuario($adv)->getOabStatus());

        $client->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'Backend automático indisponível');
    }

    #[TestDox('Confirmar depois que o titular trocou o número (mesmo id) é recusado com aviso, e nada muda')]
    public function testConfirmarRecusaSeInscricaoMudouDepoisDaFila(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv         = $this->criarAdvogado(StatusOab::NaoVerificada, '123', 'RJ');
        $principalId = (int) $this->principalDe($adv)->getId();

        $crawler = $client->request('GET', '/admin/platform/oab?q=' . urlencode((string) $adv->getEmail()));
        $form    = $crawler->filter('form[action*="/' . $principalId . '/confirmar"]')->form();
        self::assertSame(['123', 'RJ'], [$form->get('numero')->getValue(), $form->get('uf')->getValue()]);

        // O titular troca 123/RJ por 999/RJ pelo perfil enquanto a fila está aberta.
        $this->trocarNumero($principalId, '999', 'RJ');

        $client->submit($form);
        self::assertResponseRedirects();
        $depois = $this->recarregarInscricao($principalId);
        self::assertSame(['999', StatusOab::NaoVerificada], [$depois->getNumero(), $depois->getStatus()]);
        self::assertNotSame(StatusOab::Confirmada, $this->recarregarUsuario($adv)->getOabStatus());

        $client->followRedirect();
        self::assertAnySelectorTextContains('.alert', 'A inscrição mudou depois que a fila foi aberta. Confira de novo.');
    }

    #[TestDox('Reverter depois que o titular trocou a UF (mesmo id) é recusado com aviso, e nada muda')]
    public function testReverterRecusaSeInscricaoMudouDepoisDaFila(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $adv   = $this->criarAdvogado(StatusOab::Confirmada, '11111', 'SP');
        $supId = (int) $this->adicionarSuplementar($adv, 'RJ', StatusOab::Confirmada, '22222')->getId();

        $crawler = $client->request('GET', '/admin/platform/oab?status=confirmada&q=' . urlencode((string) $adv->getEmail()));
        $form    = $crawler->filter('form[action*="/' . $supId . '/reverter"]')->eq(1)->form();

        $this->trocarNumero($supId, '22222', 'MG');

        $client->submit($form);
        self::assertResponseRedirects();
        $depois = $this->recarregarInscricao($supId);
        self::assertSame(['MG', StatusOab::NaoVerificada], [$depois->getUf(), $depois->getStatus()], 'só o efeito da troca, não o Divergente pedido');

        $client->followRedirect();
        self::assertAnySelectorTextContains('.alert', 'A inscrição mudou depois que a fila foi aberta. Confira de novo.');
    }

    #[TestDox('Confirmar sem o número e a UF exibidos (POST montado à mão) é recusado com aviso')]
    public function testConfirmarSemNumeroExibidoRecusa(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));
        $adv   = $this->criarAdvogado(StatusOab::NaoVerificada);
        $supId = (int) $this->adicionarSuplementar($adv, 'RJ', StatusOab::NaoVerificada)->getId();

        $client->request('POST', '/admin/platform/oab/' . $supId . '/confirmar', ['_token' => 'TOKEN_oab_confirmar_' . $supId]);

        self::assertResponseRedirects();
        self::assertSame(StatusOab::NaoVerificada, $this->recarregarInscricao($supId)->getStatus());
    }

    #[TestDox('Confirmar com CSRF inválido → 403 e situação inalterada')]
    public function testCsrfInvalidoRecusa(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));
        $adv   = $this->criarAdvogado(StatusOab::NaoVerificada);
        $supId = (int) $this->adicionarSuplementar($adv, 'RJ', StatusOab::Divergente)->getId();

        $client->request('POST', '/admin/platform/oab/' . $supId . '/confirmar', ['_token' => 'forjado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(StatusOab::Divergente, $this->recarregarInscricao($supId)->getStatus());
    }

    #[TestDox('Token CSRF de outra inscrição não vale (o id do token é por inscrição)')]
    public function testCsrfDeOutraInscricaoRecusa(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));
        $adv         = $this->criarAdvogado(StatusOab::NaoVerificada);
        $principalId = (int) $this->principalDe($adv)->getId();
        $supId       = (int) $this->adicionarSuplementar($adv, 'RJ', StatusOab::NaoVerificada)->getId();

        $client->request('POST', '/admin/platform/oab/' . $supId . '/confirmar', ['_token' => 'TOKEN_oab_confirmar_' . $principalId]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(StatusOab::NaoVerificada, $this->recarregarInscricao($supId)->getStatus());
    }

    #[TestDox('Id de inscrição inexistente → 404 nas três ações')]
    public function testIdInexistenteRecebe404(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));
        $client->disableReboot(); // três POST seguidos com o mesmo storage de CSRF trocado
        $id = 2_000_000_000;

        $client->request('POST', '/admin/platform/oab/' . $id . '/confirmar', ['_token' => 'TOKEN_oab_confirmar_' . $id]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/admin/platform/oab/' . $id . '/reverter', ['_token' => 'TOKEN_oab_reverter_' . $id, 'destino' => 'divergente']);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/admin/platform/oab/' . $id . '/reverificar', ['_token' => 'TOKEN_oab_reverificar_' . $id]);
        self::assertResponseStatusCodeSame(404);
    }

    // ----------------------------------------------------------------- helpers

    /** As três ações sobre uma suplementar recebem 403 (com CSRF e número/UF válidos) e ela não muda. */
    private function assertAcoesRecusadas(KernelBrowser $client): void
    {
        $adv = $this->criarAdvogado(StatusOab::NaoVerificada);
        $sup = $this->adicionarSuplementar($adv, 'RJ', StatusOab::Divergente);
        $id  = (int) $sup->getId();

        foreach (['confirmar' => [], 'reverter' => ['destino' => 'nao_verificada'], 'reverificar' => []] as $acao => $extra) {
            $client->request('POST', '/admin/platform/oab/' . $id . '/' . $acao, [
                '_token' => 'TOKEN_oab_' . $acao . '_' . $id,
                'numero' => $sup->getNumero(),
                'uf'     => $sup->getUf(),
            ] + $extra);
            self::assertResponseStatusCodeSame(403, 'ação ' . $acao);
        }
        self::assertSame(StatusOab::Divergente, $this->recarregarInscricao($id)->getStatus());
    }

    /** O que o perfil faz quando o titular edita a inscrição: `alterar` mantém o id e volta a Pendente. */
    private function trocarNumero(int $inscricaoId, string $numero, string $uf): void
    {
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $inscricao = $em->getRepository(InscricaoOab::class)->find($inscricaoId);
        self::assertNotNull($inscricao);
        $inscricao->alterar($numero, $uf);
        if ($inscricao->ehPrincipal()) {
            $inscricao->espelharNoUsuario();
        }
        $em->flush();
        $em->clear();
    }

    private function logar(KernelBrowser $client, User $user): void
    {
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
    }

    /**
     * Tokens CSRF previsíveis (`TOKEN_<id>`) para montar POST sem passar pela tela (mesmo recurso do
     * TarefaIsolamentoControllerTest).
     */
    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
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
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @param list<string> $roles */
    private function criarUsuario(array $roles): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('u_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Teste');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Usuário com a inscrição principal (e o espelho User.oab* igual a ela). */
    private function criarAdvogado(StatusOab $status, string $numero = '12345', string $uf = 'SP'): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('adv_' . uniqid() . '@test.com');
        $user->setFullName('Fulano Advogado');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $principal = new InscricaoOab($user, $numero, $uf, TipoInscricaoOab::Principal);
        $principal->registrarVerificacao($status, null);
        $principal->espelharNoUsuario();
        $em->persist($principal);
        $em->flush();

        return $user;
    }

    private function adicionarSuplementar(User $user, string $uf, StatusOab $status, string $numero = '54321'): InscricaoOab
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $sup = new InscricaoOab($user, $numero, $uf, TipoInscricaoOab::Suplementar);
        $sup->registrarVerificacao($status, null);
        $em->persist($sup);
        $em->flush();

        return $sup;
    }

    private function principalDe(User $user): InscricaoOab
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $i  = $em->getRepository(InscricaoOab::class)->findOneBy(['usuario' => $user, 'tipo' => TipoInscricaoOab::Principal]);
        self::assertNotNull($i);

        return $i;
    }

    private function recarregarInscricao(int $id): InscricaoOab
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $i = $em->getRepository(InscricaoOab::class)->find($id);
        self::assertNotNull($i);

        return $i;
    }

    private function recarregarUsuario(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = $user->getId();
        $em->clear();
        $u = $em->getRepository(User::class)->find($id);
        self::assertNotNull($u);

        return $u;
    }
}
