<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Painel da Gestão eleitoral por entidade (F1-4, desenho 18): grupos .vGr com .vGrH e .vEvH como filhos diretos, filtros
 * .vFil por GET, vazios, KPIs .vKpiB com o painel de lista, as 6 etapas, e o assistente com o nome da entidade e o CNPJ.
 */
#[CoversClass(AssembleiaController::class)]
#[Group('voto')]
final class PainelPorEntidadeTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Painel ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
    }

    #[TestDox('Grupos por entidade: .vGrH com nome e CNPJ com máscara, e .vEvH por assembleia, como filhos diretos do .vGr')]
    public function testGruposPorEntidade(): void
    {
        $this->assembleia('AGO Jardim', 'Residencial Jardim', '11222333000181');
        $this->assembleia('AGE Jardim', 'Residencial Jardim', '11222333000181');
        $this->assembleia('AGO Sol', 'Edifício Sol', null);

        $tela = $this->painel();

        self::assertCount(2, $tela->filter('.voto-gestao > details.vGr'));
        $jardim = $tela->filter('.voto-gestao > details.vGr')->reduce(static fn (Crawler $g): bool => str_contains($g->text(), 'Residencial Jardim'));
        self::assertCount(1, $jardim);
        self::assertSame('Residencial Jardim', trim($jardim->filter('.vGr > .vGrH .voto-grupo-nome > b')->text()));
        self::assertSame('CNPJ 11.222.333/0001-81', trim($jardim->filter('.vGr > .vGrH .voto-grupo-cnpj')->text()));
        self::assertCount(2, $jardim->filter('.vGr > .vEvH'));
        self::assertCount(2, $jardim->filter('.vGr > .voto-gestao-corpo > .voto-assembleia'));
        self::assertSame('2 rascunho(s)', trim($jardim->filter('.vGr > .vGrH .voto-grupo-resumo')->text()));
        $sol = $tela->filter('.voto-gestao > details.vGr')->reduce(static fn (Crawler $g): bool => str_contains($g->text(), 'Edifício Sol'));
        self::assertCount(0, $sol->filter('.voto-grupo-cnpj'), 'sem CNPJ não mostra a linha de CNPJ');
        self::assertStringContainsString('0 votação(ões)', $sol->filter('.vEvH')->text(), 'a linha do evento conta as votações (sem pauta ainda)');
        self::assertStringContainsString((new \DateTimeImmutable())->format('d/m/Y'), $sol->filter('.vEvH')->text());
    }

    #[TestDox('Assembleia sem nome de entidade cai em "Sem entidade informada"')]
    public function testSemEntidade(): void
    {
        $this->assembleia('AGO sem dono', null, null);

        $tela = $this->painel();

        self::assertSame('Sem entidade informada', trim($tela->filter('.vGr > .vGrH .voto-grupo-nome > b')->text()));
    }

    #[TestDox('Filtro de Entidade só existe com mais de uma entidade, e as opções levam o CNPJ')]
    public function testFiltroDeEntidadeSoComMaisDeUma(): void
    {
        $this->assembleia('AGO Jardim', 'Residencial Jardim', '11222333000181');
        self::assertCount(0, $this->painel()->filter('.vFil select[name="entidade"]'));
        self::assertCount(1, $this->painel()->filter('.vFil select[name="situacao"]'));

        $this->assembleia('AGO Sol', 'Edifício Sol', null);
        $opcoes = $this->painel()->filter('.vFil select[name="entidade"] option')->each(static fn (Crawler $o): string => trim($o->text()));

        self::assertSame(['Todas', 'Edifício Sol', 'Residencial Jardim · 11.222.333/0001-81'], $opcoes);
        $situacoes = $this->painel()->filter('.vFil select[name="situacao"] option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertSame(['Todas', 'Em andamento', 'Rascunhos', 'Concluídas'], $situacoes);
    }

    #[TestDox('Busca por GET: nome, entidade, evento e CNPJ; o campo volta preenchido e "Limpar" aparece')]
    public function testBusca(): void
    {
        $this->assembleia('AGO Jardim', 'Residencial Jardim', '11222333000181');
        $this->assembleia('AGO Sol', 'Edifício Sol', '45723174000110');

        foreach (['sol' => 'AGO Sol', 'RESIDENCIAL' => 'AGO Jardim', 'ago sol' => 'AGO Sol', '11.222.333/0001-81' => 'AGO Jardim', '45723174' => 'AGO Sol'] as $busca => $quem) {
            $tela = $this->painel('&q=' . rawurlencode((string) $busca));
            self::assertSame([$quem], $this->titulos($tela), 'busca: ' . $busca);
        }
        $tela = $this->painel('&q=jardim');
        self::assertSame('jardim', $tela->filter('.vFil input[name="q"]')->attr('value'));
        self::assertCount(1, $tela->filter('.vFil a[data-voto-filtro-limpar]'));
        self::assertSame('Nome, entidade, evento ou CNPJ', $tela->filter('.vFil input[name="q"]')->attr('placeholder'));
    }

    #[TestDox('Filtro por entidade (chave do grupo) e por situação')]
    public function testFiltroPorEntidadeESituacao(): void
    {
        $this->assembleia('AGO Jardim', 'Residencial Jardim', null);
        $publicada = $this->assembleia('AGO Sol', 'Edifício Sol', null, true);
        PautaDaAssembleiaFactory::new(['assembleia' => $publicada, 'titulo' => 'Contas'])->comOpcoesPadrao()->aberta()->create();

        $chave = (string) $this->painel()->filter('.vFil select[name="entidade"] option')->reduce(static fn (Crawler $o): bool => str_contains($o->text(), 'Sol'))->attr('value');
        self::assertSame(['AGO Sol'], $this->titulos($this->painel('&entidade=' . rawurlencode($chave))));
        self::assertSame(['AGO Sol'], $this->titulos($this->painel('&situacao=and')));
        self::assertSame(['AGO Jardim'], $this->titulos($this->painel('&situacao=ras')));
        self::assertSame([], $this->titulos($this->painel('&situacao=con')));
        self::assertSame([], $this->titulos($this->painel('&entidade=' . rawurlencode($chave) . '&situacao=ras')));
        self::assertSame(['AGO Jardim', 'AGO Sol'], $this->titulos($this->painel('&situacao=invalida'), true), 'situação desconhecida vale como "todas"');
    }

    #[TestDox('Vazios literais do desenho: com filtros e sem nenhuma assembleia')]
    public function testVazios(): void
    {
        self::assertSame('Nenhuma votação ainda. Use Nova assembleia.', trim($this->painel()->filter('.voto-gestao > .voto-vazio')->text()));

        $this->assembleia('AGO Jardim', 'Residencial Jardim', null);
        $tela = $this->painel('&q=nao-existe');

        self::assertSame('Nenhuma votação com esses filtros.', trim($tela->filter('.voto-gestao > .voto-vazio')->text()));
        self::assertCount(0, $tela->filter('.vGr'));
        self::assertCount(0, $tela->filter('[data-voto-lote]'), 'sem assembleia na tela, sem barra de seleção');
    }

    #[TestDox('Mostrar arquivadas e a seleção em lote continuam: arquivada só aparece com arquivadas=1 e cada cartão tem a caixa')]
    public function testArquivadasELoteContinuam(): void
    {
        $this->assembleia('AGO Ativa', 'Jardim', null);
        $velha = $this->assembleia('AGO Velha', 'Jardim', null, true);
        $velha->arquivar('Encerrada ontem', 'Gestora');
        $this->em->flush();

        $tela = $this->painel();
        self::assertSame(['AGO Ativa'], $this->titulos($tela));
        self::assertCount(1, $tela->filter('[data-voto-arquivadas]'));
        self::assertCount(1, $tela->filter('.voto-gestao input[data-voto-lote-item]'));
        self::assertCount(1, $tela->filter('[data-voto-lote]'));

        $com = $this->painel('&arquivadas=1');
        self::assertEqualsCanonicalizing(['AGO Ativa', 'AGO Velha'], $this->titulos($com));
        self::assertSame('1', $com->filter('.vFil input[name="arquivadas"]')->attr('value'), 'o filtro mantém "Mostrar arquivadas"');
    }

    #[TestDox('KPIs clicáveis: Votações abertas e Cédulas depositadas, com seta, título e o painel de lista pelo GET')]
    public function testKpis(): void
    {
        $a = $this->assembleia('AGO Jardim', 'Residencial Jardim', null, true);
        PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => 'Aprovação das contas', 'ordem' => 1])->comOpcoesPadrao()->aberta()->create();
        PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => 'Síndico', 'ordem' => 2])->comOpcoesPadrao()->encerrada()->create();

        $tela = $this->painel();

        $kpis = $tela->filter('.voto-painel > .vKpis > a.vKpiB');
        self::assertCount(2, $kpis);
        self::assertSame('Ver detalhes', $kpis->eq(0)->attr('title'));
        self::assertCount(1, $kpis->eq(0)->filter('i.vKpiS.bi-chevron-right'));
        self::assertSame('1', trim($kpis->eq(0)->filter('b')->text()));
        self::assertSame('Votações abertas', trim($kpis->eq(0)->filter('span')->text()));
        self::assertSame('Cédulas depositadas', trim($kpis->eq(1)->filter('span')->text()));
        self::assertCount(0, $tela->filter('.vKpiOn'));
        self::assertCount(0, $tela->filter('[data-voto-kpi-lista]'));

        $abertas = $this->painel('&kpi=abe');
        self::assertCount(1, $abertas->filter('.vKpiOn[data-voto-kpi="abe"]'));
        $lista = $abertas->filter('[data-voto-kpi-lista="abe"]');
        self::assertStringStartsWith('Votações abertas', trim($lista->filter('.vH')->text()));
        self::assertSame(['Aprovação das contas'], $lista->filter('.voto-kpi-linha > b')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame('0 de 0 votaram · em andamento', trim($lista->filter('.voto-kpi-linha > span')->text()));
        self::assertSame('Acompanhar em tempo real', trim($lista->filter('.vH a.vB')->first()->text()));
        self::assertStringEndsWith('aba=aco', (string) $lista->filter('.vH a.vB')->first()->attr('href'));
        self::assertCount(1, $lista->filter('.vH a[aria-label="Fechar detalhes"]'));
        self::assertStringNotContainsString('kpi=', (string) $lista->filter('.vH a[aria-label="Fechar detalhes"]')->attr('href'));
        self::assertCount(0, $lista->filter('.voto-kpi-mais'));

        $cedulas = $this->painel('&kpi=ced')->filter('[data-voto-kpi-lista="ced"]');
        self::assertStringStartsWith('Cédulas depositadas por votação', trim($cedulas->filter('.vH')->text()));
        self::assertSame(['0 cédula(s) · Votação aberta', '0 cédula(s) · Encerrada'], $cedulas->filter('.voto-kpi-linha > span')->each(static fn (Crawler $s): string => trim($s->text())));
        self::assertSame('Ver Resultados', trim($cedulas->filter('.vH a.vB')->first()->text()));
        self::assertStringEndsWith('aba=tra', (string) $cedulas->filter('.vH a.vB')->first()->attr('href'));

        self::assertCount(0, $this->painel('&kpi=hab')->filter('[data-voto-kpi-lista]'), 'KPI desconhecido (eleitores fica fora) não abre lista');
    }

    #[TestDox('Painel de lista do KPI sem nada: "Nada a mostrar no momento."; e o KPI ativo se fecha ao clicar de novo')]
    public function testKpiVazio(): void
    {
        $this->assembleia('AGO Jardim', 'Residencial Jardim', null);

        $tela = $this->painel('&kpi=abe');

        self::assertSame('Nada a mostrar no momento.', trim($tela->filter('[data-voto-kpi-lista] .voto-vazio')->text()));
        self::assertStringNotContainsString('kpi=', (string) $tela->filter('a.vKpiOn')->attr('href'), 'clicar no KPI ativo fecha o painel');
    }

    #[TestDox('Painel de lista do KPI mostra no máximo 60 linhas e avisa "Mostrando 60 de N."')]
    public function testKpiLimitaEm60Linhas(): void
    {
        $a = $this->assembleia('AGO Grande', 'Jardim', null, true);
        for ($i = 1; $i <= 61; ++$i) {
            PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => 'Pauta ' . $i, 'ordem' => $i])->aberta()->create();
        }

        $lista = $this->painel('&kpi=abe')->filter('[data-voto-kpi-lista="abe"]');

        self::assertCount(60, $lista->filter('.voto-kpi-linha'));
        self::assertSame('Mostrando 60 de 61.', trim($lista->filter('.voto-kpi-mais')->text()));
    }

    #[TestDox('KPIs olham só este escritório e ignoram assembleia arquivada')]
    public function testKpisSoDoEscritorio(): void
    {
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->aberta()->create();
        $velha = $this->assembleia('AGO Velha', 'Jardim', null, true);
        PautaDaAssembleiaFactory::new(['assembleia' => $velha, 'titulo' => 'Velha'])->comOpcoesPadrao()->encerrada()->create();
        $this->em->refresh($velha);
        $velha->arquivar('Guardada', 'Gestora');
        $this->em->flush();

        $tela = $this->painel('&kpi=abe&arquivadas=1');

        self::assertSame('0', trim($tela->filter('a.vKpiB b')->first()->text()));
        self::assertSame('0', trim($tela->filter('a.vKpiB b')->eq(1)->text()));
    }

    #[TestDox('As 6 etapas do desenho no cartão: concluídas em vPasOk, atual em vPasAt')]
    public function testSeisEtapas(): void
    {
        $this->assembleia('AGO Jardim', 'Jardim', null);

        $etapas = $this->painel()->filter('.voto-assembleia .voto-etapas > li');

        self::assertSame(['1. Criar', '2. Lista de aptos', '3. Publicar', '4. Votação', '5. Apuração', '6. Relatório'], $etapas->each(static fn (Crawler $l): string => trim($l->text())));
        self::assertSame('vPas vPasOk', $etapas->eq(0)->attr('class'));
        self::assertSame('vPas vPasAt', $etapas->eq(1)->attr('class'), 'rascunho sem lista de aptos: está em "Lista de aptos"');
        self::assertSame('step', $etapas->eq(1)->attr('aria-current'));
        self::assertSame('vPas', $etapas->eq(2)->attr('class'));
    }

    #[TestDox('Etapa da votação aberta e da encerrada')]
    public function testEtapasDeAbertaEEncerrada(): void
    {
        $aberta = $this->assembleia('AGO Aberta', 'Jardim', null, true);
        PautaDaAssembleiaFactory::new(['assembleia' => $aberta])->comOpcoesPadrao()->aberta()->create();
        $fim = $this->assembleia('AGO Fim', 'Jardim', null, true);
        PautaDaAssembleiaFactory::new(['assembleia' => $fim])->comOpcoesPadrao()->encerrada()->create();

        $tela = $this->painel();
        $classes = static fn (string $titulo): array => $tela->filter('.voto-assembleia')->reduce(static fn (Crawler $c): bool => str_contains($c->filter('.vH')->text(), $titulo))->filter('.voto-etapas > li')->each(static fn (Crawler $l): string => (string) $l->attr('class'));

        self::assertSame(['vPas vPasOk', 'vPas vPasOk', 'vPas vPasOk', 'vPas vPasAt', 'vPas', 'vPas'], $classes('AGO Aberta'));
        self::assertSame(['vPas vPasOk', 'vPas vPasOk', 'vPas vPasOk', 'vPas vPasOk', 'vPas vPasOk', 'vPas vPasOk'], $classes('AGO Fim'));
    }

    // ---- assistente, passo 1 -------------------------------------------------------------------------------

    #[TestDox('Passo 1 mostra Condomínio e "CNPJ (opcional, separa entidades com nome parecido)" com o placeholder do desenho')]
    public function testPasso1MostraOsCampos(): void
    {
        $tela = $this->client->request('GET', '/voto/assembleias/nova');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('label.vL:contains("Condomínio") input[name="entidade"]'));
        $cnpj = $tela->filter('label.vL:contains("CNPJ (opcional, separa entidades com nome parecido)") input[name="cnpj"]');
        self::assertCount(1, $cnpj);
        self::assertSame('00.000.000/0000-00', $cnpj->attr('placeholder'));
        self::assertSame('numeric', $cnpj->attr('inputmode'));
    }

    #[TestDox('Criar com entidade e CNPJ mascarado grava só os dígitos; a edição mostra a máscara')]
    public function testCriarGravaSoDigitos(): void
    {
        $form = $this->client->request('GET', '/voto/assembleias/nova')->selectButton('Salvar e sair')->form(['titulo' => 'AGO 2026', 'peso' => 'fracao', 'entidade' => '  Residencial Jardim ', 'cnpj' => '11.222.333/0001-81']);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $linha = $this->em->getConnection()->fetchAssociative('SELECT id, entidade_nome, cnpj FROM voto.assembleia WHERE tenant_id = ?', [$this->escritorio->getId()]);
        self::assertSame('Residencial Jardim', $linha['entidade_nome']);
        self::assertSame('11222333000181', $linha['cnpj']);

        $edicao = $this->client->request('GET', '/voto/assembleias/' . $linha['id'] . '/editar');
        self::assertSame('11.222.333/0001-81', $edicao->filter('input[name="cnpj"]')->attr('value'));
        self::assertSame('Residencial Jardim', $edicao->filter('input[name="entidade"]')->attr('value'));
    }

    #[TestDox('Criar sem entidade nem CNPJ funciona (ambos opcionais)')]
    public function testCriarSemEntidade(): void
    {
        $this->client->submit($this->client->request('GET', '/voto/assembleias/nova')->selectButton('Salvar e sair')->form(['titulo' => 'AGO 2026', 'peso' => 'unidade']));

        self::assertResponseRedirects();
        $linha = $this->em->getConnection()->fetchAssociative('SELECT entidade_nome, cnpj FROM voto.assembleia WHERE tenant_id = ?', [$this->escritorio->getId()]);
        self::assertNull($linha['entidade_nome']);
        self::assertNull($linha['cnpj']);
    }

    #[TestDox('CNPJ inválido é recusado: nada é criado e o formulário volta com o erro e o que foi digitado')]
    public function testCnpjInvalidoRecusado(): void
    {
        $form = $this->client->request('GET', '/voto/assembleias/nova')->selectButton('Salvar e sair')->form(['titulo' => 'AGO 2026', 'peso' => 'fracao', 'entidade' => 'Jardim', 'cnpj' => '11.222.333/0001-82']);
        $tela = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CNPJ válido', $tela->filter('.voto-erro')->text());
        self::assertSame('11.222.333/0001-82', $tela->filter('input[name="cnpj"]')->attr('value'));
        self::assertSame('Jardim', $tela->filter('input[name="entidade"]')->attr('value'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia WHERE tenant_id = ?', [$this->escritorio->getId()]));
    }

    #[TestDox('Editar troca a entidade; CNPJ inválido na edição recusa sem alterar nada; assembleia de outro escritório dá 404')]
    public function testEditarEntidade(): void
    {
        $a = $this->assembleia('AGO', 'Antigo', '11222333000181');
        $editar = '/voto/assembleias/' . $a->getId() . '/editar';

        $this->client->submit($this->client->request('GET', $editar)->selectButton('Salvar e sair')->form(['entidade' => 'Novo Nome', 'cnpj' => '']));
        $linha = $this->em->getConnection()->fetchAssociative('SELECT entidade_nome, cnpj FROM voto.assembleia WHERE id = ?', [$a->getId()]);
        self::assertSame('Novo Nome', $linha['entidade_nome']);
        self::assertNull($linha['cnpj']);

        $this->client->submit($this->client->request('GET', $editar)->selectButton('Salvar e sair')->form(['entidade' => 'Outro', 'cnpj' => '123']));
        self::assertSame('Novo Nome', $this->em->getConnection()->fetchOne('SELECT entidade_nome FROM voto.assembleia WHERE id = ?', [$a->getId()]));

        $alheia = AssembleiaFactory::createOne()->_real();
        $this->client->request('GET', '/voto/assembleias/' . $alheia->getId() . '/editar');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Duplicar copia a entidade e o CNPJ para o novo rascunho')]
    public function testDuplicarCopiaEntidade(): void
    {
        $origem = $this->assembleia('AGO 2026', 'Residencial Jardim', '11222333000181', true);
        $id = $origem->getId();
        PautaDaAssembleiaFactory::new(['assembleia' => $origem])->comOpcoesPadrao()->create();

        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/voto_assembleia_' . $id, 'token-de-teste');
        $sessao->save();
        $this->client->request('POST', '/voto/assembleias/' . $id . '/duplicar', ['_token' => 'token-de-teste']);

        $copia = $this->em->getConnection()->fetchAssociative('SELECT entidade_nome, cnpj FROM voto.assembleia WHERE tenant_id = ? AND id <> ?', [$this->escritorio->getId(), $id]);
        self::assertIsArray($copia);
        self::assertSame('Residencial Jardim', $copia['entidade_nome']);
        self::assertSame('11222333000181', $copia['cnpj']);
    }

    // ---- apoio --------------------------------------------------------------------------------------------

    private function assembleia(string $titulo, ?string $entidade, ?string $cnpj, bool $publicada = false): Assembleia
    {
        $fabrica = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => $titulo, 'entidadeNome' => $entidade, 'cnpj' => $cnpj]);

        return ($publicada ? $fabrica->publicada() : $fabrica)->create()->_real();
    }

    private function painel(string $consulta = ''): Crawler
    {
        $tela = $this->client->request('GET', '/voto?aba=ges' . $consulta);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** @return list<string> os títulos dos cartões de assembleia na tela */
    private function titulos(Crawler $tela, bool $ordenar = false): array
    {
        $titulos = $tela->filter('.vGr > .vEvH > b')->each(static fn (Crawler $b): string => trim($b->text()));
        if ($ordenar) {
            sort($titulos);
        }

        return $titulos;
    }

    private function usuario(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
