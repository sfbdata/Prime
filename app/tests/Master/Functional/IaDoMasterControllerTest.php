<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Master\Controller\IaDoMasterController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Aba "Configurações da I.A." do Painel do Master (spec ia-anthropic-ligar §10): o JSON do estado, a chave geral, o
 * modelo, a retenção e a linha de cada escritório. Só o Master; a tela nunca mostra nem recebe a chave da API.
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): o 403 de quem não é Master vem
 * da regra, não do token. O CSRF errado tem teste próprio.
 */
#[CoversClass(IaDoMasterController::class)]
final class IaDoMasterControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . IaDoMasterController::TOKEN;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();
    }

    #[TestDox('Master lê o estado: instalação, chave geral, modelo, os três modelos com preço, os modos e o escritório ativo')]
    public function testMasterLe(): void
    {
        $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório da IA ' . uniqid());
        $suspenso = $this->escritorio('Escritório suspenso ' . uniqid());
        $suspenso->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/master/ia', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(['chaveDaApiGravada' => false, 'travaDoServidorLigada' => true], $d['instalacao'], 'em teste a chave da API é vazia e a trava ligada');
        self::assertFalse($d['ligadaParaTodos'], 'a migration cria a chave geral desligada');
        self::assertSame('claude-haiku-5-5', $d['modelo']);
        self::assertSame(12, $d['mesesDeRetencao']);
        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'], array_column($d['modelos'], 'valor'));
        self::assertSame(['valor' => 'claude-haiku-5-5', 'rotulo' => 'Claude Haiku 5.5 (mais barato)', 'entrada' => 0.1, 'saida' => 0.5], $d['modelos'][0]);
        self::assertSame(['incluso', 'adicional', 'limite_gratis'], array_column($d['modos'], 'valor'));
        $linha = $this->linhaDe($d, $tenant);
        self::assertSame(['tenantId' => $tenant->getId(), 'nome' => $tenant->getName(), 'liberada' => false, 'aceiteEm' => null, 'modoDeCobranca' => 'incluso', 'orcamento' => null, 'analisesNoMes' => 0, 'gastoNoMes' => '0.00', 'orcamentoAtingido' => false, 'acimaDoGratis' => false], $linha);
        self::assertNull($this->linhaDe($d, $suspenso, false), 'escritório suspenso não entra na tabela');
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'GET dados' => ['GET', '/master/ia'];
        yield 'POST chave geral' => ['POST', '/master/ia/chave-geral'];
        yield 'POST modelo' => ['POST', '/master/ia/modelo'];
        yield 'POST retenção' => ['POST', '/master/ia/retencao'];
        yield 'POST escritório' => ['POST', '/master/ia/escritorios/1'];
    }

    #[DataProvider('rotas')]
    #[TestDox('quem não é Master (primário de escritório) recebe 403 em $metodo $url, e nada muda')]
    public function testNaoMaster403(string $metodo, string $url): void
    {
        $this->entrarComoPrimarioDeEscritorio();
        $antes = $this->plataformaNoBanco();

        if ($metodo === 'GET') {
            $this->client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/json']);
        } else {
            $this->postar($url, ['ligada' => true, 'modelo' => 'claude-opus-5-5', 'meses' => 3, 'liberada' => true, 'modoDeCobranca' => 'adicional', 'orcamento' => '1']);
        }

        self::assertResponseStatusCodeSame(403);
        self::assertSame($antes, $this->plataformaNoBanco());
    }

    #[TestDox('sem login: não chega ao JSON')]
    public function testSemLogin(): void
    {
        $this->client->request('GET', '/master/ia');

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertTrue($this->client->getResponse()->isRedirect(), 'vai para o login');
    }

    #[TestDox('CSRF errado: 403 em todos os POST e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório CSRF ' . uniqid());
        $antes = $this->plataformaNoBanco();

        foreach ([
            ['/master/ia/chave-geral', ['ligada' => true]],
            ['/master/ia/modelo', ['modelo' => 'claude-opus-5-5']],
            ['/master/ia/retencao', ['meses' => 6]],
            ['/master/ia/escritorios/' . $tenant->getId(), ['liberada' => true, 'modoDeCobranca' => 'adicional', 'orcamento' => '5']],
        ] as [$url, $corpo]) {
            $this->postar($url, $corpo, 'errado');
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Token CSRF inválido. Recarregue a página.', $this->json()['erro']);
        }

        self::assertSame($antes, $this->plataformaNoBanco());
        self::assertNull($this->configuracaoDo($tenant));
    }

    #[TestDox('chave geral: liga e desliga, responde o estado novo e registra quem alterou')]
    public function testChaveGeral(): void
    {
        $master = $this->entrarComoMaster();

        $this->postar('/master/ia/chave-geral', ['ligada' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['ligadaParaTodos']);
        self::assertTrue($this->plataforma()->isLigadaParaTodos());
        self::assertSame($master->getId(), $this->plataforma()->getAlteradaPor()?->getId());

        $this->postar('/master/ia/chave-geral', ['ligada' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->plataforma()->isLigadaParaTodos());

        $this->postar('/master/ia/chave-geral', ['ligada' => 'sim']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe se a chave geral fica ligada ou desligada.', $this->json()['erro']);
    }

    #[TestDox('modelo: grava um dos três; fora da lista (inclusive Fable) é 422 e nada muda')]
    public function testModelo(): void
    {
        $this->entrarComoMaster();

        $this->postar('/master/ia/modelo', ['modelo' => 'claude-sonnet-5-5']);
        self::assertResponseIsSuccessful();
        self::assertSame('claude-sonnet-5-5', $this->json()['modelo']);
        self::assertSame('claude-sonnet-5-5', $this->plataforma()->getModelo());

        foreach (['claude-fable-5', '', 'CLAUDE-OPUS-5-5', null] as $invalido) {
            $this->postar('/master/ia/modelo', ['modelo' => $invalido]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Escolha um modelo da lista.', $this->json()['erro']);
        }
        self::assertSame('claude-sonnet-5-5', $this->plataforma()->getModelo());
    }

    #[TestDox('retenção: grava de 1 a 120 meses (número ou texto do campo); fora da faixa ou não inteiro é 422 e nada muda')]
    public function testRetencao(): void
    {
        $master = $this->entrarComoMaster();

        $this->postar('/master/ia/retencao', ['meses' => '24']);
        self::assertResponseIsSuccessful();
        self::assertSame(24, $this->json()['mesesDeRetencao']);
        self::assertSame(24, $this->plataforma()->getMesesDeRetencao());
        self::assertSame($master->getId(), $this->plataforma()->getAlteradaPor()?->getId());

        foreach ([[0, 'Guarde as respostas da IA por 1 a 120 meses.'], [121, 'Guarde as respostas da IA por 1 a 120 meses.'], ['-1', 'Guarde as respostas da IA por 1 a 120 meses.'], ['doze', 'Informe os meses em número inteiro.'], [2.5, 'Informe os meses em número inteiro.']] as [$meses, $erro]) {
            $this->postar('/master/ia/retencao', ['meses' => $meses]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($erro, $this->json()['erro']);
        }
        self::assertSame(24, $this->plataforma()->getMesesDeRetencao());
    }

    #[TestDox('escritório: libera, define o modo e o orçamento em US$ (vira centavos); bloquear não apaga o aceite')]
    public function testEscritorio(): void
    {
        $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório liberado ' . uniqid());

        $this->postar('/master/ia/escritorios/' . $tenant->getId(), ['liberada' => true, 'modoDeCobranca' => 'limite_gratis', 'orcamento' => '12,5']);

        self::assertResponseIsSuccessful();
        self::assertSame(['liberada' => true, 'modoDeCobranca' => 'limite_gratis', 'orcamento' => '12.50'], array_intersect_key($this->linhaDe($this->json(), $tenant), array_flip(['liberada', 'modoDeCobranca', 'orcamento'])));
        $configuracao = $this->configuracaoDo($tenant);
        self::assertNotNull($configuracao);
        self::assertTrue($configuracao->isLiberadaPeloMaster());
        self::assertFalse($configuracao->isHabilitada(), 'liberar não liga: falta o aceite do admin');
        self::assertSame(ModoDeCobrancaDaIa::LimiteGratis, $configuracao->getModoDeCobranca());
        self::assertSame(1250, $configuracao->getOrcamentoMensalCentavos());

        $configuracao->atualizar(true, 50, 500, true, $this->usuario(['ROLE_USER']));
        $this->em->flush();
        $this->postar('/master/ia/escritorios/' . $tenant->getId(), ['liberada' => false, 'modoDeCobranca' => 'incluso', 'orcamento' => '']);

        self::assertResponseIsSuccessful();
        $linha = $this->linhaDe($this->json(), $tenant);
        self::assertFalse($linha['liberada']);
        self::assertNull($linha['orcamento']);
        self::assertNotNull($linha['aceiteEm'], 'o aceite do escritório continua lá');
        $configuracao = $this->configuracaoDo($tenant);
        self::assertFalse($configuracao?->isLiberadaPeloMaster());
        self::assertNotNull($configuracao?->getConsentimentoEnvioExternoEm());
    }

    #[TestDox('escritório de outro tenant que não o do Master: o Master alcança (visão de plataforma), mesmo com o filtro de tenant ligado')]
    public function testEscritorioDeOutroTenantComFiltroLigado(): void
    {
        $tenantDoMaster = $this->escritorio('Escritório do Master ' . uniqid());
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->em->persist(new UserTenant($master, $tenantDoMaster));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $tenantDoMaster);
        $outro = $this->escritorio('Outro escritório ' . uniqid());
        $existente = new ConfiguracaoDeInteligencia($outro);
        $this->em->persist($existente);
        $this->em->flush();

        $this->postar('/master/ia/escritorios/' . $outro->getId(), ['liberada' => true, 'modoDeCobranca' => 'adicional', 'orcamento' => 3]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->linhaDe($this->json(), $outro)['liberada']);
        self::assertSame('3.00', $this->linhaDe($this->json(), $outro)['orcamento']);
        $this->em->clear();
        $linhas = $this->em->getRepository(ConfiguracaoDeInteligencia::class)->findBy(['tenant' => $outro->getId()]);
        self::assertCount(1, $linhas, 'atualiza a linha que existe; não cria outra (a única por tenant)');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function linhasInvalidas(): iterable
    {
        yield 'orçamento negativo' => [['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => '-5'], 'O orçamento mensal precisa ficar entre US$ 0,00 e US$ 100.000,00.'];
        yield 'orçamento acima do teto' => [['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => '100000.01'], 'O orçamento mensal precisa ficar entre US$ 0,00 e US$ 100.000,00.'];
        yield 'orçamento com três casas' => [['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => '1.005'], 'Informe o orçamento em dólares, com até duas casas decimais.'];
        yield 'orçamento texto' => [['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => 'dez'], 'Informe o orçamento em dólares, com até duas casas decimais.'];
        yield 'modo inválido' => [['liberada' => true, 'modoDeCobranca' => 'gratis', 'orcamento' => null], 'Escolha um modo de cobrança da lista.'];
        yield 'modo ausente' => [['liberada' => true, 'orcamento' => null], 'Escolha um modo de cobrança da lista.'];
        yield 'liberada não booleana' => [['liberada' => 1, 'modoDeCobranca' => 'incluso'], 'Informe se o escritório fica liberado ou bloqueado.'];
    }

    /** @param array<string, mixed> $corpo */
    #[DataProvider('linhasInvalidas')]
    #[TestDox('linha do escritório inválida ($_dataName): 422 com a mensagem e nada é gravado')]
    public function testEscritorioInvalido(array $corpo, string $erro): void
    {
        $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório inválido ' . uniqid());

        $this->postar('/master/ia/escritorios/' . $tenant->getId(), $corpo);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($erro, $this->json()['erro']);
        self::assertNull($this->configuracaoDo($tenant));
    }

    #[TestDox('escritório inexistente, suspenso ou excluído: 404')]
    public function testEscritorioInexistente(): void
    {
        $this->entrarComoMaster();
        $suspenso = $this->escritorio('Suspenso ' . uniqid());
        $suspenso->setIsActive(false);
        $excluido = $this->escritorio('Excluído ' . uniqid());
        $excluido->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        foreach ([999999999, (int) $suspenso->getId(), (int) $excluido->getId()] as $id) {
            $this->postar('/master/ia/escritorios/' . $id, ['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => null]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('Escritório não encontrado.', $this->json()['erro']);
        }
        self::assertNull($this->configuracaoDo($suspenso));
    }

    #[TestDox('auditoria: a mudança no escritório vai para o escritório alterado; a da plataforma fica sem escritório')]
    public function testAuditoriaNoEscopoCerto(): void
    {
        $master = $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório auditado ' . uniqid());

        $this->postar('/master/ia/escritorios/' . $tenant->getId(), ['liberada' => true, 'modoDeCobranca' => 'incluso', 'orcamento' => null]);
        self::assertResponseIsSuccessful();
        $this->postar('/master/ia/retencao', ['meses' => 24]);
        self::assertResponseIsSuccessful();

        $conexao = $this->em->getConnection();
        $doEscritorio = $conexao->fetchFirstColumn(
            'SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?',
            [ConfiguracaoDeInteligencia::class, $master->getId()],
        );
        self::assertSame([(int) $tenant->getId()], array_map('intval', $doEscritorio));
        $daPlataforma = $conexao->fetchFirstColumn(
            'SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?',
            [ConfiguracaoDaPlataformaDeIa::class, $master->getId()],
        );
        self::assertNotEmpty($daPlataforma);
        self::assertSame([null], array_values(array_unique($daPlataforma)));
    }

    #[TestDox('tela: a aba "Configurações da I.A." entra no painel; o parcial é filho direto do painel da aba, com o aviso da ZDR, a instalação, a chave geral, o modelo, a retenção e a tabela')]
    public function testAbaNoPainel(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $aba = $crawler->filter('div.mp-abas > button.mp-aba[data-aba="ia"]');
        self::assertCount(1, $aba);
        self::assertSame('Configurações da I.A.', trim($aba->text()));
        self::assertCount(1, $crawler->filter('div.mp-abas > button.mp-aba[data-aba="rec"] + button.mp-aba[data-aba="ia"]'), 'a quarta aba, depois de Telas e recursos');

        $painel = $crawler->filter('#master-painel > div.mp-ia[data-painel-aba="ia"]');
        self::assertCount(1, $painel);
        self::assertNotNull($painel->attr('hidden'), 'a aba abre pelo painel.js, oculta de início');
        $raiz = $painel->filter('div.mp-ia[data-painel-aba="ia"] > div.mp-ia-raiz[data-mp-ia]');
        self::assertCount(1, $raiz);
        self::assertSame('/master/ia', $raiz->attr('data-url-dados'));
        self::assertSame('/master/ia/retencao', $raiz->attr('data-url-retencao'));

        $zdr = $raiz->filter('div.mp-ia-raiz > div.mp-ia-aviso[data-mp-ia-zdr]');
        self::assertCount(1, $zdr);
        self::assertStringContainsString('a conta da Anthropic precisa ter a retenção zero de dados (ZDR)', $zdr->text());
        self::assertStringContainsString('claude.com/contact-sales', $zdr->text());
        self::assertStringContainsString('Este pedido é seu', $zdr->text());
        self::assertStringContainsString('peça também a confirmação por escrito e o DPA.', $zdr->text());
        self::assertStringContainsString('A parte técnica é do desenvolvedor', $zdr->text());
        self::assertStringContainsString('Peça ao desenvolvedor.', $zdr->text());
        self::assertStringContainsString('O processamento roda nos EUA (região us, configurada pelo desenvolvedor).', $zdr->text());

        $cards = $raiz->filter('div.mp-ia-raiz > section.mp-card');
        self::assertCount(5, $cards);
        self::assertSame(['Instalação (feita pelo desenvolvedor)', 'Chave geral', 'Modelo', 'Retenção das respostas', 'Escritórios'], $cards->each(static fn ($c): string => trim($c->filter('section > div.mp-cab-roxo > b')->text())));
        self::assertStringContainsString('A chave da API é gravada pelo desenvolvedor no servidor; esta tela não mostra nem recebe a chave.', $cards->eq(0)->text());
        self::assertStringContainsString('A BlueJus IA só funciona com ela E a chave geral desta tela ligadas.', $cards->eq(0)->text());
        self::assertStringContainsString('peça ao desenvolvedor', $cards->eq(0)->text());
        self::assertStringNotContainsString('IA_HABILITADA', $cards->eq(0)->text(), 'nome de variável não é texto para o Master');
        self::assertCount(1, $cards->eq(1)->filter('div.mp-ia-corpo > button.mp-rec-item[role="switch"][data-mp-ia-chave-geral]'));
        self::assertSame('Ligar a BlueJus IA para todos os escritórios liberados', $cards->eq(1)->filter('span.mp-rec-item-titulo')->text());
        self::assertCount(1, $cards->eq(2)->filter('label.mp-ia-campo > select[data-mp-ia-modelo]'));
        self::assertCount(1, $cards->eq(3)->filter('label.mp-ia-campo > input[type="number"][min="1"][max="120"][data-mp-ia-retencao]'));
        self::assertStringContainsString('As respostas e perguntas da BlueJus IA mais antigas que isso são apagadas todo dia às 00:20; ficam só os números de custo.', $cards->eq(3)->text());
        self::assertSame(['Escritório', 'Liberado', 'Aceite do escritório', 'Cobrança', 'Orçamento mensal (US$)', 'Análises no mês', 'Gasto no mês (US$)', ''], $cards->eq(4)->filter('div.mp-rolagem > div.mp-ia-grade > div.mp-ia-cab > div')->each(static fn ($c): string => trim($c->text())));

        self::assertCount(1, $crawler->filter('script[src*="js/master/ia.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/master-ia.css"]'));
    }

    #[TestDox('tela: nenhum campo para a chave da API (nenhum input/textarea com "chave" ou "key" no nome) e o valor do ambiente nunca aparece')]
    public function testTelaSemChaveDaApi(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');
        $html = (string) $this->client->getResponse()->getContent();

        $suspeitos = $crawler->filter('input, textarea, select')->reduce(static function ($c): bool {
            $nome = strtolower((string) $c->attr('name') . ' ' . (string) $c->attr('id'));

            return str_contains($nome, 'chave') || str_contains($nome, 'key');
        });
        self::assertCount(0, $suspeitos);
        self::assertStringNotContainsString('sk-ant', $html);
        $chave = (string) static::getContainer()->getParameter('ia_anthropic_chave');
        if ($chave !== '') {
            self::assertStringNotContainsString($chave, $html);
        }
        $this->client->request('GET', '/master/ia', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertStringNotContainsStringIgnoringCase('sk-ant', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('JS da aba: sem texto cru (tudo pelo catálogo master.js.ia.*) e as chaves usadas existem no catálogo')]
    public function testJsUsaOCatalogo(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/ia.js');
        preg_match_all("/T\\('(master\\.js\\.ia\\.[a-z_]+)'/", $js, $m);
        $tradutor = static::getContainer()->get('translator');

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $chave) {
            self::assertNotSame($chave, $tradutor->trans($chave, [], null, 'pt_BR'), $chave . ' existe no catálogo');
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $tradutor->trans($chave, [], null, 'pt_BR'));
        }
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
        $tenant = $this->escritorio('Escritório comum ' . uniqid());
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    #[TestDox('consumo do mês por escritório: análises, gasto em US$ e os selos "orçamento atingido" (incluso) e "acima do grátis" (limite grátis)')]
    public function testConsumoDoMes(): void
    {
        $this->entrarComoMaster();
        $incluso = $this->escritorioComOrcamento('Escritório incluso ' . uniqid(), ModoDeCobrancaDaIa::Incluso, 1);
        $gratis = $this->escritorioComOrcamento('Escritório grátis ' . uniqid(), ModoDeCobrancaDaIa::LimiteGratis, 1);
        $semUso = $this->escritorioComOrcamento('Escritório sem uso ' . uniqid(), ModoDeCobrancaDaIa::Adicional, 1);
        // 100 mil tokens de entrada do Haiku = 1 centavo; os 10 de saída e a região us (padrão) passam disso: 2 centavos.
        $this->analiseCobrada($incluso, 100_000);
        $this->analiseCobrada($incluso, 0);
        $this->analiseCobrada($gratis, 100_000);

        $this->client->request('GET', '/master/ia', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $campos = array_flip(['analisesNoMes', 'gastoNoMes', 'orcamentoAtingido', 'acimaDoGratis']);
        self::assertSame(['analisesNoMes' => 2, 'gastoNoMes' => '0.02', 'orcamentoAtingido' => true, 'acimaDoGratis' => false], array_intersect_key($this->linhaDe($this->json(), $incluso), $campos));
        self::assertSame(['analisesNoMes' => 1, 'gastoNoMes' => '0.02', 'orcamentoAtingido' => false, 'acimaDoGratis' => true], array_intersect_key($this->linhaDe($this->json(), $gratis), $campos));
        self::assertSame(['analisesNoMes' => 0, 'gastoNoMes' => '0.00', 'orcamentoAtingido' => false, 'acimaDoGratis' => false], array_intersect_key($this->linhaDe($this->json(), $semUso), $campos));
    }

    private function escritorioComOrcamento(string $nome, ModoDeCobrancaDaIa $modo, ?int $centavos): Tenant
    {
        $tenant = $this->escritorio($nome);
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->liberarPeloMaster();
        $configuracao->definirCobranca($modo, $centavos);
        $this->em->persist($configuracao);
        $this->em->flush();

        return $tenant;
    }

    /** Uma análise concluída neste mês com o Haiku 5.5, `$entrada` tokens de entrada e 10 de saída. */
    private function analiseCobrada(Tenant $tenant, int $entrada): void
    {
        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: null,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: 1,
            versaoDoPrompt: 'teste',
            contextoHash: hash('sha256', uniqid('', true)),
            contextoResumo: [],
        );
        $analise->iniciarProcessamento();
        $analise->concluir('Resumo.', [], null, '{}', 'falso', ModeloDeIa::Haiku55->value, $entrada, 10, 1);
        $this->em->persist($analise);
        $this->em->flush();
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = (new Tenant())->setName($nome);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('ia_master_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário da IA do Master');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function plataforma(): ConfiguracaoDaPlataformaDeIa
    {
        $this->em->clear();

        return static::getContainer()->get(ConfiguracaoDaPlataformaDeIaRepository::class)->obter();
    }

    /** @return array{bool, string, int} */
    private function plataformaNoBanco(): array
    {
        $p = $this->plataforma();

        return [$p->isLigadaParaTodos(), $p->getModelo(), $p->getMesesDeRetencao()];
    }

    private function configuracaoDo(Tenant $tenant): ?ConfiguracaoDeInteligencia
    {
        $this->em->clear();

        return $this->em->getRepository(ConfiguracaoDeInteligencia::class)->findOneBy(['tenant' => $tenant->getId()]);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return ?array<string, mixed>
     */
    private function linhaDe(array $dados, Tenant $tenant, bool $exigir = true): ?array
    {
        foreach ($dados['escritorios'] as $linha) {
            if ($linha['tenantId'] === $tenant->getId()) {
                return $linha;
            }
        }
        if ($exigir) {
            self::fail('o escritório ' . $tenant->getId() . ' não está na tabela');
        }

        return null;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    private function instalarCsrfFixo(): void
    {
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
}
