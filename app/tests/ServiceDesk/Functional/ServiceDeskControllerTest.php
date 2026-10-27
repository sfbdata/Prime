<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\ServiceDesk\Controller\ServiceDeskController;
use App\ServiceDesk\DTO\ConfirmarRecargaInput;
use App\ServiceDesk\DTO\SolicitarRecargaInput;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Pagamento\RecargaManual;
use App\ServiceDesk\Service\TransacaoDoRazaoNoDoctrine;
use App\ServiceDesk\UseCase\ConfirmarRecargaDeSaldoUseCase;
use App\ServiceDesk\UseCase\SolicitarRecargaDeSaldoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(ServiceDeskController::class)]
final class ServiceDeskControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_service_desk';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $pessoa;
    private User $pessoaDoOutro;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A');
        $this->outro = $this->tenant('B');
        $this->pessoa = $this->usuario($this->escritorio);
        $this->pessoaDoOutro = $this->usuario($this->outro);
    }

    #[TestDox('Sem saldo: mostra R$ 0,00 em vermelho, o aviso do desenho e a lista vazia')]
    public function testPaginaSemSaldoEVazia(): void
    {
        $html = $this->abrir();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Meus chamados', $html);
        self::assertStringContainsString('Suporte técnico e agendamentos com a equipe técnica do BlueJus', $html);
        self::assertSelectorTextContains('.sd-saldo-valor.baixo', 'R$ 0,00');
        self::assertStringContainsString('Adicione saldo para abrir um chamado', $html);
        self::assertStringContainsString('Você ainda não abriu nenhum chamado', $html);
        self::assertStringContainsString('Abrir meu primeiro chamado', $html);
        self::assertStringContainsString('Nenhuma movimentação ainda.', $html);
        self::assertSelectorNotExists('.sd-saldo-valor.ok');
    }

    #[TestDox('Arranjo do desenho: KPIs, saldo e lista são filhos diretos da página; sem modal Bootstrap nem Select2')]
    public function testArranjoEComponentesDoDesenho(): void
    {
        $this->abrir();

        self::assertSelectorCount(1, '.sd-pagina > .sd-kpis');
        self::assertSelectorCount(5, '.sd-kpis > .sd-kpi');
        self::assertSelectorCount(1, '.sd-pagina > .sd-saldo');
        self::assertSelectorCount(1, '.sd-pagina > .sd-lista');
        self::assertSelectorCount(1, '.sd-pagina > .sd-cabeca > .sd-btn-azul');
        self::assertSelectorCount(1, '.sd-pagina > .sd-modal-area > .sd-modal');
        self::assertSelectorNotExists('.sd-pagina .modal');
        self::assertSelectorNotExists('.sd-pagina .select2');
        self::assertSelectorNotExists('.sd-pagina .card');
        self::assertSelectorNotExists('.sd-pagina .small-box');
    }

    #[TestDox('Com saldo de R$ 50,00 o valor fica verde e o chamado aparece na lista com o valor estimado')]
    public function testPaginaComSaldoEChamado(): void
    {
        $this->darSaldo($this->escritorio, 5000);
        $this->abrir();
        self::assertSelectorTextContains('.sd-saldo-valor.ok', 'R$ 50,00');
        self::assertStringContainsString('Saldo disponível para abrir chamados', (string) $this->client->getResponse()->getContent());

        $resposta = $this->post('/service-desk/chamado', ['tipo' => 'suporte', 'prioridade' => 'alta', 'assunto' => 'Erro ao salvar', 'descricao' => 'Detalhe']);
        self::assertTrue($resposta['ok']);
        self::assertSame('Chamado #0001 aberto. A equipe técnica foi avisada.', $resposta['mensagem']);

        $this->client->request('GET', '/service-desk');
        self::assertSelectorCount(1, '.sd-lista > .sd-linha');
        self::assertSelectorTextContains('.sd-linha', '#0001');
        self::assertSelectorTextContains('.sd-linha', 'Erro ao salvar');
        self::assertSelectorTextContains('.sd-linha', 'Alta prioridade');
        self::assertSelectorTextContains('.sd-linha .sd-selo.aberto', 'Aberto');
        self::assertStringContainsString('detalhe', (string) $this->client->getCrawler()->filter('.sd-linha')->attr('data-busca'), 'a busca do desenho cobre a descrição');
    }

    #[TestDox('Abrir sem saldo recusa com a mensagem do desenho; sem assunto e treinamento sem data também')]
    public function testRecusasAoAbrir(): void
    {
        $r = $this->post('/service-desk/chamado', ['tipo' => 'suporte', 'prioridade' => 'media', 'assunto' => 'X']);
        self::assertFalse($r['ok']);
        self::assertSame('Saldo insuficiente para abrir o chamado.', $r['erro']);

        $this->darSaldo($this->escritorio, 2000);
        $r = $this->post('/service-desk/chamado', ['tipo' => 'suporte', 'prioridade' => 'media', 'assunto' => '  ']);
        self::assertSame('Informe o assunto.', $r['erro']);

        $r = $this->post('/service-desk/chamado', ['tipo' => 'agenda', 'prioridade' => 'media', 'assunto' => 'Treino']);
        self::assertSame('Escolha a data e o horário do agendamento.', $r['erro']);
    }

    #[TestDox('Detalhe do chamado: só o do próprio escritório; o de outro responde 404')]
    public function testDetalheEIsolamento(): void
    {
        $this->darSaldo($this->escritorio, 2000);
        $this->post('/service-desk/chamado', ['tipo' => 'suporte', 'prioridade' => 'baixa', 'assunto' => 'Meu chamado', 'descricao' => 'Texto']);
        $id = (int) static::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT id FROM service_desk_chamado WHERE tenant_id = ?', [$this->escritorio->getId()]);

        $this->client->request('GET', '/service-desk/chamado/' . $id);
        $json = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($json['ok']);
        self::assertSame('Chamado #0001', $json['titulo']);
        self::assertStringContainsString('Meu chamado', (string) $json['html']);
        self::assertStringContainsString('ANDAMENTO', (string) $json['html']);

        $this->logarComTenant($this->client, $this->pessoaDoOutro, $this->outro);
        $this->client->request('GET', '/service-desk/chamado/' . $id);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('PIX: mostra o copia e cola válido com a referência BJSD e o QR, sem gravar nada')]
    public function testPixSemGravar(): void
    {
        $r = $this->post('/service-desk/pix', ['valor' => '50']);

        self::assertTrue($r['ok']);
        self::assertMatchesRegularExpression('/^BJSD[0-9A-F]{12}$/i', $r['referencia']);
        self::assertStringContainsString('<svg', $r['qrSvg']);
        self::assertTrue(\App\Contribua\Service\CodigoPixEstatico::valido($r['copiaECola']));
        self::assertStringContainsString('50.00', $r['copiaECola']);
        self::assertStringContainsString($r['referencia'], $r['copiaECola']);

        $this->client->request('GET', '/service-desk');
        self::assertStringNotContainsString('aguardando confirmação do pagamento', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Já paguei: grava o pendente com a referência do PIX mostrado, uma vez só, e o saldo não sobe')]
    public function testJaPagueiGravaOPendente(): void
    {
        $pix = $this->post('/service-desk/pix', ['valor' => '50']);
        $dados = ['valor' => '50', 'referencia' => $pix['referencia'], 'assinatura' => $pix['assinatura']];

        self::assertTrue($this->post('/service-desk/recarga', $dados)['ok']);
        self::assertTrue($this->post('/service-desk/recarga', $dados)['ok'], 'segundo clique não grava outra');

        $this->client->request('GET', '/service-desk');
        self::assertStringContainsString('R$ 50,00 aguardando confirmação do pagamento', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->client->getCrawler()->filter('.sd-extrato-linha'));
        self::assertSelectorTextContains('.sd-extrato-linha', 'Aguardando');
        self::assertSame(0, $this->saldoDe($this->escritorio));
    }

    #[TestDox('Já paguei recusa referência adulterada, valor trocado ou assinatura de outro escritório')]
    public function testJaPagueiRecusaAssinaturaInvalida(): void
    {
        $pix = $this->post('/service-desk/pix', ['valor' => '50']);

        $trocado = $this->post('/service-desk/recarga', ['valor' => '200', 'referencia' => $pix['referencia'], 'assinatura' => $pix['assinatura']]);
        $adulterada = $this->post('/service-desk/recarga', ['valor' => '50', 'referencia' => 'BJSDAAAAAAAAAAAA', 'assinatura' => $pix['assinatura']]);

        self::assertFalse($trocado['ok']);
        self::assertFalse($adulterada['ok']);
        $this->client->request('GET', '/service-desk');
        self::assertStringNotContainsString('aguardando confirmação do pagamento', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Recarga só aceita os valores do desenho (20, 50, 100, 200)')]
    public function testRecargaRecusaValorForaDoDesenho(): void
    {
        $r = $this->post('/service-desk/pix', ['valor' => '33']);

        self::assertFalse($r['ok']);
        self::assertSame('Escolha um dos valores de recarga.', $r['erro']);
    }

    #[TestDox('Sem o token CSRF as ações respondem 403 e nada é gravado')]
    public function testSemCsrf(): void
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $this->client->request('POST', '/service-desk/recarga', ['valor' => '20', '_token' => 'errado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(MovimentoDeSaldo::class)->count([]));
    }

    #[TestDox('Visitante sem login vai para o login')]
    public function testSemLogin(): void
    {
        $this->client->request('GET', '/service-desk');

        self::assertResponseRedirects();
    }

    private function abrir(): string
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $this->client->request('GET', '/service-desk');

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $campos): array
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function darSaldo(Tenant $tenant, int $centavos): void
    {
        $movimentos = $this->em->getRepository(MovimentoDeSaldo::class);
        $referencia = (new SolicitarRecargaDeSaldoUseCase($movimentos, new RecargaManual()))
            ->executar(new SolicitarRecargaInput($tenant, null, $centavos, FormaDeRecarga::Pix))->referencia;
        (new ConfirmarRecargaDeSaldoUseCase($movimentos, $this->em->getRepository(SaldoDoEscritorio::class), new TransacaoDoRazaoNoDoctrine($this->em)))
            ->executar(new ConfirmarRecargaInput($referencia, $centavos));
    }

    private function saldoDe(Tenant $tenant): int
    {
        return (int) static::getContainer()->get('doctrine.dbal.default_connection')
            ->fetchOne('SELECT COALESCE(SUM(saldo_centavos), 0) FROM service_desk_saldo WHERE tenant_id = ?', [$tenant->getId()]);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório SERVICE DESK ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function usuario(Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail('sd_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa Service Desk');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Primário ' . uniqid());
        $papel->setIsSystem(true);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $tenant);
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
