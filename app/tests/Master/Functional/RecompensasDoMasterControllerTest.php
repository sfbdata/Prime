<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Indicacao\Entity\Indicacao;
use App\Master\Controller\RecompensasDoMasterController;
use App\Recompensa\Entity\AprovacaoDeContaDeRecompensa;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Seção Recompensas do /master, F1 a F3 (extrato, lançamentos e antifraude; desenho 05 VS 25.10.26): leitura, regras, condição individual, as recusas
 * (403 sem token ou sem ser Master, 404, 422) e a auditoria de plataforma. O armazém de CSRF é fixo (padrão do painel).
 */
#[CoversClass(RecompensasDoMasterController::class)]
final class RecompensasDoMasterControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . RecompensasDoMasterController::TOKEN;
    private const CHAVE_A = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const CHAVE_B = 'bbbbbbbb-0000-4000-8000-000000000002';
    private const CHAVE_C = 'cccccccc-0000-4000-8000-000000000003';
    private const CHAVE_D = 'dddddddd-0000-4000-8000-000000000004';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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
    }

    #[TestDox('Master lê: regras padrão, KPIs zerados, e o indicador com a conta criada (sem dado do indicado)')]
    public function testLe(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->client->request('GET', '/master/recompensas');

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(['ativo' => true, 'valorCentavos' => 1000, 'minEscritorios' => 10, 'carenciaDias' => 30, 'alertaQtd' => 5, 'exigePasta' => true, 'comCondicaoIndividual' => 0], $d['regras']);
        $linha = $this->linhaDe($d, (int) $ana->getId());
        self::assertSame(1, $linha['contas']);
        self::assertSame(0, $linha['ativos'], 'a conta não tem pasta e o programa exige');
        self::assertSame('andamento', $linha['situacao']);
        self::assertArrayNotHasKey('indicado', $linha);
        self::assertStringNotContainsString('conta_nova', (string) $this->client->getResponse()->getContent(), 'nada do e-mail do indicado');
        self::assertSame(0, $d['kpis']['jaPagoCentavos']);
    }

    #[TestDox('quem não é Master recebe 403 em toda rota e nada muda')]
    public function testNaoMaster(): void
    {
        $ana = $this->indicadoraComContaAntiga();
        $this->entrarComoPrimarioDeEscritorio();

        $this->client->request('GET', '/master/recompensas');
        self::assertResponseStatusCodeSame(403);

        $this->enviar('POST', '/master/recompensas/regras', $this->regrasValidas());
        self::assertResponseStatusCodeSame(403);
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00']);
        self::assertResponseStatusCodeSame(403);
        $this->enviar('DELETE', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao');
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNull(static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico());
        self::assertNull(static::getContainer()->get(CondicaoDeRecompensaRepository::class)->buscarDoIndicador((int) $ana->getId()));
    }

    #[TestDox('CSRF errado ou ausente: 403 e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->enviar('POST', '/master/recompensas/regras', $this->regrasValidas(), 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00'], '');
        self::assertResponseStatusCodeSame(403);
        $this->enviar('DELETE', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', null, 'errado');
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNull(static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico());
    }

    #[TestDox('regras: grava as seis, devolve o estado novo e deixa a trilha de auditoria de plataforma (sem escritório)')]
    public function testAlteraRegras(): void
    {
        $master = $this->entrarComoMaster();

        $this->enviar('POST', '/master/recompensas/regras', ['ativo' => false, 'valor' => '25,50', 'minEscritorios' => '3', 'carenciaDias' => 0, 'alertaQtd' => 8, 'exigePasta' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ativo' => false, 'valorCentavos' => 2550, 'minEscritorios' => 3, 'carenciaDias' => 0, 'alertaQtd' => 8, 'exigePasta' => false, 'comCondicaoIndividual' => 0], $this->json()['regras']);
        $this->em->clear();
        $linha = static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico();
        self::assertNotNull($linha);
        self::assertSame($master->getId(), $linha->getAlteradoPor()?->getId());
        $auditoria = $this->em->getConnection()->fetchFirstColumn('SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [ProgramaDeRecompensa::class, $master->getId()]);
        self::assertNotEmpty($auditoria, 'a gravação entra no audit_log');
        self::assertSame([null], array_values(array_unique($auditoria)), 'no escopo de plataforma, sem escritório');
    }

    #[TestDox('regras recusadas: 422 com a mensagem e a linha não muda (valor negativo, texto, "libera com" zero, regra faltando)')]
    public function testRegrasRecusadas(): void
    {
        $this->entrarComoMaster();

        foreach ([
            ['valor' => '-5'] + $this->regrasValidas(),
            ['valor' => 'dez'] + $this->regrasValidas(),
            ['minEscritorios' => '0'] + $this->regrasValidas(),
            ['minEscritorios' => '1,5'] + $this->regrasValidas(),
            ['carenciaDias' => '-1'] + $this->regrasValidas(),
            ['alertaQtd' => 0] + $this->regrasValidas(),
            ['valor' => ''] + $this->regrasValidas(),
            ['ativo' => 'sim'] + $this->regrasValidas(),
        ] as $i => $corpo) {
            $this->enviar('POST', '/master/recompensas/regras', $corpo);
            self::assertResponseStatusCodeSame(422, 'caso ' . $i);
            self::assertNotSame('', $this->json()['erro'], 'caso ' . $i);
        }
        $this->em->clear();
        self::assertNull(static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico());
    }

    #[TestDox('condição individual: grava, aparece na linha e no "N com condição individual", vale na leitura e vai para a auditoria')]
    public function testDefineCondicao(): void
    {
        $master = $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00', 'minEscritorios' => '1', 'carenciaDias' => '', 'recorrente' => true, 'mensal' => '2,00', 'motivo' => 'Convênio com a seccional']);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(1, $d['regras']['comCondicaoIndividual']);
        $linha = $this->linhaDe($d, (int) $ana->getId());
        self::assertSame(['valorCentavos' => 3000, 'minEscritorios' => 1, 'carenciaDias' => null, 'recorrente' => true, 'mensalCentavos' => 200, 'motivo' => 'Convênio com a seccional'], $linha['condicao']);
        self::assertSame(3000, $linha['valorCentavos']);
        self::assertSame(1, $linha['minEscritorios']);
        $this->em->clear();
        $auditoria = $this->em->getConnection()->fetchFirstColumn('SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [CondicaoDeRecompensa::class, $master->getId()]);
        self::assertNotEmpty($auditoria);
        self::assertSame([null], array_values(array_unique($auditoria)));
    }

    #[TestDox('voltar à regra geral: remove a condição, devolve o estado novo e audita a remoção')]
    public function testRemoveCondicao(): void
    {
        $master = $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00']);
        self::assertResponseIsSuccessful();

        $this->enviar('DELETE', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao');

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(0, $d['regras']['comCondicaoIndividual']);
        self::assertNull($this->linhaDe($d, (int) $ana->getId())['condicao']);
        $this->em->clear();
        self::assertNull(static::getContainer()->get(CondicaoDeRecompensaRepository::class)->buscarDoIndicador((int) $ana->getId()));
        $acoes = $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log WHERE entity_class = ? AND actor_user_id = ? ORDER BY id', [CondicaoDeRecompensa::class, $master->getId()]);
        self::assertSame(['create', 'delete'], array_map('strtolower', $acoes));
    }

    #[TestDox('condição recusada: 422 (valor negativo, recorrente sem mensal) e 404 para usuário que nunca indicou; nada é gravado')]
    public function testCondicaoRecusada(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $semIndicacao = $this->usuario(['ROLE_USER']);

        foreach ([['valor' => '-1'], ['valor' => 'x'], ['recorrente' => true], ['minEscritorios' => '0'], ['motivo' => str_repeat('a', 256)]] as $i => $corpo) {
            $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', $corpo);
            self::assertResponseStatusCodeSame(422, 'caso ' . $i);
            self::assertArrayHasKey('erro', $this->json());
        }
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao');
        self::assertResponseStatusCodeSame(422, 'corpo que não é JSON');
        $this->enviar('POST', '/master/recompensas/usuarios/' . $semIndicacao->getId() . '/condicao', ['valor' => '30,00']);
        self::assertResponseStatusCodeSame(404);
        $this->enviar('POST', '/master/recompensas/usuarios/999999999/condicao', ['valor' => '30,00']);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertSame([], static::getContainer()->get(CondicaoDeRecompensaRepository::class)->findAll());
    }

    #[TestDox('o painel traz a seção Recompensas como externa (data-sec-externa="recomp") com o contêiner, o CSS e o script')]
    public function testPainel(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('div.mp-secao-externa[data-sec-externa="recomp"] > div.mrc[data-mrc]'));
        self::assertCount(1, $crawler->filter('script[src*="js/master/recompensas.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/master-recompensas.css"]'));
        self::assertNotSame('', (string) $crawler->filter('[data-mrc]')->attr('data-token'));
    }

    #[TestDox('F2: crédito aumenta o saldo e o A PAGAR, entra no extrato com o indicador e deixa trilha de auditoria de plataforma')]
    public function testCreditoAumentaSaldo(): void
    {
        $master = $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->lancar($ana, '25,50', 'Convênio', self::CHAVE_A);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(2550, $this->linhaDe($d, (int) $ana->getId())['saldoCentavos']);
        self::assertSame(2550, $d['kpis']['aPagarCentavos']);
        self::assertSame(0, $d['kpis']['jaPagoCentavos']);
        $this->client->request('GET', '/master/recompensas/extrato');
        $extrato = $this->json()['extrato'];
        self::assertCount(1, $extrato);
        self::assertSame([(int) $ana->getId(), 'Usuária das Recompensas', $ana->getEmail(), 'bonus', 2550, 'Convênio'], [$extrato[0]['userId'], $extrato[0]['nome'], $extrato[0]['email'], $extrato[0]['tipo'], $extrato[0]['valorCentavos'], $extrato[0]['observacao']]);
        $acoes = $this->em->getConnection()->fetchAllAssociative('SELECT action, tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [LancamentoDeRecompensa::class, $master->getId()]);
        self::assertCount(1, $acoes);
        self::assertNull($acoes[0]['tenant_id'], 'plataforma, sem escritório');
        $linha = $this->em->getConnection()->fetchAssociative('SELECT criado_por_id, tipo FROM lancamento_de_recompensa');
        self::assertSame([$master->getId(), 'bonus'], [$linha['criado_por_id'], $linha['tipo']]);
    }

    #[TestDox('F2: estorno diminui o saldo e, maior que o crédito, deixa zero (nunca negativo); o KPI soma só saldos reais')]
    public function testEstornoENuncaNegativo(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $this->lancar($ana, '30,00', null, self::CHAVE_A);

        $this->lancar($ana, '-10,00', 'Erro de lançamento', self::CHAVE_B);
        self::assertSame(2000, $this->linhaDe($this->json(), (int) $ana->getId())['saldoCentavos']);

        $this->lancar($ana, '-500,00', null, self::CHAVE_C);
        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(0, $this->linhaDe($d, (int) $ana->getId())['saldoCentavos']);
        self::assertSame(0, $d['kpis']['aPagarCentavos']);
        self::assertSame(3, $this->totalDeLancamentos(), 'o livro guarda os três: o estorno é outro lançamento');
    }

    #[TestDox('F2: idempotência: a mesma chave duas vezes grava um lançamento só e as duas respostas são sucesso')]
    public function testIdempotencia(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->lancar($ana, '10,00', 'Bônus', self::CHAVE_A);
        self::assertResponseIsSuccessful();
        $this->lancar($ana, '10,00', 'Bônus', self::CHAVE_A);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->totalDeLancamentos());
        self::assertSame(1000, $this->linhaDe($this->json(), (int) $ana->getId())['saldoCentavos']);
        $this->lancar($ana, '99,00', 'Bônus', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422, 'mesma chave com outro valor não passa');
        self::assertSame(1, $this->totalDeLancamentos());
    }

    #[TestDox('F2: lançamento recusado: 422 com {erro} (zero, texto, três casas, acima do teto, chave ruim, observação longa, corpo que não é JSON) e nada é gravado')]
    public function testLancamentoRecusado(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        foreach ([
            ['valor' => '0', 'chave' => self::CHAVE_A],
            ['valor' => '', 'chave' => self::CHAVE_A],
            ['valor' => 'dez', 'chave' => self::CHAVE_A],
            ['valor' => '10,999', 'chave' => self::CHAVE_A],
            ['valor' => '100000,01', 'chave' => self::CHAVE_A],
            ['valor' => '-100000,01', 'chave' => self::CHAVE_A],
            ['valor' => '10,00', 'chave' => 'curta'],
            ['valor' => '10,00'],
            ['valor' => '10,00', 'chave' => self::CHAVE_A, 'observacao' => str_repeat('a', 256)],
            ['valor' => [1], 'chave' => self::CHAVE_A],
        ] as $i => $corpo) {
            $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/lancamento', $corpo);
            self::assertResponseStatusCodeSame(422, 'caso ' . $i);
            self::assertNotSame('', $this->json()['erro'], 'caso ' . $i);
        }
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/lancamento');
        self::assertResponseStatusCodeSame(422, 'corpo que não é JSON');
        self::assertSame('Informe o valor do crédito (use negativo para estornar).', $this->lancarERetornarErro($ana, '0'));
        self::assertSame(0, $this->totalDeLancamentos());
    }

    #[TestDox('F2: valor no teto de R$ 100.000,00 passa; 404 para quem nunca indicou e para id que não existe')]
    public function testTetoE404(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $semIndicacao = $this->usuario(['ROLE_USER']);

        $this->lancar($ana, '100.000,00', null, self::CHAVE_A);
        self::assertResponseIsSuccessful();
        $this->lancar($semIndicacao, '10,00', null, self::CHAVE_B);
        self::assertResponseStatusCodeSame(404);
        $this->enviar('POST', '/master/recompensas/usuarios/999999999/lancamento', ['valor' => '10,00', 'chave' => self::CHAVE_C]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(1, $this->totalDeLancamentos());
    }

    #[TestDox('F2: sem token CSRF ou sem ser Master: 403 em lançamento, extrato e CSV, e nada é gravado')]
    public function testLancamentoProtegido(): void
    {
        $ana = $this->indicadoraComContaAntiga();
        $this->entrarComoMaster();

        $this->lancar($ana, '10,00', null, self::CHAVE_A, 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->lancar($ana, '10,00', null, self::CHAVE_A, '');
        self::assertResponseStatusCodeSame(403);

        $this->entrarComoPrimarioDeEscritorio();
        $this->lancar($ana, '10,00', null, self::CHAVE_A);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/master/recompensas/extrato');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/master/recompensas/extrato.csv');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->totalDeLancamentos());
    }

    #[TestDox('F2: extrato mostra os 60 mais recentes, do mais novo ao mais antigo, e o filtro ?usuario= traz só o indicador')]
    public function testExtratoSessentaEFiltro(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $bia = $this->indicadoraComContaAntiga();
        for ($i = 1; $i <= 62; ++$i) {
            $this->lancar($ana, (string) $i, null, sprintf('chave-de-teste-%04d', $i));
        }
        $this->lancar($bia, '7,00', null, self::CHAVE_B);

        $this->client->request('GET', '/master/recompensas/extrato');
        $todos = $this->json()['extrato'];
        $this->client->request('GET', '/master/recompensas/extrato?usuario=' . $ana->getId());
        $daAna = $this->json()['extrato'];
        $this->client->request('GET', '/master/recompensas/extrato?usuario=' . $bia->getId());
        $daBia = $this->json()['extrato'];

        self::assertCount(60, $todos);
        self::assertSame(700, $todos[0]['valorCentavos'], 'o mais recente primeiro');
        self::assertCount(60, $daAna);
        self::assertSame([6200, 6100], [$daAna[0]['valorCentavos'], $daAna[1]['valorCentavos']]);
        self::assertSame([700], array_column($daBia, 'valorCentavos'));
        self::assertSame(63, $this->totalDeLancamentos());
    }

    #[TestDox('F2: CSV completo: cabeçalhos de download, BOM, ";" , todos os lançamentos (mais que 60), filtro por indicador, sem dado do indicado')]
    public function testCsv(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $bia = $this->indicadoraComContaAntiga();
        for ($i = 1; $i <= 61; ++$i) {
            $this->lancar($ana, (string) $i, null, sprintf('chave-de-teste-%04d', $i));
        }
        $this->lancar($bia, '-7,00', 'Estorno da Bia', self::CHAVE_B);

        $this->client->request('GET', '/master/recompensas/extrato.csv');

        self::assertResponseIsSuccessful();
        $r = $this->client->getResponse();
        self::assertSame('text/csv; charset=utf-8', $r->headers->get('Content-Type'));
        self::assertSame('attachment; filename="extrato-recompensas-bluejus.csv"', $r->headers->get('Content-Disposition'));
        $csv = (string) $r->getContent();
        self::assertStringStartsWith("\u{FEFF}\"Data\";\"Usuário\";\"E-mail\";\"Tipo\";\"Valor\";\"Observação\"\n", $csv);
        self::assertCount(1 + 62, explode("\n", rtrim($csv, "\n")), 'cabeçalho mais os 62, sem o limite de 60 da tela');
        self::assertStringContainsString('"Estorno";"-7,00";"Estorno da Bia"', $csv);
        self::assertStringNotContainsString('conta_nova', $csv, 'nada do e-mail do indicado');

        $this->client->request('GET', '/master/recompensas/extrato.csv?usuario=' . $bia->getId());
        self::assertSame('attachment; filename="extrato-recompensas-bluejus-' . $bia->getId() . '.csv"', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertCount(2, explode("\n", rtrim((string) $this->client->getResponse()->getContent(), "\n")));
    }

    #[TestDox('F2: CSV escapa injeção de fórmula na observação e no nome, e dobra as aspas')]
    public function testCsvInjecaoDeFormula(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $ana->setFullName('=HYPERLINK("http://x","Ana")');
        $this->em->flush();

        $this->lancar($ana, '10,00', '=cmd|calc', self::CHAVE_A);
        $this->lancar($ana, '10,00', '@SOMA(A1)', self::CHAVE_B);
        $this->lancar($ana, '10,00', '+1', self::CHAVE_C);
        $this->lancar($ana, '10,00', '-1+1', self::CHAVE_D);
        $this->client->request('GET', '/master/recompensas/extrato.csv');

        $csv = (string) $this->client->getResponse()->getContent();
        foreach (['=cmd|calc', '@SOMA(A1)', '+1', '-1+1'] as $obs) {
            self::assertStringContainsString("\"'" . $obs . '"', $csv, $obs);
            self::assertStringNotContainsString(';"' . $obs . '"', $csv, $obs);
        }
        self::assertStringContainsString('"\'=HYPERLINK(""http://x"",""Ana"")"', $csv);
    }

    #[TestDox('F2: o painel traz as URLs do lançamento e do extrato e o bloco do extrato')]
    public function testPainelComExtrato(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertSame('/master/recompensas/usuarios/0/lancamento', $crawler->filter('[data-mrc]')->attr('data-url-lancamento'));
        self::assertSame('/master/recompensas/extrato', $crawler->filter('[data-mrc]')->attr('data-url-extrato'));
        self::assertSame('/master/recompensas/extrato.csv', $crawler->filter('[data-mrc]')->attr('data-url-csv'));
        self::assertCount(1, $crawler->filter('.mrc > section.mrc-caixa [data-mrc-ext-lista]'));
        self::assertCount(1, $crawler->filter('.mrc > [data-mrc-lan] .mrc-dlg[role="dialog"]'));
    }

    #[TestDox('F3: conta suspeita fica retida em análise com o motivo agregado; aprovar faz a conta contar, audita e não vaza dado do indicado')]
    public function testAprovaSuspeitas(): void
    {
        $master = $this->entrarComoMaster();
        $this->enviar('POST', '/master/recompensas/regras', ['exigePasta' => false, 'carenciaDias' => 0] + $this->regrasValidas());
        $ana = $this->indicadoraComContaSuspeita('banca-' . uniqid() . '.adv.br');
        $url = '/master/recompensas/usuarios/' . $ana->getId() . '/suspeitas/aprovar';

        $this->client->request('GET', '/master/recompensas');
        $antes = $this->linhaDe($this->json(), (int) $ana->getId());
        self::assertSame(['analise', 0, 1, ['dominio' => 1]], [$antes['situacao'], $antes['ativos'], $antes['suspeitas'], $antes['motivosDeSuspeita']]);

        $this->enviar('POST', $url, ['vistas' => 2]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A lista de suspeitas mudou. Recarregue o painel e revise de novo.', $this->json()['erro']);
        foreach ([null, [], ['vistas' => 0], ['vistas' => '1']] as $corpo) {
            $this->enviar('POST', $url, $corpo);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM aprovacao_de_conta_de_recompensa'));

        $this->enviar('POST', $url, ['vistas' => 1]);
        self::assertResponseIsSuccessful();
        $depois = $this->linhaDe($this->json(), (int) $ana->getId());
        self::assertSame(['andamento', 1, 0, 1000], [$depois['situacao'], $depois['ativos'], $depois['suspeitas'], $depois['saldoCentavos']]);
        $this->enviar('POST', $url, ['vistas' => 1]);
        self::assertResponseStatusCodeSame(422, 'nada mais pendente: a quantidade vista não bate');

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM aprovacao_de_conta_de_recompensa'), 'repetir é recusado e não grava de novo');
        $auditoria = $this->em->getConnection()->fetchFirstColumn('SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [AprovacaoDeContaDeRecompensa::class, $master->getId()]);
        self::assertCount(1, $auditoria);
        self::assertNull($auditoria[0], 'plataforma, sem escritório');
        self::assertStringNotContainsString('conta_nova', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('F3: bloquear mostra "bloqueado" sem mexer no saldo; desbloquear volta; repetir é idempotente e a linha some sem condição')]
    public function testBloqueiaEDesbloqueia(): void
    {
        $master = $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $url = '/master/recompensas/usuarios/' . $ana->getId() . '/bloqueio';

        $this->enviar('POST', $url, ['bloqueado' => true]);
        self::assertResponseIsSuccessful();
        $linha = $this->linhaDe($this->json(), (int) $ana->getId());
        self::assertSame(['bloqueado', true, null], [$linha['situacao'], $linha['bloqueado'], $linha['condicao']]);
        $this->enviar('POST', $url, ['bloqueado' => true]);
        self::assertResponseIsSuccessful();
        $acoes = $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log WHERE entity_class = ? AND actor_user_id = ? ORDER BY id', [CondicaoDeRecompensa::class, $master->getId()]);
        self::assertCount(1, $acoes, 'o segundo bloqueio não regrava');

        $this->enviar('POST', $url, ['bloqueado' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame(['andamento', false], [$this->linhaDe($this->json(), (int) $ana->getId())['situacao'], $this->linhaDe($this->json(), (int) $ana->getId())['bloqueado']]);
        $this->em->clear();
        self::assertNull(static::getContainer()->get(CondicaoDeRecompensaRepository::class)->buscarDoIndicador((int) $ana->getId()));
    }

    #[TestDox('F3: voltar à regra geral não tira o bloqueio, e o bloqueado não vira ★ no "N com condição individual"')]
    public function testBloqueioSobreviveAoVoltarParaAGeral(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00']);
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/bloqueio', ['bloqueado' => true]);

        $this->enviar('DELETE', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao');

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(0, $d['regras']['comCondicaoIndividual']);
        self::assertSame('bloqueado', $this->linhaDe($d, (int) $ana->getId())['situacao']);
    }

    #[TestDox('F3: bloqueio recusado: 422 com corpo que não é booleano, 404 para quem nunca indicou')]
    public function testBloqueioRecusado(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $semIndicacao = $this->usuario(['ROLE_USER']);

        foreach ([null, [], ['bloqueado' => 'true'], ['bloqueado' => 1]] as $i => $corpo) {
            $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/bloqueio', $corpo);
            self::assertResponseStatusCodeSame(422, 'caso ' . $i);
        }
        $this->enviar('POST', '/master/recompensas/usuarios/' . $semIndicacao->getId() . '/bloqueio', ['bloqueado' => true]);
        self::assertResponseStatusCodeSame(404);
        $this->enviar('POST', '/master/recompensas/usuarios/999999999/bloqueio', ['bloqueado' => true]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], static::getContainer()->get(CondicaoDeRecompensaRepository::class)->findAll());
    }

    #[TestDox('F3: apagar da lista: com APAGAR o indicador sai da lista e dos KPIs e o extrato continua; repetir é idempotente')]
    public function testApaga(): void
    {
        $master = $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $bia = $this->indicadoraComContaAntiga();
        $this->lancar($ana, '10,00', null, self::CHAVE_A);

        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/apagar', ['confirmacao' => 'apagar']);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame([(int) $bia->getId()], array_column($d['indicadores'], 'userId'));
        self::assertSame(1, $d['kpis']['indicadores']);
        self::assertSame(0, $d['kpis']['aPagarCentavos']);
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/apagar', ['confirmacao' => 'APAGAR']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/master/recompensas/extrato');
        self::assertCount(1, $this->json()['extrato'], 'o extrato continua guardado');
        $acoes = $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [CondicaoDeRecompensa::class, $master->getId()]);
        self::assertCount(1, $acoes, 'o segundo pedido não regrava');
    }

    #[TestDox('F3: apagar sem a palavra APAGAR: 422 com a mensagem do desenho e nada muda; quem nunca indicou é 404')]
    public function testApagarRecusado(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $semIndicacao = $this->usuario(['ROLE_USER']);

        foreach ([null, [], ['confirmacao' => ''], ['confirmacao' => 'apague'], ['confirmacao' => 'APAGAR TUDO'], ['confirmacao' => true]] as $i => $corpo) {
            $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/apagar', $corpo);
            self::assertResponseStatusCodeSame(422, 'caso ' . $i);
            self::assertSame('Digite APAGAR para confirmar.', $this->json()['erro'], 'caso ' . $i);
        }
        $this->enviar('POST', '/master/recompensas/usuarios/' . $semIndicacao->getId() . '/apagar', ['confirmacao' => 'APAGAR']);
        self::assertResponseStatusCodeSame(404);
        $this->enviar('POST', '/master/recompensas/usuarios/999999999/apagar', ['confirmacao' => 'APAGAR']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], static::getContainer()->get(CondicaoDeRecompensaRepository::class)->findAll());
    }

    #[TestDox('F3: as três rotas novas recusam sem CSRF (403) e quem não é Master (403), sem mudar nada')]
    public function testRotasDoAntifraudeProtegidas(): void
    {
        $ana = $this->indicadoraComContaSuspeita('banca-' . uniqid() . '.adv.br');
        $rotas = [
            ['/master/recompensas/usuarios/' . $ana->getId() . '/suspeitas/aprovar', ['vistas' => 1]],
            ['/master/recompensas/usuarios/' . $ana->getId() . '/bloqueio', ['bloqueado' => true]],
            ['/master/recompensas/usuarios/' . $ana->getId() . '/apagar', ['confirmacao' => 'APAGAR']],
        ];

        $this->entrarComoMaster();
        foreach ($rotas as [$url, $corpo]) {
            $this->enviar('POST', $url, $corpo, 'errado');
            self::assertResponseStatusCodeSame(403, $url);
            $this->enviar('POST', $url, $corpo, '');
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->entrarComoPrimarioDeEscritorio();
        foreach ($rotas as [$url, $corpo]) {
            $this->enviar('POST', $url, $corpo);
            self::assertResponseStatusCodeSame(403, $url);
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM aprovacao_de_conta_de_recompensa'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM condicao_de_recompensa'));
    }

    #[TestDox('F3: aprovar para quem nunca indicou é 404')]
    public function testAprovarInexistente(): void
    {
        $this->entrarComoMaster();
        $semIndicacao = $this->usuario(['ROLE_USER']);

        $this->enviar('POST', '/master/recompensas/usuarios/' . $semIndicacao->getId() . '/suspeitas/aprovar', ['vistas' => 1]);
        self::assertResponseStatusCodeSame(404);
        $this->enviar('POST', '/master/recompensas/usuarios/999999999/suspeitas/aprovar', ['vistas' => 1]);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('F3: alerta: aceitas em 7 dias >= alerta do programa mostram "alerta" na lista')]
    public function testAlerta(): void
    {
        $this->entrarComoMaster();
        $ana = $this->usuario(['ROLE_USER']);
        for ($i = 0; $i < 5; ++$i) {
            $nova = $this->usuario(['ROLE_USER'], 'conta_nova_' . uniqid() . '@indicado-teste.com.br');
            $nova->setCreatedAt(new \DateTimeImmutable('-2 days'));
            $this->em->persist(Indicacao::porEmail($ana, null, $nova->getEmail(), null, new \DateTimeImmutable('-3 days')));
        }
        $this->em->flush();

        $this->client->request('GET', '/master/recompensas');

        $linha = $this->linhaDe($this->json(), (int) $ana->getId());
        self::assertSame(['alerta', 5], [$linha['situacao'], $linha['emSeteDias']]);
    }

    /** Indicadora cuja conta nova (60 dias) tem o MESMO domínio de e-mail corporativo, o que a deixa em análise. */
    private function indicadoraComContaSuspeita(string $dominio): User
    {
        $ana = $this->usuario(['ROLE_USER'], 'ana_' . uniqid() . '@' . $dominio);
        $nova = $this->usuario(['ROLE_USER'], 'conta_nova_' . uniqid() . '@' . $dominio);
        $nova->setCreatedAt(new \DateTimeImmutable('-60 days'));
        $this->em->persist(Indicacao::porEmail($ana, null, $nova->getEmail(), null, new \DateTimeImmutable('-70 days')));
        $this->em->flush();

        return $ana;
    }

    private function lancar(User $indicador, string $valor, ?string $observacao, string $chave, string $token = self::TOKEN): void
    {
        $this->enviar('POST', '/master/recompensas/usuarios/' . $indicador->getId() . '/lancamento', ['valor' => $valor, 'observacao' => $observacao, 'chave' => $chave], $token);
    }

    private function lancarERetornarErro(User $indicador, string $valor): string
    {
        $this->lancar($indicador, $valor, null, self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);

        return $this->json()['erro'];
    }

    private function totalDeLancamentos(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lancamento_de_recompensa');
    }

    /** @return array<string, mixed> */
    private function regrasValidas(): array
    {
        return ['ativo' => true, 'valor' => '10,00', 'minEscritorios' => 10, 'carenciaDias' => 30, 'alertaQtd' => 5, 'exigePasta' => true];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function linhaDe(array $dados, int $userId): array
    {
        foreach ($dados['indicadores'] as $linha) {
            if ($linha['userId'] === $userId) {
                return $linha;
            }
        }
        self::fail('indicador ' . $userId . ' fora da lista');
    }

    /** Indicadora (e-mail) cuja conta nova foi criada há 60 dias, sem pasta no escritório. */
    private function indicadoraComContaAntiga(): User
    {
        $ana = $this->usuario(['ROLE_USER']);
        $nova = $this->usuario(['ROLE_USER'], 'conta_nova_' . uniqid() . '@indicado-teste.com.br');
        $nova->setCreatedAt(new \DateTimeImmutable('-60 days'));
        $this->em->persist(Indicacao::porEmail($ana, null, $nova->getEmail(), null, new \DateTimeImmutable('-70 days')));
        $this->em->flush();

        return $ana;
    }

    private function entrarComoMaster(): User
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = (new Tenant())->setName('Escritório das recompensas ' . uniqid());
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, ?string $email = null): User
    {
        $u = new User();
        $u->setEmail($email ?? 'recompensas_' . uniqid() . '@teste.com');
        $u->setFullName('Usuária das Recompensas');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed>|null $corpo */
    private function enviar(string $metodo, string $url, ?array $corpo = null, string $token = self::TOKEN): void
    {
        $this->client->request($metodo, $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: $corpo === null ? '' : (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 12, \JSON_THROW_ON_ERROR);
    }
}
