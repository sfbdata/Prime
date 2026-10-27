<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\AssinaturaDeEspacoController;
use App\Contribua\Controller\WebhookDoGatewayController;
use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Service\AtualizacaoDaAssinatura;
use App\Contribua\Service\PlanoVigenteDoEscritorio;
use App\Contribua\UseCase\ConciliarAssinaturasUseCase;
use App\Contribua\UseCase\MontarPagamentoDoContribuaUseCase;
use App\Contribua\UseCase\ReceberAvisoDoGatewayUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Contribua\Doubles\GatewayFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O caminho do cartão de ponta a ponta com o gateway falso: checkout, retorno, webhook com reconsulta,
 * confirmação manual do Master, cancelamento e conciliação (fim do mês pago volta ao Free).
 */
#[CoversClass(AssinaturaDeEspacoController::class)]
#[CoversClass(WebhookDoGatewayController::class)]
#[CoversClass(AtualizacaoDaAssinatura::class)]
#[CoversClass(PlanoVigenteDoEscritorio::class)]
#[CoversClass(ReceberAvisoDoGatewayUseCase::class)]
#[CoversClass(ConciliarAssinaturasUseCase::class)]
final class AssinaturaDeEspacoFluxoTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private GatewayFalso $gateway;
    private Tenant $escritorio;
    private User $primario;
    private User $secundario;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->gateway = static::getContainer()->get(GatewayFalso::class);
        $this->escritorio = $this->tenant();
        $this->primario = $this->pessoa('primario', true);
        $this->secundario = $this->pessoa('secundario', false);
        $this->master = $this->pessoa('master', false, ['ROLE_SUPER_ADMIN']);
        $this->ligarGatewayFalso();
    }

    #[TestDox('Sem gateway escolhido pelo Master, o checkout não abre (o botão segue para a lista de espera)')]
    public function testSemGatewayNaoAbre(): void
    {
        $config = static::getContainer()->get(ConfiguracaoDePagamentoRepository::class)->atual();
        $config->configurar(null, null, [], 7);
        $this->em->flush();

        $resposta = $this->assinar($this->primario);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A assinatura por cartão ainda não foi liberada.', $resposta['erro']);
    }

    #[TestDox('Só o primário assina; o secundário recebe a recusa do desenho')]
    public function testSecundarioNaoAssina(): void
    {
        $resposta = $this->assinar($this->secundario);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Quem amplia o espaço é o usuário primário do escritório.', $resposta['erro']);
        self::assertSame(0, $this->contarAssinaturas());
    }

    #[TestDox('O checkout grava o pedido aguardando e devolve a página do gateway; o retorno não libera espaço')]
    public function testCheckoutERetorno(): void
    {
        $resposta = $this->assinar($this->primario);

        self::assertTrue($resposta['ok'], json_encode($resposta) ?: '');
        self::assertSame('redirecionar', $resposta['modo']);
        self::assertStringStartsWith('https://gateway.falso/checkout/', (string) $resposta['url']);
        $pedido = $this->pedido();
        self::assertSame(SituacaoDaAssinatura::Aguardando, $pedido->getSituacao());
        self::assertSame(1990, $pedido->getValorCentavos());

        $this->client->request('GET', '/contribua?assinatura=ok');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Pagamento enviado', $html);
        self::assertStringContainsString('seu espaço passa para <span data-co-ok-gb>20 GB</span>', $html);
        self::assertStringContainsString('data-retorno="1"', $html, 'abre direto no Pagamento enviado');
        self::assertSame('free', $this->planoDoEscritorio(), 'a URL de retorno não libera nada sozinha');
    }

    #[TestDox('Gateway fora do ar no checkout: mensagem amigável e nenhum pedido fica para trás')]
    public function testGatewayForaDoArNoCheckout(): void
    {
        $this->gateway->foraDoAr = true;

        $resposta = $this->assinar($this->primario);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('O gateway de pagamento não respondeu. Tente de novo em instantes.', $resposta['erro']);
        self::assertSame(0, $this->contarAssinaturas());
    }

    #[TestDox('O webhook reconsulta o gateway: pago libera o plano e avisa o primário; repetido e sem token não fazem nada')]
    public function testWebhookComReconsulta(): void
    {
        $this->assinar($this->primario);
        $idNoGateway = (string) $this->pedido()->getIdDoCheckout();
        $this->gateway->marcarComoPaga($idNoGateway, new \DateTimeImmutable('+1 month'));

        self::assertSame('processado', $this->webhook('evt-1', $idNoGateway)['resultado']);
        self::assertSame(SituacaoDaAssinatura::Ativa, $this->pedido()->getSituacao());
        self::assertSame($idNoGateway, $this->pedido()->getIdDaAssinatura());
        self::assertSame('essencial', $this->planoDoEscritorio());
        self::assertSame(1, $this->contarNotificacoesDoPrimario());

        self::assertSame('repetido', $this->webhook('evt-1', $idNoGateway)['resultado']);
        $this->webhook('evt-2', $idNoGateway, 'token-errado');
        self::assertResponseStatusCodeSame(401);
        self::assertSame(1, $this->contarNotificacoesDoPrimario());
    }

    #[TestDox('Webhook: a query da URL chega ao adaptador como x-query-<nome>, com o ponto preservado')]
    public function testQueryDoWebhook(): void
    {
        $this->client->request('POST', '/api/webhooks/falso?data.id=123&type=payment', [], [], ['HTTP_X_TOKEN' => GatewayFalso::TOKEN_DO_AVISO], '{}');

        self::assertResponseIsSuccessful();
        self::assertSame('123', $this->gateway->ultimosCabecalhos['x-query-data.id'] ?? null);
        self::assertSame('payment', $this->gateway->ultimosCabecalhos['x-query-type'] ?? null);
    }

    #[TestDox('O aviso de assinatura que o BlueJus não conhece não muda nada nem derruba o webhook')]
    public function testWebhookDeAssinaturaAlheia(): void
    {
        $this->webhook('evt-x', 'sub_que_nao_existe');

        self::assertResponseIsSuccessful();
        self::assertSame('free', $this->planoDoEscritorio());
    }

    #[TestDox('Master: configurar e confirmar pagamento exigem ROLE_SUPER_ADMIN; a confirmação libera o espaço')]
    public function testConfirmacaoManualDoMaster(): void
    {
        $this->assinar($this->primario);
        $id = (int) $this->pedido()->getId();

        $this->post('/contribua/pagamento/' . $id . '/confirmar', []);
        self::assertResponseStatusCodeSame(403);
        $this->post('/contribua/pagamento', ['gateway' => 'falso', 'carencia' => 7]);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        self::assertTrue($this->post('/contribua/pagamento/' . $id . '/confirmar', [])['ok']);
        self::assertSame(SituacaoDaAssinatura::Ativa, $this->pedido()->getSituacao());
        self::assertSame($this->master->getId(), $this->pedido()->getConfirmadaPor()?->getId());
        self::assertSame('essencial', $this->planoDoEscritorio());
    }

    #[TestDox('Master salva o gateway e a carência; gateway não instalado é recusado')]
    public function testConfigurarPagamento(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);

        self::assertFalse($this->post('/contribua/pagamento', ['gateway' => 'pagseguro', 'carencia' => 7])['ok']);
        self::assertTrue($this->post('/contribua/pagamento', ['gateway' => 'falso', 'chavePublica' => 'pk_teste', 'carencia' => 10, 'links' => ['essencial' => 'https://pague.exemplo/essencial']])['ok']);

        $config = static::getContainer()->get(ConfiguracaoDePagamentoRepository::class)->atual();
        self::assertSame(10, $config->getDiasDeCarencia());
        self::assertSame('pk_teste', $config->getChavePublica());
        self::assertSame('https://pague.exemplo/essencial', $config->linkDoPacote('essencial'));
    }

    #[TestDox('Cancelar: cancela no gateway, mantém o plano até o fim do mês pago; depois a conciliação volta ao Free')]
    public function testCancelarEDepoisVencer(): void
    {
        $this->assinar($this->primario);
        $idNoGateway = (string) $this->pedido()->getIdDoCheckout();
        $this->gateway->marcarComoPaga($idNoGateway, new \DateTimeImmutable('+10 days'));
        $this->webhook('evt-pago', $idNoGateway);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $resposta = $this->post('/contribua/assinatura/cancelar', []);
        self::assertTrue($resposta['ok'], json_encode($resposta) ?: '');
        self::assertContains($idNoGateway, $this->gateway->canceladas);
        self::assertSame(SituacaoDaAssinatura::Cancelada, $this->pedido()->getSituacao());
        self::assertSame('essencial', $this->planoDoEscritorio(), 'vale até o fim do mês pago');

        static::getContainer()->get(ConciliarAssinaturasUseCase::class)->executar(new \DateTimeImmutable('+11 days'));
        self::assertSame('free', $this->planoDoEscritorio());
    }

    #[TestDox('Uma assinatura viva por escritório: outro checkout pede para cancelar a atual antes')]
    public function testUmaAssinaturaViva(): void
    {
        $this->assinar($this->primario);
        $this->gateway->marcarComoPaga((string) $this->pedido()->getIdDoCheckout(), new \DateTimeImmutable('+1 month'));
        $this->webhook('evt-a', (string) $this->pedido()->getIdDoCheckout());

        $resposta = $this->assinar($this->primario, 'profissional');

        self::assertFalse($resposta['ok']);
        self::assertStringContainsString('cancele a atual em Meu Perfil', (string) $resposta['erro']);
    }

    #[TestDox('Formulário: o servidor recebe só o token, assina e devolve a próxima cobrança e a ação do 3-D Secure')]
    public function testAssinarComToken(): void
    {
        $this->gateway->modo = InicioDoCheckout::FORMULARIO;

        $inicio = $this->assinar($this->primario);
        self::assertSame('formulario', $inicio['modo'], json_encode($inicio) ?: '');
        self::assertSame('chave-publica-falsa', $inicio['chavePublica']);

        $resposta = $this->post('/contribua/assinatura/' . $inicio['pedido'] . '/token', ['token' => 'tok_falso', 'documento' => '12345678909']);
        self::assertTrue($resposta['ok'], json_encode($resposta) ?: '');
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', (string) $resposta['proxima']);
        self::assertArrayHasKey('acaoNoNavegador', $resposta);
        self::assertSame(SituacaoDaAssinatura::Ativa, $this->pedido()->getSituacao());
        self::assertSame('essencial', $this->planoDoEscritorio());

        $this->post('/contribua/assinatura/' . $inicio['pedido'] . '/token', ['token' => 'tok_falso', 'documento' => '12345678909']);
        self::assertResponseStatusCodeSame(422, 'o mesmo pedido não assina duas vezes');
    }

    #[TestDox('Confirmado à mão antes do aviso: a assinatura do gateway se liga ao pedido e o cancelar chega ao gateway')]
    public function testConfirmacaoManualDepoisWebhook(): void
    {
        $this->assinar($this->primario);
        $idNoGateway = (string) $this->pedido()->getIdDoCheckout();
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->post('/contribua/pagamento/' . $this->pedido()->getId() . '/confirmar', []);

        $this->gateway->marcarComoPaga($idNoGateway, new \DateTimeImmutable('+1 month'));
        $this->webhook('evt-depois', $idNoGateway);
        self::assertSame($idNoGateway, $this->pedido()->getIdDaAssinatura());

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertTrue($this->post('/contribua/assinatura/cancelar', [])['ok']);
        self::assertContains($idNoGateway, $this->gateway->canceladas);
    }

    #[TestDox('Master navegando em outro escritório confirma o pedido e o plano muda de verdade; a auditoria vai para a conta do pedido')]
    public function testMasterDeOutroEscritorioConfirma(): void
    {
        $this->assinar($this->primario);
        $id = (int) $this->pedido()->getId();
        $outro = $this->tenant();
        $masterDeFora = $this->pessoa('master_fora', false, ['ROLE_SUPER_ADMIN'], $outro);

        $this->logarComTenant($this->client, $masterDeFora, $outro);
        self::assertTrue($this->post('/contribua/pagamento/' . $id . '/confirmar', [])['ok']);

        self::assertSame('essencial', $this->planoDoEscritorio());
        $auditoria = static::getContainer()->get(Connection::class)->fetchOne("SELECT tenant_id FROM audit_log WHERE entity_class = ? AND entity_id = ? AND action = 'update' ORDER BY id DESC LIMIT 1", [AssinaturaDeEspaco::class, (string) $id]);
        self::assertSame((int) $this->escritorio->getId(), (int) $auditoria);
    }

    #[TestDox('Checkout aberto: o mesmo plano reabre a mesma página do gateway; outro plano substitui o anterior')]
    public function testCheckoutAbertoReaproveitado(): void
    {
        $primeiro = $this->assinar($this->primario);
        $denovo = $this->assinar($this->primario);
        self::assertSame($primeiro['pedido'], $denovo['pedido']);
        self::assertSame($primeiro['url'], $denovo['url']);
        self::assertSame(1, $this->contarAssinaturas());

        // Página hospedada de outro plano: o anterior continua aguardando (pode ter sido pago), e o pago
        // se liga ao pedido do mesmo valor quando o aviso chegar.
        $outro = $this->assinar($this->primario, 'profissional');
        self::assertTrue($outro['ok'], json_encode($outro) ?: '');
        self::assertNotSame($primeiro['pedido'], $outro['pedido']);
        $this->em->clear();
        self::assertSame(SituacaoDaAssinatura::Aguardando, static::getContainer()->get(AssinaturaDeEspacoRepository::class)->find($primeiro['pedido'])?->getSituacao());

        $idDoPrimeiro = (string) static::getContainer()->get(AssinaturaDeEspacoRepository::class)->find($primeiro['pedido'])?->getIdDoCheckout();
        $this->gateway->marcarComoPaga($idDoPrimeiro, new \DateTimeImmutable('+1 month'));
        $this->webhook('evt-primeiro', $idDoPrimeiro);
        $this->em->clear();
        self::assertSame(SituacaoDaAssinatura::Ativa, static::getContainer()->get(AssinaturaDeEspacoRepository::class)->find($primeiro['pedido'])?->getSituacao());
        self::assertSame('essencial', $this->planoDoEscritorio());
    }

    #[TestDox('Voltou da página do gateway: outro plano espera a confirmação do pagamento anterior')]
    public function testVoltouEspera(): void
    {
        $this->assinar($this->primario);
        $this->client->request('GET', '/contribua?assinatura=ok');

        $outro = $this->assinar($this->primario, 'profissional');

        self::assertFalse($outro['ok']);
        self::assertStringContainsString('sendo confirmado pelo gateway', (string) $outro['erro']);
    }

    #[TestDox('Cartão recusado no token: a nova tentativa reaproveita o mesmo pedido, sem esperar')]
    public function testNovaTentativaDepoisDeRecusa(): void
    {
        $this->gateway->modo = InicioDoCheckout::FORMULARIO;
        $inicio = $this->assinar($this->primario);
        $this->gateway->foraDoAr = true;
        self::assertFalse($this->post('/contribua/assinatura/' . $inicio['pedido'] . '/token', ['token' => 'tok', 'documento' => '12345678909'])['ok']);

        $this->gateway->foraDoAr = false;
        $denovo = $this->assinar($this->primario);
        self::assertTrue($denovo['ok'], json_encode($denovo) ?: '');
        self::assertSame($inicio['pedido'], $denovo['pedido']);
        self::assertTrue($this->post('/contribua/assinatura/' . $denovo['pedido'] . '/token', ['token' => 'tok', 'documento' => '12345678909'])['ok']);
    }

    #[TestDox('O Master não assina pelo escritório (D4: quem compra é o primário)')]
    public function testMasterNaoAssina(): void
    {
        $resposta = $this->assinar($this->master);

        self::assertFalse($resposta['ok']);
        self::assertSame(0, $this->contarAssinaturas());
    }

    #[TestDox('Bloco Assinatura do Meu Perfil: dados do escritório pago e parcial que não quebra sem sessão')]
    public function testAssinaturaNoPerfil(): void
    {
        $this->assinar($this->primario);
        $idNoGateway = (string) $this->pedido()->getIdDoCheckout();
        $this->gateway->marcarComoPaga($idNoGateway, new \DateTimeImmutable('+1 month'));
        $this->webhook('evt-perfil', $idNoGateway);

        $dados = static::getContainer()->get(MontarPagamentoDoContribuaUseCase::class)->assinaturaDoEscritorio((int) $this->escritorio->getId());
        self::assertSame('Essencial', $dados['plano'] ?? null);
        self::assertTrue($dados['cancelavel']);
        self::assertSame((new \DateTimeImmutable('+1 month'))->format('d/m/Y'), $dados['pagaAte']);
        self::assertSame('', trim(static::getContainer()->get('twig')->render('contribua/_assinatura_no_perfil.html.twig')));
    }

    #[TestDox('A URL de retorno só marca o pedido quando quem volta é o primário; o Master não lê "pago"')]
    public function testRetornoSoDoPrimario(): void
    {
        $this->assinar($this->primario);
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);
        $this->client->request('GET', '/contribua?assinatura=ok');
        self::assertNull($this->pedido()->getRetornouEm());

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('GET', '/contribua?assinatura=ok');
        self::assertNotNull($this->pedido()->getRetornouEm());

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->client->request('GET', '/contribua');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('voltou do gateway, não confirmado', $html);
        self::assertStringNotContainsString('pago, aguardando', $html);
    }

    #[TestDox('Escritório excluído: a conciliação cancela a assinatura no gateway e aqui')]
    public function testEscritorioExcluidoParaDeSerCobrado(): void
    {
        $this->assinar($this->primario);
        $idNoGateway = (string) $this->pedido()->getIdDoCheckout();
        $this->gateway->marcarComoPaga($idNoGateway, new \DateTimeImmutable('+1 month'));
        $this->webhook('evt-excluido', $idNoGateway);
        static::getContainer()->get(Connection::class)->executeStatement('UPDATE tenant SET excluido_em = NOW(), is_active = false WHERE id = ?', [$this->escritorio->getId()]);

        static::getContainer()->get(ConciliarAssinaturasUseCase::class)->executar(new \DateTimeImmutable());

        self::assertContains($idNoGateway, $this->gateway->canceladas);
        self::assertSame(SituacaoDaAssinatura::Cancelada, $this->pedido()->getSituacao());
    }

    #[TestDox('PIX: valor acima do teto do apoio é limitado e o código continua válido')]
    public function testPixComTeto(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('GET', '/contribua/pix?valor=999999999');

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['valido'] ?? false);
    }

    /** @return array<string, mixed> */
    private function assinar(User $quem, string $plano = 'essencial'): array
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);

        return $this->post('/contribua/assinatura', ['plano' => $plano, 'documento' => '123.456.789-09']);
    }

    /** @return array<string, mixed> */
    /** Sem sessão, como o gateway chama: o filtro de tenant fica desligado. */
    private function webhook(string $evento, string $assinatura, string $token = GatewayFalso::TOKEN_DO_AVISO): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/webhooks/falso', [], [], ['HTTP_X_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['id' => $evento, 'evento' => 'assinatura.atualizada', 'assinatura' => $assinatura]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $campos): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function pedido(): AssinaturaDeEspaco
    {
        $this->em->clear();
        $lista = static::getContainer()->get(AssinaturaDeEspacoRepository::class)->doTenant((int) $this->escritorio->getId());
        self::assertNotEmpty($lista);

        return $lista[0];
    }

    private function planoDoEscritorio(): string
    {
        $this->em->clear();
        $espaco = static::getContainer()->get(EspacoDoEscritorioRepository::class)->doTenant((int) $this->escritorio->getId());

        return $espaco === null ? 'free' : $espaco->getPlano()->getCodigo();
    }

    private function contarAssinaturas(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM contribua_assinatura WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function contarNotificacoesDoPrimario(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tipo = 'contribua.assinatura'", [$this->primario->getId()]);
    }

    private function ligarGatewayFalso(): void
    {
        $config = static::getContainer()->get(ConfiguracaoDePagamentoRepository::class)->atual();
        $config->configurar('falso', null, [], 7);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório ASSINATURA ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, bool $primario, array $roles = ['ROLE_USER'], ?Tenant $escritorio = null): User
    {
        $escritorio ??= $this->escritorio;
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($escritorio);
        $papel->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid());
        $papel->setIsSystem($primario);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $escritorio);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
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
}
