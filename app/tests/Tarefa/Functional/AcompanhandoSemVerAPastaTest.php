<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tarefa\Tarefa;
use App\ChatIa\Service\ContextoDasMetasNoChat;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\PastasSoAcompanhadasSemVer;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria 3.12, sobra do lote B (docs/AUTORIZACAO.md, seção 7, "Meta aberta por id"): nas Minhas Metas, a meta
 * só ACOMPANHADA aparece para quem pode ver a pasta dela; quem cuida da meta (responsável ou criador) continua
 * vendo. Vale para a aba Acompanhando, a aba Todas, as contagens das abas, o trilho ("Seu andamento" e "Precisa
 * de atenção"), as atrasadas que o chat da I.A lê e as contagens que voltam do clique em "acompanhar". É a mesma regra do `AcessoAMeta::exigir`, que já dá 404 na
 * página da meta para esse acompanhante.
 *
 * Cada caso negativo tem o positivo ao lado (com `resources.pasta.view`, com acesso item a item, ou cuidando da
 * meta), para o sumiço não vir de outra coisa.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(TarefaRepository::class)]
#[CoversClass(PastasSoAcompanhadasSemVer::class)]
#[CoversClass(ContextoDasMetasNoChat::class)]
#[Group('tarefa')]
final class AcompanhandoSemVerAPastaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const TITULO = 'Meta reservada acompanhada';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /** @return array{0: User, 1: Tenant, 2: Pasta, 3: Tarefa} o primário, o escritório, a pasta e a meta (atrasada) */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $pasta = $this->criarPasta($tenant, 'CLIENTE RESERVADO');
        $pasta->setCriadoPor($primario);
        $meta = $this->novaMeta($pasta, $primario, self::TITULO);

        return [$primario, $tenant, $pasta, $meta];
    }

    private function novaMeta(Pasta $pasta, User $criador, string $titulo): Tarefa
    {
        // A guarda da meta pede um criador ou responsável da pasta com vínculo ativo (SEC-013).
        if ($pasta->getCriadoPor() === null) {
            $pasta->setCriadoPor($criador);
        }
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Demanda');
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($criador);
        $meta->addResponsavel($criador);
        // Atrasada: entra no trilho "Precisa de atenção", que olha o universo da aba Todas.
        $meta->setPrazo(new \DateTimeImmutable('-3 days'));
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    private function acompanhar(Tarefa $meta, User $quem): void
    {
        $meta->alternarAcompanhamento($quem);
        $this->em()->flush();
    }

    private function darAcessoAPasta(User $user, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($pasta->getTenant());
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    private function abrir(User $quem, Tenant $tenant, string $aba): string
    {
        $this->entrar($quem, $tenant);
        $this->client->request('GET', '/tarefas/minhas?aba=' . $aba);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function badge(string $aba): string
    {
        return trim($this->client->getCrawler()->filter('.mm-aba[data-aba="' . $aba . '"] .mm-aba-badge')->text());
    }

    /** @return array{0: string, 1: string} o "X de Y" do "Seu andamento" e a fatia das atrasadas */
    private function andamento(): array
    {
        $trilho = $this->client->getCrawler()->filter('.mm-trilho-andamento');

        return [trim($trilho->filter('.mm-progresso-numero')->text()), trim($trilho->filter('.mm-fatia.tom-danger')->text())];
    }

    private function atencao(): string
    {
        return $this->client->getCrawler()->filter('.mm-trilho-atencao')->text();
    }

    #[TestDox('Acompanhante que não vê a pasta: a meta some da aba Acompanhando, da aba Todas, das contagens e do trilho')]
    public function testAcompanhanteSemVerAPastaNaoVe(): void
    {
        [, $tenant, , $meta] = $this->cenario();
        $soModulo = $this->criarColaborador($tenant, 'Acompanha Sem Ver', ['modules.tarefas.view']);
        $this->acompanhar($meta, $soModulo);

        $acompanhando = $this->abrir($soModulo, $tenant, 'acompanhando');
        self::assertStringNotContainsString(self::TITULO, $acompanhando);
        self::assertSame('0', $this->badge('acompanhando'));
        self::assertSame('0', $this->badge('todas'));

        $todas = $this->abrir($soModulo, $tenant, 'todas');
        self::assertStringNotContainsString(self::TITULO, $todas, 'nem na lista, nem no trilho');

        $padrao = $this->abrir($soModulo, $tenant, 'responsavel');
        self::assertStringNotContainsString(self::TITULO, $padrao, 'o trilho "Precisa de atenção" olha a aba Todas');
        self::assertStringNotContainsString(self::TITULO, $this->atencao());
        self::assertSame(['0 de 0', '0 atrasada(s)'], $this->andamento(), '"Seu andamento" não conta a meta');
    }

    #[TestDox('Positivo do trilho: com resources.pasta.view, a meta acompanhada aparece no "Precisa de atenção" da aba Sou responsável e conta no "Seu andamento"')]
    public function testTrilhoDoAcompanhanteQueVeAPasta(): void
    {
        [, $tenant, , $meta] = $this->cenario();
        $veTodas = $this->criarColaborador($tenant, 'Acompanha Vendo', ['modules.tarefas.view', 'resources.pasta.view']);
        $this->acompanhar($meta, $veTodas);

        $this->abrir($veTodas, $tenant, 'responsavel');
        self::assertStringContainsString(self::TITULO, $this->atencao());
        self::assertSame(['0 de 1', '1 atrasada(s)'], $this->andamento());
    }

    #[TestDox('Acompanhante com resources.pasta.view: vê a meta na aba Acompanhando, na Todas e na contagem')]
    public function testAcompanhanteComPermissaoDeVerAPastaVe(): void
    {
        [, $tenant, , $meta] = $this->cenario();
        $veTodas = $this->criarColaborador($tenant, 'Acompanha Vendo', ['modules.tarefas.view', 'resources.pasta.view']);
        $this->acompanhar($meta, $veTodas);

        self::assertStringContainsString(self::TITULO, $this->abrir($veTodas, $tenant, 'acompanhando'));
        self::assertSame('1', $this->badge('acompanhando'));
        self::assertSame('1', $this->badge('todas'));
        self::assertStringContainsString(self::TITULO, $this->abrir($veTodas, $tenant, 'todas'));
    }

    #[TestDox('Acompanhante com acesso item a item (ResourceAccess) à pasta: vê a meta; a de outra pasta sem acesso, não')]
    public function testAcompanhanteComAcessoItemAItemVe(): void
    {
        [$primario, $tenant, $pasta, $meta] = $this->cenario();
        $outra     = $this->novaMeta($this->criarPasta($tenant, 'OUTRO CLIENTE'), $primario, 'Meta de pasta sem acesso');
        $comAcesso = $this->criarColaborador($tenant, 'Acompanha Com Acesso', ['modules.tarefas.view']);
        $this->darAcessoAPasta($comAcesso, $pasta);
        $this->acompanhar($meta, $comAcesso);
        $this->acompanhar($outra, $comAcesso);

        $corpo = $this->abrir($comAcesso, $tenant, 'acompanhando');
        self::assertStringContainsString(self::TITULO, $corpo);
        self::assertStringNotContainsString('Meta de pasta sem acesso', $corpo);
        self::assertSame('1', $this->badge('acompanhando'));
    }

    #[TestDox('Responsável que não vê a pasta continua vendo a própria meta acompanhada, na Acompanhando e na Todas')]
    public function testResponsavelSemVerAPastaContinuaVendo(): void
    {
        [, $tenant, , $meta] = $this->cenario();
        $responsavel = $this->criarColaborador($tenant, 'Responsável Sem Ver', ['modules.tarefas.view']);
        $meta->addResponsavel($responsavel);
        $this->acompanhar($meta, $responsavel);

        self::assertStringContainsString(self::TITULO, $this->abrir($responsavel, $tenant, 'acompanhando'));
        self::assertSame('1', $this->badge('acompanhando'));
        self::assertSame('1', $this->badge('todas'));
        self::assertStringContainsString(self::TITULO, $this->abrir($responsavel, $tenant, 'todas'));
    }

    #[TestDox('Primário: vê toda meta que acompanha')]
    public function testPrimarioVeTudo(): void
    {
        [$primario, $tenant] = $this->cenario();
        $deOutro = $this->criarColaborador($tenant, 'Outro Criador', ['modules.tarefas.view']);
        $meta    = $this->novaMeta($this->criarPasta($tenant, 'CLIENTE DO OUTRO'), $deOutro, 'Meta do colega');
        $this->acompanhar($meta, $primario);

        self::assertStringContainsString('Meta do colega', $this->abrir($primario, $tenant, 'acompanhando'));
        self::assertSame('1', $this->badge('acompanhando'));
    }

    #[TestDox('Clique em "acompanhar": as contagens que voltam não contam a meta acompanhada de pasta que a pessoa não vê')]
    public function testContagensDoCliqueEmAcompanhar(): void
    {
        [$primario, $tenant, , $meta] = $this->cenario();
        $responsavel = $this->criarColaborador($tenant, 'Responsável Sem Ver', ['modules.tarefas.view']);
        $propria     = $this->novaMeta($this->criarPasta($tenant, 'CLIENTE DA PROPRIA'), $primario, 'Meta própria');
        $propria->addResponsavel($responsavel);
        $this->acompanhar($meta, $responsavel);

        $this->entrar($responsavel, $tenant);
        $this->client->request('POST', '/tarefas/' . $propria->getId() . '/acompanhar', ['_token' => 'TOKEN_acompanhar_tarefa_' . $propria->getId()]);
        self::assertResponseIsSuccessful();

        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($dados['acompanhando']);
        self::assertSame(1, $dados['contagens']['acompanhando'], 'só a própria; a da pasta que não vê fica fora');
        self::assertSame(1, $dados['contagens']['todas']);
    }

    #[TestDox('Criador que não é responsável e não vê a pasta continua vendo a meta que acompanha')]
    public function testCriadorSemVerAPastaContinuaVendo(): void
    {
        [$primario, $tenant] = $this->cenario();
        $criador = $this->criarColaborador($tenant, 'Criador Sem Ver', ['modules.tarefas.view']);
        $pasta   = $this->criarPasta($tenant, 'CLIENTE DO CRIADOR');
        $pasta->setCriadoPor($primario);
        $meta = $this->novaMeta($pasta, $criador, 'Meta criada e delegada');
        $meta->removeResponsavel($criador);
        $meta->addResponsavel($primario);
        $this->acompanhar($meta, $criador);
        // Outra meta da MESMA pasta, só acompanhada: põe a pasta na lista das que ele não vê, e a meta que ele
        // criou precisa continuar pelo ramo do criador.
        $alheia = $this->novaMeta($pasta, $primario, 'Meta alheia da mesma pasta');
        $this->acompanhar($alheia, $criador);

        $corpo = $this->abrir($criador, $tenant, 'acompanhando');
        self::assertStringContainsString('Meta criada e delegada', $corpo);
        self::assertStringNotContainsString('Meta alheia da mesma pasta', $corpo);
        self::assertSame('1', $this->badge('acompanhando'));
        self::assertStringContainsString('Meta criada e delegada', $this->abrir($criador, $tenant, 'todas'));
    }

    #[TestDox('Master (ROLE_SUPER_ADMIN) vê a meta que acompanha, mesmo sem permissão de ver pasta no perfil')]
    public function testMasterVeTudo(): void
    {
        [, $tenant, , $meta] = $this->cenario();
        $master = $this->criarColaborador($tenant, 'Master Da Plataforma', ['modules.tarefas.view']);
        $master->setRoles(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->acompanhar($meta, $master);

        self::assertStringContainsString(self::TITULO, $this->abrir($master, $tenant, 'acompanhando'));
        self::assertSame('1', $this->badge('acompanhando'));
    }

    /**
     * Regra do `AcessoAMeta::exigir`: o cadeado é só o teto. Estar em `Visualizar` no cadeado não dá a permissão de
     * ver a pasta; sem `resources.pasta.view` (ou acesso item a item) a pessoa não vê a pasta, então a meta só
     * acompanhada NÃO aparece (e a página dela dá 404). Com `resources.pasta.view`, aparece.
     */
    #[TestDox('Cadeado em Visualizar sem resources.pasta.view: a meta só acompanhada não aparece (404 na página); com a permissão, aparece')]
    public function testCadeadoEmVisualizar(): void
    {
        [$primario, $tenant, $pasta, $meta] = $this->cenario();
        $sem = $this->criarColaborador($tenant, 'Visualizar Sem Permissao', ['modules.tarefas.view']);
        $com = $this->criarColaborador($tenant, 'Visualizar Com Permissao', ['modules.tarefas.view', 'resources.pasta.view']);
        $this->trancar($pasta, $primario, [[$sem, NivelDeAcesso::Visualizar], [$com, NivelDeAcesso::Visualizar]]);
        $this->acompanhar($meta, $sem);
        $this->acompanhar($meta, $com);

        self::assertStringNotContainsString(self::TITULO, $this->abrir($sem, $tenant, 'acompanhando'));
        self::assertSame('0', $this->badge('acompanhando'));
        $this->client->request('GET', '/tarefas/' . $meta->getId());
        self::assertResponseStatusCodeSame(404, 'a página da meta segue a mesma regra');

        self::assertStringContainsString(self::TITULO, $this->abrir($com, $tenant, 'acompanhando'));
        self::assertSame('1', $this->badge('acompanhando'));
    }

    #[TestDox('Chat da I.A: a meta só acompanhada de pasta que a pessoa não vê sai das atrasadas; com resources.pasta.view entra')]
    public function testAtrasadasDoChat(): void
    {
        [$primario, $tenant, , $meta] = $this->cenario();
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->atualizar(true, 10, 100, false, $primario);
        $this->em()->persist($configuracao);
        $sem = $this->criarColaborador($tenant, 'Chat Sem Ver', ['modules.tarefas.view']);
        $com = $this->criarColaborador($tenant, 'Chat Vendo', ['modules.tarefas.view', 'resources.pasta.view']);
        $this->acompanhar($meta, $sem);
        $this->acompanhar($meta, $com);
        $contexto = static::getContainer()->get(ContextoDasMetasNoChat::class);

        $doSem = implode("\n", $contexto->ler($sem, $tenant));
        self::assertStringContainsString('<metas>', $doSem);
        self::assertStringNotContainsString(self::TITULO, $doSem);

        $doCom = implode("\n", $contexto->ler($com, $tenant));
        self::assertStringContainsString(self::TITULO, $doCom);
    }
}
