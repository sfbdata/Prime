<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Master\Controller\FerramentasDoMasterController;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\Produto;
use App\Produto\UseCase\AlterarFerramentaDoEscritorioPeloMasterUseCase;
use App\Produto\UseCase\ObterFerramentasDosEscritoriosUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Produto\Functional\ComEscritoriosPorProduto;
use App\Tests\Produto\Functional\ComFerramentasNoPerfil;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Aba "Ferramentas" do Painel do Master (spec docs/specs/multiproduto-contratacao-e-voto.md, §10 e §11, "PR 9, Master
 * (functional)"): a grade dos escritórios ativos e o liga e desliga de cada ferramenta, `ia` e `voto` inclusive. Só o
 * Master. Desligar pelo Master é a trava da D16: o dono não religa.
 */
#[CoversClass(FerramentasDoMasterController::class)]
#[CoversClass(AlterarFerramentaDoEscritorioPeloMasterUseCase::class)]
#[CoversClass(ObterFerramentasDosEscritoriosUseCase::class)]
final class FerramentasDoMasterTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;
    use ComFerramentasNoPerfil;

    private const TOKEN = FerramentasDoMasterController::TOKEN;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFixo();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'grade' => ['GET', '/master/produtos'];
        yield 'escritório' => ['POST', '/master/produtos/escritorios/1'];
    }

    #[DataProvider('rotas')]
    #[TestDox('quem não é Master leva 403 (o dono do escritório inclusive)')]
    public function testNaoMaster403(string $metodo, string $url): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Cobrancas);
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $this->client->request($metodo, $url, server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_' . self::TOKEN], content: '{"produto":"cobrancas","ligado":false}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['cobrancas', 'expediente'], $this->ativosDo($tenant), 'nada mudou');
    }

    #[TestDox('sem login: não chega ao JSON')]
    public function testSemLogin(): void
    {
        $this->client->request('GET', '/master/produtos');

        self::assertTrue($this->client->getResponse()->isRedirect(), 'vai para o login');
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrfInvalido(): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Cobrancas);
        $this->comoMaster();

        $this->postar($tenant, ['produto' => 'cobrancas', 'ligado' => false], 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token CSRF inválido. Recarregue a página.', $this->respostaJson()['erro']);
        self::assertSame(['cobrancas', 'expediente'], $this->ativosDo($tenant));
    }

    #[TestDox('escritório inexistente, suspenso ou excluído: 404 e nada é gravado')]
    public function testEscritorioNaoEncontrado(): void
    {
        $suspenso = $this->escritorio(Produto::Expediente, Produto::Cobrancas);
        $this->em()->find(Tenant::class, $suspenso->getId())?->setIsActive(false);
        $excluido = $this->escritorio(Produto::Expediente, Produto::Cobrancas);
        $this->em()->find(Tenant::class, $excluido->getId())?->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em()->flush();
        $this->comoMaster();

        $this->client->request('POST', '/master/produtos/escritorios/987654321', server: $this->cabecalhos(self::TOKEN), content: (string) json_encode(['produto' => 'cobrancas', 'ligado' => false]));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Escritório não encontrado.', $this->respostaJson()['erro']);
        foreach ([$suspenso, $excluido] as $tenant) {
            $this->postar($tenant, ['produto' => 'cobrancas', 'ligado' => false]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(['cobrancas', 'expediente'], $this->ativosDo($tenant));
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function corposInvalidos(): iterable
    {
        yield 'produto fora da lista' => [['produto' => 'financeiro', 'ligado' => true]];
        yield 'produto ausente' => [['ligado' => true]];
        yield 'produto que não é texto' => [['produto' => 7, 'ligado' => true]];
        yield 'ligado ausente' => [['produto' => 'cobrancas']];
        yield 'ligado em texto' => [['produto' => 'cobrancas', 'ligado' => 'sim']];
        yield 'corpo que não é JSON' => ['isto não é json'];
    }

    #[DataProvider('corposInvalidos')]
    #[TestDox('produto fora da lista ou corpo inválido: 422 e nada muda')]
    public function testCorpoInvalido(mixed $corpo): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Cobrancas);
        $this->comoMaster();

        $this->client->request('POST', '/master/produtos/escritorios/' . $tenant->getId(), server: $this->cabecalhos(self::TOKEN), content: is_string($corpo) ? $corpo : (string) json_encode($corpo));

        self::assertResponseStatusCodeSame(422);
        self::assertNotEmpty($this->respostaJson()['erro']);
        self::assertSame(['cobrancas', 'expediente'], $this->ativosDo($tenant));
    }

    #[TestDox('a grade mostra as ferramentas de cada escritório ativo: ligadas, e as desligadas pelo BlueJus como travadas')]
    public function testGrade(): void
    {
        $completo = $this->escritorio();
        $parcial = $this->escritorio(Produto::Expediente, Produto::Cobrancas);
        $this->comoMaster();
        $this->postar($parcial, ['produto' => 'cobrancas', 'ligado' => false]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/master/produtos', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $d = $this->respostaJson();
        self::assertSame(['voto', 'expediente', 'ia', 'ponto', 'calculo', 'kanban', 'assinatura', 'cobrancas'], array_column($d['ferramentas'], 'valor'));
        self::assertSame(['Voto', 'Expediente', 'I.A.', 'Ponto', 'Cálculo', 'Kanban', 'Assinatura', 'Cobranças'], array_column($d['ferramentas'], 'rotulo'));
        $linhaCompleto = $this->linhaDe($d, $completo);
        self::assertTrue($linhaCompleto['completo']);
        self::assertSame(['expediente', 'ponto', 'calculo', 'kanban', 'assinatura', 'cobrancas'], $linhaCompleto['ligadas'], 'Voto e I.A. só aparecem ligados onde o Master liberou');
        self::assertSame([], $linhaCompleto['travadas']);
        $linhaParcial = $this->linhaDe($d, $parcial);
        self::assertFalse($linhaParcial['completo']);
        self::assertSame(['expediente'], $linhaParcial['ligadas']);
        self::assertSame(['cobrancas'], $linhaParcial['travadas']);
    }

    #[TestDox('a grade só traz escritório ativo: o suspenso e o excluído não aparecem')]
    public function testGradeSemSuspensoNemExcluido(): void
    {
        $ativo = $this->escritorio();
        $suspenso = $this->escritorio();
        $this->em()->find(Tenant::class, $suspenso->getId())?->setIsActive(false);
        $excluido = $this->escritorio();
        $this->em()->find(Tenant::class, $excluido->getId())?->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em()->flush();
        $this->comoMaster();

        $this->client->request('GET', '/master/produtos', server: ['HTTP_ACCEPT' => 'application/json']);

        $ids = array_column($this->respostaJson()['escritorios'], 'tenantId');
        self::assertContains($ativo->getId(), $ids);
        self::assertNotContains($suspenso->getId(), $ids);
        self::assertNotContains($excluido->getId(), $ids);
    }

    #[TestDox('o Master liga e desliga uma ferramenta de outro escritório, com origem master, e a D7 não vale para ele')]
    public function testLigaEDesliga(): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Cobrancas);
        $this->comoMaster();

        $this->postar($tenant, ['produto' => 'cobrancas', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame(['expediente'], $this->ativosDo($tenant));
        self::assertSame(['expediente'], $this->linhaDe($this->respostaJson(), $tenant)['ligadas'], 'a resposta já traz o estado novo');

        $this->postar($tenant, ['produto' => 'cobrancas', 'ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(['cobrancas', 'expediente'], $this->ativosDo($tenant));
        $ultima = $this->linhasDo($tenant);
        self::assertSame('master', end($ultima)->getOrigem()->value);

        $this->postar($tenant, ['produto' => 'cobrancas', 'ligado' => false]);
        $this->postar($tenant, ['produto' => 'expediente', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->ativosDo($tenant), 'o Master pode desligar a única ferramenta ligada (a D7 é do dono)');
    }

    #[TestDox('escritório sem linhas: desligar uma ferramenta materializa as outras; ligar não grava nada')]
    public function testEscritorioCompleto(): void
    {
        $tenant = $this->escritorio();
        $this->comoMaster();

        $this->postar($tenant, ['produto' => 'kanban', 'ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->linhasDo($tenant), 'já tinha tudo');

        $this->postar($tenant, ['produto' => 'kanban', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame(['assinatura', 'calculo', 'cobrancas', 'expediente', 'ia', 'ponto', 'voto'], $this->ativosDo($tenant));
    }

    #[TestDox('"completo" pode ser posto e tirado: pôr dá tudo ao escritório, tirar grava uma linha para cada ferramenta')]
    public function testCompleto(): void
    {
        $tenant = $this->escritorio(Produto::Expediente);
        $this->comoMaster();

        $this->postar($tenant, ['produto' => 'completo', 'ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->linhaDe($this->respostaJson(), $tenant)['completo']);
        self::assertContains('completo', $this->ativosDo($tenant));

        $this->postar($tenant, ['produto' => 'completo', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        $linha = $this->linhaDe($this->respostaJson(), $tenant);
        self::assertFalse($linha['completo']);
        self::assertSame(['assinatura', 'calculo', 'cobrancas', 'expediente', 'ia', 'kanban', 'ponto', 'voto'], $this->ativosDo($tenant));
    }

    #[TestDox('ligar o Voto libera a LiberacaoDoVoto (D10); desligar a bloqueia; o escritório sem linhas continua sem linhas ao ligar')]
    public function testVoto(): void
    {
        $tenant = $this->escritorio(Produto::Expediente);
        $semLinhas = $this->escritorio();
        $this->comoMaster();

        $this->postar($tenant, ['produto' => 'voto', 'ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->liberacaoDoVoto($tenant), 'a liberação saiu junto');
        self::assertSame(['expediente', 'voto'], $this->ativosDo($tenant));
        self::assertContains('voto', $this->linhaDe($this->respostaJson(), $tenant)['ligadas']);

        $this->postar($semLinhas, ['produto' => 'voto', 'ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->liberacaoDoVoto($semLinhas));
        self::assertSame([], $this->linhasDo($semLinhas));

        $this->postar($tenant, ['produto' => 'voto', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->liberacaoDoVoto($tenant));
        self::assertSame(['expediente'], $this->ativosDo($tenant));
    }

    #[TestDox('liga e desliga a I.A. de outro escritório: a liberação e a contratação andam juntas, e o teto do escritório fica como está')]
    public function testIa(): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Ia);
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->liberarPeloMaster();
        $configuracao->definirCobranca(ModoDeCobrancaDaIa::Incluso, 900);
        $this->em()->persist($configuracao);
        $this->em()->flush();
        $this->comoMaster();

        $this->postar($tenant, ['produto' => 'ia', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->configuracaoDaIa($tenant)?->isLiberadaPeloMaster());
        self::assertSame(['expediente'], $this->ativosDo($tenant));
        self::assertSame(['ia'], $this->linhaDe($this->respostaJson(), $tenant)['travadas']);

        $this->postar($tenant, ['produto' => 'ia', 'ligado' => true]);
        self::assertResponseIsSuccessful();
        $depois = $this->configuracaoDaIa($tenant);
        self::assertTrue($depois?->isLiberadaPeloMaster());
        self::assertSame(900, $depois?->getOrcamentoMensalCentavos());
        self::assertSame(['expediente', 'ia'], $this->ativosDo($tenant));
    }

    #[TestDox('auditoria: a mudança entra no audit_log do escritório alterado, nunca no da sessão do Master')]
    public function testAuditoriaNoEscritorioAlterado(): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Cobrancas, Produto::Ia);
        $outro = $this->escritorio(Produto::Expediente);
        $master = $this->comoMaster();

        $this->postar($tenant, ['produto' => 'cobrancas', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        $this->postar($tenant, ['produto' => 'ia', 'ligado' => false]);
        self::assertResponseIsSuccessful();

        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT tenant_id, entity_class FROM audit_log WHERE entity_class IN (?, ?) AND actor_user_id = ?',
            [Contratacao::class, ConfiguracaoDeInteligencia::class, $master->getId()],
        );
        self::assertContains(Contratacao::class, array_column($linhas, 'entity_class'));
        self::assertContains(ConfiguracaoDeInteligencia::class, array_column($linhas, 'entity_class'));
        self::assertSame([(int) $tenant->getId()], array_values(array_unique(array_map(static fn (array $l): int => (int) $l['tenant_id'], $linhas))));
        self::assertNotContains((int) $outro->getId(), array_map(static fn (array $l): int => (int) $l['tenant_id'], $linhas));
    }

    /** @return iterable<string, array{Produto}> */
    public static function produtosDaTrava(): iterable
    {
        yield 'cobranças' => [Produto::Cobrancas];
        yield 'I.A.' => [Produto::Ia];
        yield 'Voto' => [Produto::Voto];
    }

    #[DataProvider('produtosDaTrava')]
    #[TestDox('D16: desligar pelo Master impede o dono de religar (422); religar pelo Master destrava')]
    public function testTravaImpedeODonoDeReligar(Produto $produto): void
    {
        $tenant = $this->escritorio(Produto::Expediente, $produto);
        $this->liberarNoBanco($tenant, $produto);
        $dono = $this->dono($tenant);
        $master = $this->comoMaster();

        $this->postar($tenant, ['produto' => $produto->value, 'ligado' => false]);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($this->client, $dono, $tenant);
        $this->postarFerramenta($produto->value, ['ligar' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('desligada pelo BlueJus', $this->respostaJson()['erro']);
        self::assertNotContains($produto->value, $this->ativosDo($tenant));

        $this->comoMaster($master);
        $this->postar($tenant, ['produto' => $produto->value, 'ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertContains($produto->value, $this->ativosDo($tenant));

        $this->logarComTenant($this->client, $dono, $tenant);
        $this->postarFerramenta($produto->value, ['ligar' => false]);
        self::assertResponseIsSuccessful();
        $this->postarFerramenta($produto->value, ['ligar' => true]);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('tela: a aba "Ferramentas" é a sexta do painel e o parcial é filho direto do painel da aba, com a grade e o token')]
    public function testAbaNoPainel(): void
    {
        $this->comoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $abas = $crawler->filter('div.mp-abas > button.mp-aba');
        self::assertCount(6, $abas);
        self::assertSame('ferramentas', $abas->eq(5)->attr('data-aba'));
        self::assertSame('Ferramentas', trim($abas->eq(5)->text()));
        self::assertCount(1, $crawler->filter('div.mp-abas > button.mp-aba[data-aba="voto"] + button.mp-aba[data-aba="ferramentas"]'), 'depois do Voto');

        $painel = $crawler->filter('#master-painel > div.mp-ferramentas[data-painel-aba="ferramentas"]');
        self::assertCount(1, $painel);
        self::assertNotNull($painel->attr('hidden'), 'a aba abre pelo painel.js, oculta de início');
        $raiz = $painel->filter('div.mp-ferramentas > div.mp-fer-raiz[data-mp-fer]');
        self::assertCount(1, $raiz);
        self::assertSame('/master/produtos', $raiz->attr('data-url-dados'));
        self::assertSame('/master/produtos/escritorios/0', $raiz->attr('data-url-escritorio'));
        self::assertNotEmpty($raiz->attr('data-token'));
        self::assertSame('Ferramentas por escritório', trim($raiz->filter('section.mp-card > div.mp-cab-roxo > b')->text()));
        self::assertCount(1, $raiz->filter('section.mp-card div.mp-rolagem > div.mp-fer-grade > div.mp-fer-cab'));
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $raiz->text());
        self::assertCount(1, $crawler->filter('script[src*="js/master/ferramentas.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/master-ferramentas.css"]'));
    }

    #[TestDox('JS da aba: tudo pelo catálogo master.js.produtos.*, as chaves usadas existem em pt_BR e en e nenhuma tem travessão')]
    public function testJsUsaOCatalogo(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/ferramentas.js');
        preg_match_all("/T\\('(master\\.js\\.produtos\\.[a-z_]+)'/", $js, $m);
        $tradutor = static::getContainer()->get('translator');

        self::assertNotEmpty($m[1]);
        self::assertStringContainsString("'switch'", $js);
        foreach (array_unique($m[1]) as $chave) {
            foreach (['pt_BR', 'en'] as $idioma) {
                $texto = $tradutor->trans($chave, [], null, $idioma);
                self::assertNotSame($chave, $texto, $chave . ' existe em ' . $idioma);
                self::assertDoesNotMatchRegularExpression('/[—–]/u', $texto);
            }
        }
    }

    private function comoMaster(?User $master = null): User
    {
        $master ??= UserFactory::createOne(['fullName' => 'Master das ferramentas', 'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']])->_real();
        $this->client->loginUser($master);
        $sessao = $this->client->getSession();
        $sessao->remove('current_tenant_id');
        $sessao->save();
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(Tenant $tenant, array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', '/master/produtos/escritorios/' . $tenant->getId(), server: $this->cabecalhos($token), content: (string) json_encode($corpo));
    }

    /** @return array<string, string> */
    private function cabecalhos(string $token): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'errado' === $token ? 'errado' : 'TOKEN_' . $token,
        ];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function linhaDe(array $dados, Tenant $tenant): array
    {
        foreach ($dados['escritorios'] as $linha) {
            if ($linha['tenantId'] === $tenant->getId()) {
                return $linha;
            }
        }
        self::fail('O escritório ' . $tenant->getId() . ' não está na grade.');
    }

    /** O Voto e a I.A. só entram na trava (D16) depois de liberados: bloquear o que nunca foi liberado não é trava. */
    private function liberarNoBanco(Tenant $tenant, Produto $produto): void
    {
        if (Produto::Voto === $produto) {
            $liberacao = new LiberacaoDoVoto($tenant);
            $liberacao->liberar();
            $this->em()->persist($liberacao);
        }
        if (Produto::Ia === $produto) {
            $configuracao = new ConfiguracaoDeInteligencia($tenant);
            $configuracao->liberarPeloMaster();
            $this->em()->persist($configuracao);
        }
        $this->em()->flush();
    }

    private function liberacaoDoVoto(Tenant $tenant): bool
    {
        $this->em()->clear();

        return static::getContainer()->get(LiberacaoDoVotoRepository::class)->findDoTenantParaOMaster($this->em()->getReference(Tenant::class, $tenant->getId()))?->isLiberado() ?? false;
    }

    private function configuracaoDaIa(Tenant $tenant): ?ConfiguracaoDeInteligencia
    {
        $this->em()->clear();

        return static::getContainer()->get(ConfiguracaoDeInteligenciaRepository::class)->findDoTenantParaOMaster($this->em()->getReference(Tenant::class, $tenant->getId()));
    }
}
