<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\GoogleAgendaController;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\UseCase\ConectarGoogleAgendaUseCase;
use App\Agenda\UseCase\DesconectarGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(GoogleAgendaController::class)]
#[CoversClass(ConectarGoogleAgendaUseCase::class)]
#[CoversClass(DesconectarGoogleAgendaUseCase::class)]
final class GoogleAgendaControllerTest extends JusPrimeWebTestCase
{
    private GoogleFalso $google;

    #[TestDox('Painel do desenho sem cliente OAuth no servidor: "Disponível quando o servidor liberar a conexão", sem endereço de conexão')]
    public function testPainelIndisponivel(): void
    {
        $client = $this->cliente(new GoogleAgendaApi(new MockHttpClient()));
        $this->entrar($client);
        $client->request('GET', '/agenda/classica');
        self::assertSelectorTextContains('.ag-google-st', 'Disponível quando o servidor liberar a conexão');
        self::assertSelectorTextContains('#agGoogle', 'Sincronizar com o Google Agenda');
        self::assertSame('', $client->getCrawler()->filter('[data-google]')->attr('data-url-google-conectar'));
    }

    #[TestDox('Painel do desenho com o servidor liberado: "Não conectado"; conectado, "Conectado · atualiza automaticamente" e Desconectar')]
    public function testPainelNaoConectadoEConectado(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $client->request('GET', '/agenda/classica');
        self::assertSelectorTextContains('.ag-google-st', 'Não conectado');
        self::assertSelectorNotExists('#agGoogleSair');

        $this->conectado($eu, $tenant);
        $client->request('GET', '/agenda/classica');
        self::assertSelectorTextContains('.ag-google-st', 'Conectado · atualiza automaticamente');
        self::assertSelectorTextContains('#agGoogle', 'Sincronizado com o Google Agenda');
        self::assertSelectorExists('#agGoogleSair');
    }

    #[TestDox('Conectar leva ao Google com state, PKCE S256, offline, o escopo só das agendas que o BlueJus cria e o e-mail do cadastro como sugestão; volta de outro site vira a Agenda')]
    public function testConectarLevaAoGoogle(): void
    {
        $client = $this->cliente();
        [$eu] = $this->entrar($client);

        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('https://mal.example/x'));
        $destino = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($destino, \PHP_URL_QUERY), $q);

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $destino);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame(43, strlen((string) $q['code_challenge']));
        self::assertSame(32, strlen((string) $q['state']));
        self::assertSame('offline', $q['access_type']);
        self::assertSame('false', $q['include_granted_scopes'], 'nada herdado de outra autorização do mesmo cliente');
        self::assertSame('openid email https://www.googleapis.com/auth/calendar.app.created', $q['scope']);
        self::assertSame($eu->getEmail(), $q['login_hint'], 'sugere a conta do cadastro; a pessoa escolhe qualquer uma');
        self::assertStringEndsWith('/agenda/google/retorno', (string) $q['redirect_uri']);

        $client->request('GET', '/agenda/google/retorno?error=access_denied&state=' . $q['state']);
        self::assertResponseRedirects('/agenda#google=cancelado');
    }

    #[TestDox('Retorno com state errado é recusado; com o certo grava a conexão com o token cifrado (nunca em texto puro) e volta à página de origem')]
    public function testRetornoGravaCifrado(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('http://localhost/agenda?vista=semana'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        $client->request('GET', '/agenda/google/retorno?code=abc&state=outro');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('/agenda?vista=semana'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $client->request('GET', '/agenda/google/retorno?code=abc&state=' . $q['state']);

        self::assertResponseRedirects('/agenda?vista=semana#google=conectado');
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT conta, refresh_token_cifrado FROM agenda_google_conexao WHERE usuario_id = :u AND tenant_id = :t', ['u' => $eu->getId(), 't' => $tenant->getId()]);
        self::assertIsArray($linha);
        self::assertSame('pessoa@gmail.com', $linha['conta']);
        self::assertStringNotContainsString('REFRESH-SECRETO', (string) $linha['refresh_token_cifrado']);
        self::assertSame('REFRESH-SECRETO', static::getContainer()->get(CifradorDeSegredo::class)->decifrar((string) $linha['refresh_token_cifrado']));
        self::assertContains('POST /token', $this->google->pedidos);
    }

    #[TestDox('Conta Google sem e-mail confirmado não conecta: o e-mail casa convidados com a equipe')]
    public function testEmailNaoConfirmado(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $this->google->emailConfirmado = false;
        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('/agenda'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        $client->request('GET', '/agenda/google/retorno?code=abc&state=' . $q['state']);

        self::assertResponseRedirects('/agenda#google=falhou');
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT id FROM agenda_google_conexao WHERE usuario_id = :u AND tenant_id = :t', ['u' => $eu->getId(), 't' => $tenant->getId()]));
    }

    #[TestDox('A mesma conta Google pode ser ligada em outro escritório: cada um terá a sua agenda nela, e a de lá não é revogada')]
    public function testMesmaContaEmOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $outro = (new Tenant())->setName('Tenant GGL outro ' . uniqid());
        $this->em()->persist($outro);
        $this->em()->flush();
        $this->conectado($eu, $outro);
        $client->request('GET', '/agenda/google/conectar');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        $client->request('GET', '/agenda/google/retorno?code=abc&state=' . $q['state']);

        self::assertResponseRedirects('/agenda#google=conectado');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_conexao WHERE tenant_id = :t', ['t' => $tenant->getId()]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_conexao WHERE tenant_id = :t', ['t' => $outro->getId()]));
        self::assertNotContains('POST /revoke', $this->google->pedidos);
    }

    #[TestDox('Identificação ou permissões que não conferem: $caso. Não conecta; permissões erradas são revogadas lá')]
    #[DataProvider('retornosRecusados')]
    public function testRetornoQueNaoConfere(string $caso, string $propriedade, string|int $valor, bool $revoga, string $aviso = 'falhou'): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $this->google->{$propriedade} = $valor;
        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('/agenda'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        $client->request('GET', '/agenda/google/retorno?code=abc&state=' . $q['state']);

        self::assertResponseRedirects('/agenda#google=' . $aviso);
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT id FROM agenda_google_conexao WHERE usuario_id = :u AND tenant_id = :t', ['u' => $eu->getId(), 't' => $tenant->getId()]));
        self::assertSame($revoga, in_array('POST /revoke', $this->google->pedidos, true));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string|int, 3: bool, 4?: string}> */
    public static function retornosRecusados(): iterable
    {
        yield 'id_token de outro cliente' => ['id_token de outro cliente', 'audiencia', 'outro-cliente', false];
        yield 'id_token vencido' => ['id_token vencido', 'validadeDoIdToken', -60, false];
        yield 'escopo além do pedido' => ['escopo além do pedido', 'escoposConcedidos', 'openid email https://www.googleapis.com/auth/calendar.app.created https://www.googleapis.com/auth/drive', true];
        yield 'sem o escopo da agenda (a caixinha desmarcada no Google): volta com o aviso próprio' => ['sem o escopo da agenda', 'escoposConcedidos', 'openid email', true, 'sem-permissao'];
    }

    #[TestDox('A falha na volta do Google vai ao log de erro com o motivo (o código do OAuth, sem a descrição livre); escritório trocado no meio também')]
    public function testFalhaNaVoltaVaiAoLog(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $this->google->erroDoToken = 'invalid_client';
        $antes = $this->tamanhoDoLog();
        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('/agenda'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        $client->request('GET', '/agenda/google/retorno?code=abc&state=' . $q['state']);

        self::assertResponseRedirects('/agenda#google=falhou');
        $log = $this->logDesde($antes);
        self::assertStringContainsString('Google Agenda: conexão falhou', $log);
        self::assertStringContainsString('O Google recusou o pedido de token (401, invalid_client)', $log);
        self::assertStringNotContainsString('Descrição livre do Google', $log);
        self::assertStringNotContainsString('REFRESH-SECRETO', $log);

        $outro = (new Tenant())->setName('Tenant GGL outro ' . uniqid());
        $this->em()->persist($outro);
        $papel = (new TenantRole())->setTenant($outro)->setName('Papel ' . uniqid())->setIsSystem(true);
        $this->em()->persist($papel);
        $vinculo = new UserTenant($this->em()->find(User::class, $eu->getId()), $outro);
        $vinculo->setTenantRole($papel);
        $this->em()->persist($vinculo);
        $this->em()->flush();
        $this->google->erroDoToken = '';
        $antes = $this->tamanhoDoLog();
        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('/agenda'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $sessao = $client->getSession();
        $sessao->set('current_tenant_id', $outro->getId());
        $sessao->save();

        $client->request('GET', '/agenda/google/retorno?code=abc&state=' . $q['state']);

        self::assertResponseRedirects('/agenda#google=falhou');
        self::assertStringContainsString('Google Agenda: escritório trocado entre a ida e a volta do Google', $this->logDesde($antes));
    }

    #[TestDox('Permissões erradas numa conta já ligada em outro escritório: recusa sem revogar (derrubaria a de lá)')]
    public function testRecusaSemDerrubarOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$eu] = $this->entrar($client);
        $outro = (new Tenant())->setName('Tenant GGL outro ' . uniqid());
        $this->em()->persist($outro);
        $this->em()->flush();
        $this->conectado($eu, $outro);
        $this->google->escoposConcedidos = 'openid email';
        $client->request('GET', '/agenda/google/conectar?volta=' . rawurlencode('/agenda'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        $client->request('GET', '/agenda/google/retorno?code=abc&state=' . $q['state']);

        self::assertResponseRedirects('/agenda#google=sem-permissao');
        self::assertNotContains('POST /revoke', $this->google->pedidos);
    }

    #[TestDox('Desconectar com a sincronia rodando: 409 com o aviso, e a conexão fica')]
    public function testDesconectarDuranteASincronia(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $this->conectado($eu, $tenant);
        $this->em()->getConnection()->executeStatement('UPDATE agenda_google_conexao SET ocupada_desde = NOW() WHERE usuario_id = :u', ['u' => $eu->getId()]);

        $client->request('POST', '/agenda/google/desconectar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{}');

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('sincronizando agora', (string) json_decode((string) $client->getResponse()->getContent(), true)['erro']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_conexao WHERE usuario_id = :u', ['u' => $eu->getId()]));
    }

    #[TestDox('Limitador configurado: 20 pedidos por pessoa em 15 minutos; o 21º é recusado')]
    public function testLimitadorConfigurado(): void
    {
        $limite = static::getContainer()->get('limiter.agenda_google')->create('pessoa-teste');

        self::assertTrue($limite->consume(20)->isAccepted());
        self::assertFalse($limite->consume()->isAccepted());
    }

    #[TestDox('Limitador nas rotas do Google: estourado, conectar volta à Agenda com o aviso e desconectar dá 429 com a mensagem')]
    public function testLimitadorNasRotas(): void
    {
        $client = $this->cliente();
        // Nas requisições o kernel zera os pools em memória entre uma e outra: um de limite 1 prova o estouro.
        static::getContainer()->set('limiter.agenda_google', new RateLimiterFactory(['id' => 'agenda_google_teste', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage()));
        $this->entrar($client);

        $client->request('GET', '/agenda/google/conectar');
        self::assertStringStartsWith('https://accounts.google.com/', (string) $client->getResponse()->headers->get('Location'));
        $client->request('GET', '/agenda/google/conectar');
        self::assertResponseRedirects('/agenda#google=limite');
        $client->request('POST', '/agenda/google/desconectar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{}');

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Tente de novo em alguns minutos', (string) json_decode((string) $client->getResponse()->getContent(), true)['erro']);
    }

    #[TestDox('Desconectar pedindo para apagar a agenda do escritório: apaga lá também; sem pedir, ela fica')]
    public function testDesconectarApagandoAAgenda(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $this->conectado($eu, $tenant);
        $this->em()->getConnection()->executeStatement("UPDATE agenda_google_conexao SET calendario_id = 'agenda-do-escritorio' WHERE usuario_id = :u", ['u' => $eu->getId()]);

        $client->request('POST', '/agenda/google/desconectar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{"apagarAgenda": true}');

        self::assertResponseIsSuccessful();
        self::assertContains('DELETE /calendar/v3/calendars/agenda-do-escritorio', $this->google->pedidos);
    }

    #[TestDox('Desconectar revoga no Google e apaga a conexão (LGPD, art. 8º, § 5º); sem Agenda, 403')]
    public function testDesconectar(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $semAgenda = $this->criarUsuario($tenant, false);
        $this->conectado($eu, $tenant);

        $client->request('POST', '/agenda/google/desconectar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertNotContains('POST /revoke', $this->google->pedidos, 'sem CSRF válido, nada é revogado');

        $client->request('POST', '/agenda/google/desconectar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{}');

        self::assertResponseIsSuccessful();
        self::assertContains('POST /revoke', $this->google->pedidos);
        self::assertSame([], array_filter($this->google->pedidos, static fn (string $p): bool => str_starts_with($p, 'DELETE /calendar')), 'sem pedir, a agenda do escritório fica no Google');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_conexao WHERE usuario_id = :u', ['u' => $eu->getId()]));

        $this->logarComTenant($client, $semAgenda, $tenant);
        $client->request('GET', '/agenda/google/conectar');
        self::assertResponseStatusCodeSame(403);
    }

    private function conectado(User $eu, Tenant $tenant): void
    {
        $cifrado = static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH-SECRETO');
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO agenda_google_conexao (tenant_id, usuario_id, refresh_token_cifrado, conta, fuso, conectada_em) VALUES (:t, :u, :c, 'pessoa@gmail.com', 'America/Sao_Paulo', NOW())",
            ['t' => $tenant->getId(), 'u' => $eu->getId(), 'c' => $cifrado],
        );
    }

    /** @return array{User, Tenant} */
    private function entrar(KernelBrowser $client): array
    {
        $tenant = (new Tenant())->setName('Tenant GGL ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();
        $eu = $this->criarUsuario($tenant, true);
        $this->logarComTenant($client, $eu, $tenant);

        return [$eu, $tenant];
    }

    private function arquivoDoLog(): string
    {
        return static::getContainer()->getParameter('kernel.logs_dir') . '/test' . (getenv('TESTE_PROCESSO') ?: '') . '.log';
    }

    private function tamanhoDoLog(): int
    {
        clearstatcache();

        return is_file($this->arquivoDoLog()) ? (int) filesize($this->arquivoDoLog()) : 0;
    }

    private function logDesde(int $posicao): string
    {
        clearstatcache();

        return is_file($this->arquivoDoLog()) ? (string) file_get_contents($this->arquivoDoLog(), false, null, $posicao) : '';
    }

    private function cliente(?GoogleAgendaApi $api = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->google = new GoogleFalso();
        static::getContainer()->set(GoogleAgendaApi::class, $api ?? $this->google->api());
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

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarUsuario(Tenant $tenant, bool $gestor): User
    {
        $em = $this->em();
        $u = new User();
        $u->setEmail('ggl_' . uniqid() . '@test.com');
        $u->setFullName('Usuário ' . uniqid());
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $em->persist($u);
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($gestor);
        $em->persist($role);
        $ut = new UserTenant($u, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $u;
    }
}
