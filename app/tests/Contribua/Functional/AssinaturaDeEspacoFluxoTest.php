<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\AssinaturaDeEspacoController;
use App\Contribua\Controller\WebhookDoGatewayController;
use App\Contribua\Entity\AssinaturaDeEspaco;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Contribua\Event\UsoDoEspacoSubiu;
use App\Contribua\UseCase\AmpliarEspacoAutomaticamenteUseCase;
use App\Contribua\Entity\Plano;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Gateway\AvisoDoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\AtualizacaoDaAssinatura;
use App\Contribua\Service\PlanoVigenteDoEscritorio;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\UseCase\ConciliarAssinaturasUseCase;
use App\Contribua\UseCase\IniciarAssinaturaUseCase;
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
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

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
#[CoversClass(IniciarAssinaturaUseCase::class)]
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

    #[TestDox('VS 03.11.26: o maior pacote dobrado (×4) grava o valor e o GB multiplicados e o pagamento libera 400 GB')]
    public function testMaiorPacoteDobrado(): void
    {
        $resposta = $this->assinarCom($this->primario, 'avancado', 4);

        self::assertTrue($resposta['ok'], json_encode($resposta) ?: '');
        $pedido = $this->pedido();
        self::assertSame(4, $pedido->getMultiplicador());
        self::assertSame(6990 * 4, $pedido->getValorCentavos());
        self::assertSame(400, $pedido->gigabytes());
        self::assertSame(6990 * 4, $this->gateway->ultimoPedido?->valorCentavos, 'o gateway recebe o valor cheio');
        self::assertNull($this->gateway->ultimoPedido->linkDoPacote, 'com multiplicador, nunca o link fixo do pacote');

        // Reabrir com outro multiplicador não reaproveita a página aberta do ×4 (o valor é outro).
        $outro = $this->assinarCom($this->primario, 'avancado', 2);
        self::assertTrue($outro['ok'], json_encode($outro) ?: '');
        self::assertNotSame($resposta['url'], $outro['url']);
        self::assertSame(6990 * 2, $this->gateway->ultimoPedido?->valorCentavos);
        self::assertSame(2, $this->contarAssinaturas(), 'a página hospedada do ×4 fica, pode ter sido paga');

        $idNoGateway = (string) $pedido->getIdDoCheckout();
        $this->gateway->marcarComoPaga($idNoGateway, new \DateTimeImmutable('+1 month'));
        $this->webhook('evt-mul', $idNoGateway);

        $this->em->clear();
        $espaco = static::getContainer()->get(EspacoDoEscritorioRepository::class)->doTenant((int) $this->escritorio->getId());
        self::assertNotNull($espaco);
        self::assertSame('avancado', $espaco->getPlano()->getCodigo());
        self::assertSame(4, $espaco->getMultiplicador());
        self::assertSame(400 * 1024 ** 3, static::getContainer()->get(SituacaoDoEspaco::class)->doEscritorio((int) $this->escritorio->getId())->bytesLimite);
    }

    #[TestDox('VS 03.11.26: só o maior pacote dobra; multiplicador fora de 1, 2, 4, 8 e 16 é recusado')]
    public function testSoOMaiorDobra(): void
    {
        $resposta = $this->assinarCom($this->primario, 'essencial', 2);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Só o maior pacote pode ser dobrado.', $resposta['erro']);

        $resposta = $this->assinarCom($this->primario, 'avancado', 3);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha quantas vezes dobrar o espaço: 1, 2, 4, 8 e assim por diante, sempre dobrando.', $resposta['erro']);
        self::assertSame(0, $this->contarAssinaturas());
    }

    #[TestDox('VS 03.11.26: a chave "Ampliação de GB automática" grava por escritório; só o primário liga')]
    public function testAmpliacaoAutomatica(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('GET', '/contribua');
        self::assertSelectorExists('[data-checkout] .co-auto[aria-checked="false"]');

        $resposta = $this->post('/contribua/espaco/ampliacao-automatica', ['ligada' => '1']);
        self::assertTrue($resposta['ok'], json_encode($resposta) ?: '');
        self::assertTrue($resposta['ligada']);
        $this->em->clear();
        self::assertTrue(static::getContainer()->get(EspacoDoEscritorioRepository::class)->doTenant((int) $this->escritorio->getId())?->isAmpliacaoAutomatica());
        self::assertSame('free', $this->planoDoEscritorio(), 'ligar a chave não muda o plano');

        $this->client->request('GET', '/contribua');
        self::assertSelectorExists('[data-checkout] .co-auto[aria-checked="true"]');

        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);
        $resposta = $this->post('/contribua/espaco/ampliacao-automatica', ['ligada' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Quem amplia o espaço é o usuário primário do escritório.', $resposta['erro']);

        $this->client->request('POST', '/contribua/espaco/ampliacao-automatica', ['_token' => 'errado', 'ligada' => '0'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('VS 03.11.26: checkout com o + no topo, a linha "Precisa de mais?" com − e ×N, e a chave; só o maior pacote marca data-plano-maior')]
    public function testCheckoutComDobrarEChave(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $crawler = $this->client->request('GET', '/contribua');

        self::assertSame(1, $crawler->filter('.co-compra > .co-compra-topo > button.co-mais[data-co-mais][title="Dobrar o espaço e o valor"] > i.bi-plus-lg')->count());
        self::assertSame(1, $crawler->filter('.co-compra > .co-dobrar[data-co-dobrar] > button.co-menos[title="Reduzir pela metade"] > i.bi-dash-lg')->count());
        self::assertSelectorTextContains('.co-dobrar > span', 'Precisa de mais? Dobre o espaço e o valor');
        self::assertSame(1, $crawler->filter('.co-resumo > .co-ciclo + button.co-auto[role="switch"] > .co-auto-trilho > .co-auto-bola')->count());
        self::assertSelectorTextSame('.co-auto > b', 'Ampliação de GB automática');
        self::assertSame(['0', '0', '0', '1'], $crawler->filter('[data-pedir-plano]')->each(static fn ($b): string => (string) $b->attr('data-plano-maior')));
        self::assertSame(4, $crawler->filter('.ct-pacote > .ct-pacote-cabeca > .ct-pacote-cabeca-selos')->count(), 'o selo de ganho fica dentro do span do desenho, em todo pacote');
    }

    #[TestDox('Dobra automática: com a chave ligada, passar do contratado dobra espaço e valor no gateway e avisa o primário')]
    public function testDobraAutomatica(): void
    {
        $this->assinaturaPaga('essencial');
        $this->ligarAmpliacao();
        self::assertSame(20 * 1024 ** 3, $this->folga(), 'com a chave ligada, o envio pode passar uma dobra (20 GB) do limite');

        $resultado = $this->ampliar(25);

        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::AMPLIOU, $resultado);
        $pedido = $this->pedido();
        self::assertSame(2, $pedido->getMultiplicador());
        self::assertSame(3980, $pedido->getValorCentavos());
        self::assertSame([(string) $pedido->getIdDaAssinatura() => 3980], $this->gateway->valoresAlterados);
        self::assertSame(40 * 1024 ** 3, $this->limiteEmBytes(), 'o espaço dobra na hora');
        self::assertStringContainsString('Seu espaço passou de 20 GB: dobramos para 40 GB. O valor passa para R$ 39,80/mês a partir da próxima cobrança.', $this->ultimaNotificacaoDoPrimario());

        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::NADA, $this->ampliar(30), 'dentro do novo limite, nada');
        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::AMPLIOU, $this->ampliar(150), 'uso muito acima dobra quantas vezes precisar');
        self::assertSame(8, $this->pedido()->getMultiplicador());
        self::assertSame(1990 * 8, $this->pedido()->getValorCentavos());
    }

    #[TestDox('Dobra automática: chave desligada, sem assinatura ligada ou gateway recusando não muda nada (o Master é avisado da recusa)')]
    public function testDobraAutomaticaNaoAcontece(): void
    {
        $this->ligarAmpliacao();
        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::NADA, $this->ampliar(25), 'Free não tem assinatura para dobrar');

        $this->assinaturaPaga('essencial');
        $this->desligarAmpliacao();
        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::NADA, $this->ampliar(25), 'chave desligada');
        self::assertSame(0, $this->folga(), 'chave desligada não dá folga');

        $this->ligarAmpliacao();
        $this->gateway->recusarValor = true;
        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::FALHOU, $this->ampliar(25));
        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::FALHOU, $this->ampliar(26), 'o envio seguinte tenta de novo');
        $ampliar = static::getContainer()->get(AmpliarEspacoAutomaticamenteUseCase::class);
        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::NADA, $ampliar->executar((int) $this->escritorio->getId(), 27 * 1024 ** 3), 'na mesma requisição, uma tentativa só');
        self::assertSame(1, $this->pedido()->getMultiplicador());
        self::assertSame(1990, $this->pedido()->getValorCentavos());
        self::assertSame(20 * 1024 ** 3, $this->limiteEmBytes());
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tipo = 'contribua.ampliacao_falhou'", [$this->master->getId()]), 'o mesmo aviso ao Master no máximo uma vez por dia');
    }

    #[TestDox('Dobra automática: sem limite de vezes, dobra de novo mesmo com o pacote já dobrado muitas vezes')]
    public function testDobraAutomaticaSemLimite(): void
    {
        $this->assinaturaPaga('avancado', 16);
        $this->ligarAmpliacao();
        self::assertSame(1600 * 1024 ** 3, $this->folga(), 'a folga continua sendo de uma dobra');

        self::assertSame(AmpliarEspacoAutomaticamenteUseCase::AMPLIOU, $this->ampliar(1601));
        self::assertSame(32, $this->pedido()->getMultiplicador());
        self::assertSame(6990 * 32, $this->pedido()->getValorCentavos());
    }

    #[TestDox('Dobra automática: o envio que passa do limite chega pelo evento UsoDoEspacoSubiu')]
    public function testDobraPeloEvento(): void
    {
        $this->assinaturaPaga('essencial');
        $this->ligarAmpliacao();
        $tenantId = (int) $this->escritorio->getId();
        $retrato = static::getContainer()->get(SituacaoDoEspaco::class)->retratar($tenantId, 25 * 1024 ** 3);

        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new UsoDoEspacoSubiu($tenantId, 90, 125, $retrato));

        self::assertSame(2, $this->pedido()->getMultiplicador());
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

    #[TestDox('Página hospedada (Asaas): a janela não mostra o campo CPF/CNPJ do titular; a página do gateway pede')]
    public function testHospedadoSemCampoDeDocumento(): void
    {
        $config = static::getContainer()->get(ConfiguracaoDePagamentoRepository::class)->atual();
        $config->configurar('asaas', null, [], 7);
        $this->em->flush();

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $crawler = $this->client->request('GET', '/contribua');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-checkout][data-modo="redirecionar"][data-gateway="asaas"]')->count());
        self::assertSame(0, $crawler->filter('[data-checkout] [data-cartao-documento]')->count());
        self::assertStringNotContainsString('CPF/CNPJ DO TITULAR', $crawler->filter('[data-checkout] [data-co-form]')->text());
        self::assertSame(1, $crawler->filter('[data-co-form] > .co-meio + .co-hospedado')->count(), 'o aviso da página segura fica logo abaixo de "Cartão de crédito"');
        self::assertStringContainsString('página segura do Asaas', $crawler->filter('[data-co-form] > .co-hospedado')->text());
    }

    #[TestDox('Página hospedada: iniciar sem documento funciona, e o que vier no pedido é ignorado (o gateway não recebe)')]
    public function testIniciarHospedadoSemDocumento(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $resposta = $this->post('/contribua/assinatura', ['plano' => 'essencial']);

        self::assertTrue($resposta['ok'], json_encode($resposta) ?: '');
        self::assertSame('redirecionar', $resposta['modo']);
        self::assertNotNull($this->gateway->ultimoPedido);
        self::assertNull($this->gateway->ultimoPedido->pagador->documento);
        self::assertSame(SituacaoDaAssinatura::Aguardando, $this->pedido()->getSituacao());

        $denovo = $this->post('/contribua/assinatura', ['plano' => 'essencial', 'documento' => '123']);
        self::assertTrue($denovo['ok'], 'documento inválido (tela antiga em cache) não trava a página hospedada');
        self::assertSame($resposta['url'], $denovo['url'], 'reabre o mesmo checkout');
    }

    #[TestDox('Formulário de cartão (Stripe, Mercado Pago, Pagar.me): a janela mostra o campo CPF/CNPJ do titular')]
    public function testFormularioMostraCampoDeDocumento(): void
    {
        $pagamento = static::getContainer()->get(MontarPagamentoDoContribuaUseCase::class)->executar($this->solicitante());
        $pagamento['modo'] = MontarPagamentoDoContribuaUseCase::modoDoGateway('stripe');
        $pagamento['gateway'] = 'stripe';
        $pagamento['gatewayNome'] = 'Stripe';

        $html = static::getContainer()->get(Environment::class)->render('contribua/_checkout.html.twig', ['pagamento' => $pagamento, 'retorno' => null]);
        $crawler = new Crawler($html);

        self::assertSame(1, $crawler->filter('[data-checkout][data-modo="formulario"]')->count());
        self::assertSame(1, $crawler->filter('[data-co-form] > [data-co-elemento] + label.co-campo > input[data-cartao-documento]')->count());
        self::assertStringContainsString('CPF/CNPJ DO TITULAR', $crawler->filter('[data-co-form] > label.co-campo')->text());
        self::assertSame(0, $crawler->filter('.co-hospedado')->count());
    }

    #[TestDox('Formulário de cartão: iniciar continua exigindo o CPF/CNPJ do titular e o envia ao gateway')]
    public function testFormularioExigeDocumentoAoIniciar(): void
    {
        $config = static::getContainer()->get(ConfiguracaoDePagamentoRepository::class)->atual();
        $config->configurar('stripe', 'pk_test_formulario', [], 7);
        $this->em->flush();
        // Um gateway de formulário com o código do Stripe: o 'falso' do container é página hospedada para a janela.
        $stripe = new class () implements GatewayDeAssinatura {
            public ?PedidoDeCheckout $ultimoPedido = null;

            public function codigo(): string
            {
                return 'stripe';
            }

            public function iniciar(PedidoDeCheckout $pedido): InicioDoCheckout
            {
                $this->ultimoPedido = $pedido;

                return InicioDoCheckout::formulario('pk_test_formulario');
            }

            public function assinarComToken(PedidoDeCheckout $pedido, string $tokenDoCartao): SituacaoNoGateway
            {
                throw new \LogicException('Fora deste teste.');
            }

            public function consultar(string $idDaAssinatura): SituacaoNoGateway
            {
                throw new \LogicException('Fora deste teste.');
            }

            public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway
            {
                return null;
            }

            public function cancelar(string $idDaAssinatura): void
            {
            }

            public function alterarValor(string $idDaAssinatura, int $valorCentavos): void
            {
            }
        };
        $iniciar = $this->iniciarCom($stripe);

        foreach (['', '123'] as $documento) {
            try {
                $iniciar->executar($this->solicitante(), 'essencial', $documento, null);
                self::fail('iniciou sem o CPF/CNPJ do titular: "' . $documento . '"');
            } catch (\DomainException $recusa) {
                self::assertSame('Informe o CPF ou CNPJ do titular.', $recusa->getMessage());
            }
        }
        self::assertSame(0, $this->contarAssinaturas(), 'sem documento nada é gravado');
        self::assertNull($stripe->ultimoPedido, 'sem documento nada vai ao gateway');

        $inicio = $iniciar->executar($this->solicitante(), 'essencial', '123.456.789-09', null);

        self::assertSame(InicioDoCheckout::FORMULARIO, $inicio->modo);
        self::assertNotNull($stripe->ultimoPedido);
        self::assertSame('12345678909', $stripe->ultimoPedido->pagador->documento);
    }

    #[TestDox('Formulário de cartão: o token sem CPF/CNPJ do titular é recusado e o pedido segue aguardando')]
    public function testFormularioExigeDocumentoNoToken(): void
    {
        $this->gateway->modo = InicioDoCheckout::FORMULARIO;
        $inicio = $this->assinar($this->primario);

        $resposta = $this->post('/contribua/assinatura/' . $inicio['pedido'] . '/token', ['token' => 'tok_falso']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o CPF ou CNPJ do titular.', $resposta['erro']);
        self::assertSame(SituacaoDaAssinatura::Aguardando, $this->pedido()->getSituacao());
        self::assertNull($this->pedido()->getIdDaAssinatura(), 'nada foi ao gateway');
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

    #[TestDox('Checkout sem cliente (Asaas): a assinatura se liga ao pedido do checkout de origem, não ao aberto mais novo do escritório')]
    public function testAssinaturaLigadaPeloCheckoutDeOrigem(): void
    {
        $primeiro = $this->assinar($this->primario);
        $outro = $this->assinar($this->primario, 'profissional');
        self::assertTrue($outro['ok'], json_encode($outro) ?: '');
        // Referência só do escritório e sem valor: pelo palpite antigo, iria ao pedido aberto mais novo (profissional).
        $this->assinaturaNoGateway('sub_do_checkout', $this->checkoutDoPedido((int) $primeiro['pedido']), sprintf('BJ%06d', (int) $this->escritorio->getId()));

        self::assertSame('processado', $this->webhook('evt-checkout', 'sub_do_checkout')['resultado']);

        $ligado = $this->pedidoNoBanco((int) $primeiro['pedido']);
        self::assertSame('sub_do_checkout', $ligado['id_da_assinatura']);
        self::assertSame('cus_do_pagador', $ligado['id_do_cliente']);
        self::assertSame(SituacaoDaAssinatura::Ativa->value, $ligado['situacao']);
        self::assertSame(SituacaoDaAssinatura::Aguardando->value, $this->pedidoNoBanco((int) $outro['pedido'])['situacao']);
        self::assertNull($this->pedidoNoBanco((int) $outro['pedido'])['id_da_assinatura']);
        self::assertSame('essencial', $this->planoDoEscritorio());
    }

    #[TestDox('Checkout de pedido cancelado: a assinatura não vai a outro pedido e o Master é avisado pelo escritório do checkout')]
    public function testCheckoutDePedidoCanceladoNaoLigaAOutro(): void
    {
        $primeiro = $this->assinar($this->primario);
        $checkoutDoPrimeiro = $this->checkoutDoPedido((int) $primeiro['pedido']);
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE contribua_assinatura SET situacao = 'cancelada', cancelada_em = NOW() WHERE id = ?", [(int) $primeiro['pedido']]);
        $segundo = $this->assinar($this->primario);
        self::assertNotSame($primeiro['pedido'], $segundo['pedido']);

        // Sem referência nenhuma: o escritório do aviso ao Master vem do pedido que abriu o checkout.
        $this->assinaturaNoGateway('sub_sem_pedido', $checkoutDoPrimeiro, null);
        $this->webhook('evt-sem-pedido', 'sub_sem_pedido');
        // Com a referência do escritório: nem assim vai ao pedido aberto de mesmo valor.
        $this->assinaturaNoGateway('sub_sem_pedido_2', $checkoutDoPrimeiro, sprintf('BJ%06d', (int) $this->escritorio->getId()));
        $this->webhook('evt-sem-pedido-2', 'sub_sem_pedido_2');

        self::assertSame(SituacaoDaAssinatura::Cancelada->value, $this->pedidoNoBanco((int) $primeiro['pedido'])['situacao']);
        self::assertNull($this->pedidoNoBanco((int) $primeiro['pedido'])['id_da_assinatura']);
        self::assertNull($this->pedidoNoBanco((int) $segundo['pedido'])['id_da_assinatura']);
        self::assertSame('free', $this->planoDoEscritorio());
        self::assertSame(2, $this->avisosSemPedidoAoMaster('Assinatura paga sem pedido no BlueJus: ' . $this->escritorio->getName() . ', '), 'os dois avisos levam o nome deste escritório');
    }

    #[TestDox('Checkout que o BlueJus não conhece e assinatura sem referência: o Master é avisado sem escritório, uma vez só')]
    public function testAssinaturaSemPedidoNemEscritorioAvisaOMaster(): void
    {
        $this->assinaturaNoGateway('sub_de_ninguem', 'chk_que_o_bluejus_nao_abriu', null);

        self::assertSame('processado', $this->webhook('evt-de-ninguem', 'sub_de_ninguem')['resultado']);
        $this->webhook('evt-de-ninguem-2', 'sub_de_ninguem');

        self::assertSame(1, $this->avisosSemPedidoAoMaster('Assinatura paga sem pedido no BlueJus e sem escritório identificado: sub_de_ninguem '));
        self::assertSame('free', $this->planoDoEscritorio());
    }

    #[TestDox('Cross-tenant: checkout de outro escritório com a referência deste não liga a nenhum dos dois')]
    public function testCheckoutDeOutroEscritorioNaoLiga(): void
    {
        $proprio = $this->assinar($this->primario);
        $outroEscritorio = $this->tenant();
        $plano = static::getContainer()->get(PlanoRepository::class)->findOneBy(['codigo' => 'essencial']);
        self::assertInstanceOf(Plano::class, $plano);
        $alheio = new AssinaturaDeEspaco($outroEscritorio, $plano, 'falso', $plano->getPrecoCentavos(), null);
        $alheio->registrarInicio('chk_de_outro_escritorio', 'https://gateway.falso/checkout/chk_de_outro_escritorio');
        $this->em->persist($alheio);
        $this->em->flush();
        $idDoAlheio = (int) $alheio->getId();

        $this->assinaturaNoGateway('sub_cruzada', 'chk_de_outro_escritorio', sprintf('BJ%06d', (int) $this->escritorio->getId()));
        // O webhook chega sem sessão: em produção o filtro de tenant está desligado. Aqui o kernel não reinicia
        // entre as requisições e o filtro do primário ficaria ligado, escondendo o pedido do outro escritório.
        if ($this->em->getFilters()->isEnabled('tenant')) {
            $this->em->getFilters()->disable('tenant');
        }
        $this->webhook('evt-cruzado', 'sub_cruzada');

        self::assertNull($this->pedidoNoBanco($idDoAlheio)['id_da_assinatura']);
        self::assertSame(SituacaoDaAssinatura::Aguardando->value, $this->pedidoNoBanco($idDoAlheio)['situacao']);
        self::assertNull($this->pedidoNoBanco((int) $proprio['pedido'])['id_da_assinatura']);
        self::assertSame('free', $this->planoDoEscritorio());
        // Referência e checkout discordam: o aviso leva o escritório da referência (vem antes do checkout).
        self::assertSame(1, $this->avisosSemPedidoAoMaster('Assinatura paga sem pedido no BlueJus: ' . $this->escritorio->getName() . ', sub_cruzada '));
        self::assertSame(0, $this->avisosSemPedidoAoMaster('Assinatura paga sem pedido no BlueJus: ' . $outroEscritorio->getName() . ', '));
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
        $this->client->request('POST', '/contribua/pix', ['_token' => self::TOKEN, 'valor' => '999999999']);

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['valido'] ?? false);
    }

    /** @return array<string, mixed> */
    private function assinar(User $quem, string $plano = 'essencial'): array
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);

        return $this->post('/contribua/assinatura', ['plano' => $plano, 'documento' => '123.456.789-09']);
    }

    private function solicitante(): Solicitante
    {
        return new Solicitante($this->primario, $this->escritorio, false, true);
    }

    /** O caso de uso real com só o gateway trocado (o resto vem do container de teste). */
    private function iniciarCom(GatewayDeAssinatura $gateway): IniciarAssinaturaUseCase
    {
        $c = static::getContainer();
        $configuracao = $c->get(ConfiguracaoDePagamentoRepository::class);

        return new IniciarAssinaturaUseCase(
            new GatewaysDeAssinatura([$gateway], $configuracao),
            $c->get(PlanoRepository::class),
            $c->get(SituacaoDoEspaco::class),
            $c->get(AssinaturaDeEspacoRepository::class),
            $configuracao,
            $c->get(UrlGeneratorInterface::class),
            $c->get(LoggerInterface::class),
            $c->get(Connection::class),
        );
    }

    private function assinaturaPaga(string $plano, int $multiplicador = 1): void
    {
        $resposta = $this->assinarCom($this->primario, $plano, $multiplicador);
        self::assertTrue($resposta['ok'], json_encode($resposta) ?: '');
        $idNoGateway = (string) $this->pedido()->getIdDoCheckout();
        $this->gateway->marcarComoPaga($idNoGateway, new \DateTimeImmutable('+1 month'));
        $this->webhook('evt-pago-' . uniqid(), $idNoGateway);
        self::assertSame(SituacaoDaAssinatura::Ativa, $this->pedido()->getSituacao());
    }

    private function ligarAmpliacao(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertTrue($this->post('/contribua/espaco/ampliacao-automatica', ['ligada' => '1'])['ok']);
    }

    private function desligarAmpliacao(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertTrue($this->post('/contribua/espaco/ampliacao-automatica', ['ligada' => '0'])['ok']);
    }

    private function ampliar(int $gbUsados): string
    {
        $this->em->clear();
        $ampliar = static::getContainer()->get(AmpliarEspacoAutomaticamenteUseCase::class);
        $ampliar->reset();

        return $ampliar->executar((int) $this->escritorio->getId(), $gbUsados * 1024 ** 3);
    }

    private function folga(): int
    {
        $this->em->clear();

        return static::getContainer()->get(AmpliarEspacoAutomaticamenteUseCase::class)->folgaDaAmpliacao((int) $this->escritorio->getId());
    }

    private function limiteEmBytes(): ?int
    {
        $this->em->clear();

        return static::getContainer()->get(SituacaoDoEspaco::class)->doEscritorio((int) $this->escritorio->getId())->bytesLimite;
    }

    private function ultimaNotificacaoDoPrimario(): string
    {
        return (string) static::getContainer()->get(Connection::class)->fetchOne("SELECT titulo FROM notificacao WHERE usuario_id = ? AND tipo = 'contribua.assinatura' ORDER BY id DESC LIMIT 1", [$this->primario->getId()]);
    }

    /** @return array<string, mixed> */
    private function assinarCom(User $quem, string $plano, int $multiplicador): array
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);

        return $this->post('/contribua/assinatura', ['plano' => $plano, 'documento' => '123.456.789-09', 'multiplicador' => (string) $multiplicador]);
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

    /**
     * A linha do pedido lida direto no banco: o filtro de tenant que ficou no EntityManager não esconde o
     * pedido de outro escritório.
     *
     * @return array<string, mixed>
     */
    private function pedidoNoBanco(int $id): array
    {
        $linha = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT situacao, id_da_assinatura, id_do_cliente, id_do_checkout FROM contribua_assinatura WHERE id = ?', [$id]);
        self::assertIsArray($linha, 'pedido ' . $id . ' não existe');

        return $linha;
    }

    /** Avisos "assinatura paga sem pedido" do Master cujo título começa pelo texto dado. */
    private function avisosSemPedidoAoMaster(string $inicioDoTitulo): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tipo = ? AND LEFT(titulo, ?) = ?',
            [$this->master->getId(), 'contribua.assinatura', mb_strlen($inicioDoTitulo), $inicioDoTitulo],
        );
    }

    private function checkoutDoPedido(int $id): string
    {
        $checkout = $this->pedidoNoBanco($id)['id_do_checkout'];
        self::assertIsString($checkout);

        return $checkout;
    }

    /**
     * A assinatura como o Asaas a devolve quando nasce do checkout sem cliente: com o checkout de origem, o
     * cliente que o pagador cadastrou e, talvez, uma referência; sem valor, para não ajudar o palpite antigo.
     */
    private function assinaturaNoGateway(string $id, string $checkout, ?string $referencia): void
    {
        $this->gateway->assinaturas[$id] = new SituacaoNoGateway($id, SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('+1 month'), $referencia, 'cus_do_pagador', null, idDoCheckout: $checkout);
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
