<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\ContribuaController;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Twig\AvisoDeEspacoExtension;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(ContribuaController::class)]
#[CoversClass(AvisoDeEspacoExtension::class)]
final class ContribuaControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $primario;
    private User $secundario;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A');
        $this->outro = $this->tenant('B');
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->secundario = $this->pessoa('secundario', $this->escritorio, false);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
        $this->em->persist(new Sugestao($this->outro, null, 'Pessoa do B', TipoDeSugestao::Falta, 'Sugestão que só o B e o Master veem'));
        $this->em->flush();
    }

    #[TestDox('A página abre com os textos do desenho e o PIX do escritório')]
    public function testPaginaAbre(): void
    {
        $html = $this->abrir($this->primario);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('BlueJus, o escritório digital', $html);
        self::assertStringContainsString('RECOMENDADO PARA VOCÊ', $html);
        self::assertStringContainsString('Quero +10 GB', $html);
        self::assertStringContainsString('PIX válido · conta Nº ' . str_pad((string) $this->escritorio->getId(), 6, '0', \STR_PAD_LEFT), $html);
        self::assertStringContainsString('Já contribuí, registrar meu apoio', $html);
        self::assertStringContainsString('Quem somos e contribua', $html, 'título na barra azul');
        self::assertStringNotContainsString('Marcar atendida', $html);
    }

    #[TestDox('Indique e ganhe no topo: saldo, prêmio, cupom BJ- do link curto, copiar, compartilhar e contadores; dica "ganhe saldo"')]
    public function testIndiqueEGanhe(): void
    {
        $html = $this->abrir($this->primario);

        self::assertSelectorCount(1, '.ct-pagina > section#indique.ct-indique:first-child');
        self::assertSelectorTextContains('.ct-indique > .ct-indique-saldo .ct-indique-rotulo', 'SEU SALDO DE RECOMPENSA');
        self::assertSelectorTextSame('.ct-indique-valor', 'R$ 0,00');
        self::assertSelectorTextContains('.ct-indique-situacao', 'Libera com 10 escritórios ativos com pastas · você tem 0');
        self::assertSelectorTextContains('.ct-indique-titulo', 'Indique o BlueJus e ganhe R$ 10 por escritório');
        self::assertMatchesRegularExpression('#data-cupom>BJ-[A-HJ-NP-Z2-9]{8}<#', $html);
        self::assertSelectorTextContains('.ct-indique-cupom > [data-copiar-cupom]', 'Copiar cupom');
        self::assertSelectorTextContains('.ct-indique-cupom > .ct-indique-compartilhar[data-ind-abrir]', 'Compartilhar');
        self::assertSelectorTextContains('.ct-indique-contadores', '0 convite(s) enviado(s)');
        self::assertSelectorTextContains('.ct-indique-contadores', '0 aguardando confirmação');
        self::assertSelectorTextContains('.ct-indique-contadores', '0 conta(s) aberta(s)');
        self::assertSelectorExists('.bj-share[data-tip="Indique o BlueJus e ganhe saldo"]');
        self::assertSelectorTextContains('.ct-indique-texto', 'você ganha crédito quando o escritório indicado começar a usar o BlueJus. O saldo fica guardado e nunca expira.');
        self::assertStringNotContainsString('—', $this->client->getCrawler()->filter('#indique')->text());
    }

    #[TestDox('Com o programa de recompensa desligado no Master, o cartão do saldo some')]
    public function testIndiqueSomeComProgramaDesligado(): void
    {
        $programa = $this->em->find(ProgramaDeRecompensa::class, ProgramaDeRecompensa::ID_UNICO) ?? new ProgramaDeRecompensa();
        $programa->alterarRegras(false, 1000, 10, 0, 50, true);
        $this->em->persist($programa);
        $this->em->flush();

        $this->abrir($this->primario);

        self::assertSelectorNotExists('#indique');
        self::assertSelectorExists('.ct-pagina');
    }

    #[TestDox('O pacote Free mostra o selo do convênio OAB, que abre o modal; os pagos mostram "+ Espaço"')]
    public function testConvenioOabEMaisEspaco(): void
    {
        $this->abrir($this->primario);

        self::assertSelectorCount(1, '.ct-pacote > .ct-oab-selo[data-abrir-oab]');
        self::assertSelectorTextContains('.ct-oab-selo', 'CONVÊNIO OAB');
        self::assertSelectorExists('[data-modal-oab][hidden] [role="dialog"]');
        self::assertSelectorTextContains('[data-modal-oab]', 'Parabéns! Você tem o BlueJus completo, sem pagar nada.');
        self::assertSelectorCount(8, '[data-modal-oab] .ct-oab-beneficio');
        self::assertSelectorExists('.ct-pacote > .ct-nuvem');
        self::assertSelectorNotExists('.ct-pacote--atual > .ct-nuvem');
    }

    #[TestDox('O escritório não vê as sugestões de outro; o Master vê todas e pode atender')]
    public function testIsolamentoDasSugestoes(): void
    {
        self::assertStringNotContainsString('Sugestão que só o B e o Master veem', $this->abrir($this->primario));

        $doMaster = $this->abrir($this->master);
        self::assertStringContainsString('Sugestão que só o B e o Master veem', $doMaster);
        self::assertStringContainsString('Marcar atendida', $doMaster);
    }

    #[TestDox('Sugestão curta é recusada; a válida é gravada no escritório e avisa o Master')]
    public function testEnviarSugestao(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        $curta = $this->post('/contribua/sugestao', ['tipo' => 'sug', 'texto' => 'curta']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escreva um pouco mais para entendermos a sua sugestão.', $curta['erro']);

        $ok = $this->post('/contribua/sugestao', ['tipo' => 'prob', 'texto' => 'O filtro some quando volto para a lista']);
        self::assertTrue($ok['ok']);
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM contribua_sugestao WHERE tenant_id = :t AND tipo = 'prob'", $this->escritorio));
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM notificacao WHERE usuario_id = {$this->master->getId()} AND tipo = 'contribua.sugestao' AND tenant_id = :t", $this->escritorio));
    }

    #[TestDox('Atender sugestão: 403 para quem não é Master; o Master atende a de outro escritório')]
    public function testAtender(): void
    {
        $id = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM contribua_sugestao WHERE tenant_id = :t', ['t' => $this->outro->getId()]);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', "/contribua/sugestao/{$id}/atender", ['_token' => self::TOKEN, 'resposta' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->client->request('POST', "/contribua/sugestao/{$id}/atender", ['_token' => self::TOKEN, 'resposta' => 'Entrou na versão 2.4']);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['atendida' => true, 'resposta' => 'Entrou na versão 2.4'],
            static::getContainer()->get(Connection::class)->fetchAssociative('SELECT atendida, resposta FROM contribua_sugestao WHERE id = :id', ['id' => $id]),
        );
    }

    #[TestDox('Atender: o secundário também recebe 403')]
    public function testAtenderSecundario(): void
    {
        $id = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM contribua_sugestao WHERE tenant_id = :t', ['t' => $this->outro->getId()]);
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        $this->client->request('POST', "/contribua/sugestao/{$id}/atender", ['_token' => self::TOKEN]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Sugestão longa com nomes longos: grava uma vez e o aviso ao Master cabe no título')]
    public function testSugestaoLonga(): void
    {
        $this->escritorio->setName(str_repeat('Escritório Grande ', 3));
        $this->secundario->setFullName(str_repeat('Nome Comprido ', 3));
        $this->em->flush();
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        self::assertTrue($this->post('/contribua/sugestao', ['tipo' => 'sug', 'texto' => str_repeat('Texto longo de sugestão. ', 20)])['ok']);

        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM contribua_sugestao WHERE tenant_id = :t', $this->escritorio));
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM notificacao WHERE tipo = 'contribua.sugestao' AND tenant_id = :t AND char_length(titulo) <= 255", $this->escritorio));
    }

    #[TestDox('Números isolados: o escritório conta só os próprios apoios; o Master conta todos')]
    public function testNumerosIsolados(): void
    {
        $this->em->persist(new \App\Contribua\Entity\Apoio($this->outro, null, 2000));
        $this->em->flush();

        self::assertMatchesRegularExpression('#APOIOS REGISTRADOS</div><b>0</b>#', $this->abrir($this->primario));
        self::assertMatchesRegularExpression('#APOIOS REGISTRADOS</div><b>[1-9]\d*</b>#', $this->abrir($this->master));
    }

    #[TestDox('Apoio: um por mês; o segundo é recusado com o texto do desenho')]
    public function testApoioUmPorMes(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        self::assertTrue($this->post('/contribua/apoio', ['centavos' => 5000])['ok']);
        $segundo = $this->post('/contribua/apoio', ['centavos' => 5000]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Seu apoio deste mês já está registrado. Obrigado!', $segundo['erro']);
        self::assertStringContainsString('Obrigado! Apoio registrado este mês', $this->abrir($this->primario));
    }

    #[TestDox('Motivo anônimo: gravado só com o escritório e a pergunta some')]
    public function testMotivo(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        self::assertTrue($this->post('/contribua/motivo', ['motivo' => 'Sem orçamento agora'])['ok']);

        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM contribua_motivo_de_nao_apoiar WHERE tenant_id = :t', $this->escritorio));
        self::assertStringNotContainsString('Ainda não é o momento de contribuir?', $this->abrir($this->secundario));
    }

    #[TestDox('Pedir espaço: só o primário, e só plano maior que o atual')]
    public function testPedirEspaco(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);
        $this->post('/contribua/espaco/pedido', ['plano' => 'essencial']);
        self::assertResponseStatusCodeSame(422);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertSame('Pedido registrado. Avisaremos quando a assinatura por cartão for liberada.', $this->post('/contribua/espaco/pedido', ['plano' => 'essencial'])['mensagem']);
        $this->post('/contribua/espaco/pedido', ['plano' => 'free']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Ação sem o token CSRF é recusada')]
    public function testCsrf(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', '/contribua/apoio', ['_token' => 'errado', 'centavos' => 2000]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM contribua_apoio WHERE tenant_id = :t', $this->escritorio));
    }

    #[TestDox('PIX para outro valor vem válido e com o txid da conta')]
    public function testPixPorValor(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('GET', '/contribua/pix?valor=50');

        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($dados['valido']);
        self::assertStringContainsString('540550.00', $dados['codigo']);
        self::assertStringContainsString('BJ' . str_pad((string) $this->escritorio->getId(), 6, '0', \STR_PAD_LEFT), $dados['codigo']);
    }

    #[TestDox('Aviso de espaço a partir de 90%: aparece para o primário, não para o secundário')]
    public function testAvisoDeEspaco(): void
    {
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn((int) (0.93 * 10 * RetratoDoEspaco::BYTES_POR_GB));
        static::getContainer()->set(ConsumoDeArmazenamentoRepository::class, $consumo);

        $doPrimario = $this->abrir($this->primario);
        self::assertStringContainsString('Seu espaço está 93% ocupado', $doPrimario);
        self::assertStringContainsString('9,3 GB de 10 GB no plano Free. Faltam 0,7 GB.', $doPrimario);
        self::assertStringNotContainsString('Seu espaço está 93% ocupado', $this->abrir($this->secundario));
    }

    #[TestDox('Menu do avatar tem o item Contribua · PIX')]
    public function testItemDoMenu(): void
    {
        $html = $this->abrir($this->secundario);

        self::assertMatchesRegularExpression('#href="/contribua"[^>]*class="dropdown-item mu-item"[\s\S]*?Contribua[\s\S]*?PIX#', $html);
    }

    private function abrir(User $quem): string
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('GET', '/contribua');

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $campos): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function contar(string $sql, Tenant $tenant): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne($sql, ['t' => $tenant->getId()]);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório CONTRIBUA ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, Tenant $tenant, bool $primario, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid());
        $papel->setIsSystem($primario);
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
