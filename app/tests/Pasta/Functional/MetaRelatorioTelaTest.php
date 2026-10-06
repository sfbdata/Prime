<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\DTO\PastaMetaRelatorioOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Twig\AlertasDasMetasExtension;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lote L1 (pendências pós-Documentos) na tela da aba Metas:
 *  - o sino "Alertado: Nome às HH:MM" lido da notificação REAL do sino;
 *  - o drawer "Relatório da meta" (dc L.1464-1508): arranjo com filho direto, um
 *    relatório por meta na ordem da lista, rodapé com as mesmas rotas/tokens do ⋮;
 *  - o ⋮ ganha "Abrir relatório";
 *  - N8: "vence dd/mm/aaaa".
 * Isolamento: meta de outra pasta não entra no drawer; alerta de outra pasta ou de
 * outro escritório não acende o sino. Estado vazio: sem meta, sem drawer.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(AlertasDasMetasExtension::class)]
#[CoversClass(PastaMetaRelatorioOutput::class)]
#[Group('pasta')]
final class MetaRelatorioTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarPastaDe(Tenant $tenant, User $criador): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setCriadoPor($criador);
        $this->em()->flush();

        return $pasta;
    }

    private function criarColega(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('metas_l1_rel_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel L1 ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    /** @param User[] $responsaveis */
    private function criarMeta(Pasta $pasta, User $autor, array $responsaveis, string $status, string $prazo, string $titulo): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable($prazo));
        $meta->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $meta->setDataConclusao(new \DateTimeImmutable('-1 day'));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        foreach ($responsaveis as $r) {
            $meta->addResponsavel($r);
        }
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    private function notificar(Tarefa $meta, User $destinatario, Tenant $tenant, string $tipo = AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO): void
    {
        $n = new Notificacao();
        $n->setUsuario($destinatario);
        $n->setTenant($tenant);
        $n->setTipo($tipo);
        $n->setTitulo('Alerta para verificar a meta');
        $n->setTarefa($meta);
        $this->em()->persist($n);
        $this->em()->flush();
    }

    private function abrir(object $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function relatorio(Crawler $crawler, Tarefa $meta): Crawler
    {
        $rel = $crawler->filter('#tarefas > aside#psMetaRelatorio > .ps-mrel-meta[data-meta-rel="' . $meta->getId() . '"]');
        self::assertCount(1, $rel, 'um relatório por meta, filho direto do drawer');

        return $rel;
    }

    private function sino(Crawler $crawler, Tarefa $meta): Crawler
    {
        $sino = $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"] > .ps-meta-sino-wrap > button.ps-meta-sino');
        self::assertCount(1, $sino);

        return $sino;
    }

    // =========================================================================
    // Drawer "Relatório da meta"
    // =========================================================================

    #[TestDox('drawer: escondido, diálogo; cabeçalho na ordem do desenho (ícone, título, "N de M", ▲, ▼, fechar); um relatório por meta, na ordem da lista')]
    public function testArranjoDoDrawer(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $primeira        = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');
        $segunda         = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_CONCLUIDA, '+5 days midnight', 'Pedir certidão');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $drawer = $crawler->filter('#tarefas > aside#psMetaRelatorio.ps-mrel');
        self::assertCount(1, $drawer, 'o drawer nasce no parcial da aba (o JS o leva ao <body>)');
        self::assertNotNull($drawer->attr('hidden'));
        self::assertSame('dialog', $drawer->attr('role'));
        self::assertSame('true', $drawer->attr('aria-modal'));
        self::assertSame('Relatório da meta', $drawer->attr('aria-label'));
        self::assertCount(1, $crawler->filter('#tarefas > #psMetaRelatorioFundo.ps-mrel-fundo[hidden]'));

        $cab = $drawer->filter('aside > .ps-mrel-cab > *')->each(static fn (Crawler $n) => (string) $n->attr('class'));
        self::assertSame(['bi bi-clipboard2-check', 'ps-mrel-cab-titulo', 'ps-mrel-pos ps-num', 'ps-mrel-nav', 'ps-mrel-nav', 'ps-mrel-fechar'], $cab);
        self::assertSame('Relatório da meta', trim($drawer->filter('.ps-mrel-cab > .ps-mrel-cab-titulo')->text()));
        self::assertSame(['Meta anterior', 'Próxima meta'], $drawer->filter('.ps-mrel-cab > .ps-mrel-nav')->each(static fn (Crawler $b) => $b->attr('title')));
        self::assertSame('Fechar (Esc)', $drawer->filter('.ps-mrel-cab > button.ps-mrel-fechar')->attr('title'));

        self::assertSame(
            [(string) $primeira->getId(), (string) $segunda->getId()],
            $drawer->filter('aside > .ps-mrel-meta')->each(static fn (Crawler $r) => (string) $r->attr('data-meta-rel')),
            'mesma ordem das linhas (número local)',
        );
        foreach ($drawer->filter('aside > .ps-mrel-meta') as $rel) {
            self::assertTrue($rel->hasAttribute('hidden'), 'só o JS mostra um relatório');
        }

        $rel = $this->relatorio($crawler, $primeira);
        self::assertSame(
            ['ps-mrel-corpo', 'ps-mrel-rodape'],
            $rel->filter('.ps-mrel-meta > *')->each(static fn (Crawler $n) => (string) $n->attr('class')),
            'corpo rolável e rodapé fixo',
        );
        self::assertSame(
            ['ps-mrel-topo', 'ps-mrel-campos', 'ps-mrel-secao', 'ps-mrel-secao'],
            $rel->filter('.ps-mrel-corpo > *')->each(static fn (Crawler $n) => (string) $n->attr('class')),
            'situação/título/prazo, grade, Histórico, Outras metas',
        );
        self::assertSame(['Histórico', 'Outras metas desta pasta'], $rel->filter('.ps-mrel-corpo > .ps-mrel-secao > .ps-mrel-secao-titulo')->each(static fn (Crawler $n) => trim($n->text())));
        self::assertCount(1, $rel->filter('.ps-mrel-corpo > .ps-mrel-secao[data-ps-mrel-outras-secao] > .ps-mrel-outras[data-ps-mrel-outras]'), 'montada no navegador a partir das linhas');
    }

    #[TestDox('relatório de meta aberta: pílula, título, "Vence dd/mm/aaaa", seis campos reais e histórico; rodapé "Marcar como concluída" + "Abrir em Metas"')]
    public function testConteudoDaMetaAberta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $bruno           = $this->criarColega($tenant, 'Bruno Responsável');
        $meta            = $this->criarMeta($pasta, $user, [$bruno], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');
        $prazo           = $meta->getPrazo()->format('d/m/Y');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $rel     = $this->relatorio($crawler, $meta);

        self::assertStringContainsString('ps-mrel-meta--aberta', (string) $rel->attr('class'));
        $topo = $rel->filter('.ps-mrel-corpo > .ps-mrel-topo');
        self::assertSame('Pendente', trim($topo->filter('.ps-mrel-topo > .ps-mrel-situacao')->text()));
        self::assertSame($meta->getTitulo(), trim($topo->filter('.ps-mrel-topo > h3.ps-mrel-titulo')->text()));
        self::assertSame('Vence ' . $prazo, trim($topo->filter('.ps-mrel-topo > .ps-mrel-prazo')->text()));
        self::assertCount(1, $topo->filter('.ps-mrel-prazo > i.bi-calendar-event'));

        $campos = [];
        $rel->filter('.ps-mrel-campos > .ps-mrel-campo')->each(static function (Crawler $c) use (&$campos): void {
            $campos[trim($c->filter('.ps-mrel-campo > .ps-mrel-campo-k')->text())] = trim($c->filter('.ps-mrel-campo > .ps-mrel-campo-v')->text());
        });
        self::assertSame(['Criada por', 'Responsáveis', 'Prazo', 'Última modificação', 'Pasta', 'Situação'], array_keys($campos));
        self::assertSame($user->getFullName(), $campos['Criada por']);
        self::assertSame('Bruno Responsável', $campos['Responsáveis']);
        self::assertSame($prazo, $campos['Prazo']);
        self::assertSame($pasta->getNup(), $campos['Pasta']);
        self::assertSame('Pendente', $campos['Situação']);

        $hist = $rel->filter('.ps-mrel-hist > .ps-mrel-hist-item')->each(static fn (Crawler $e) => trim($e->filter('.ps-mrel-hist-corpo > .ps-mrel-hist-texto')->text()));
        self::assertSame('Meta criada por ' . $user->getFullName() . ' para Bruno Responsável', $hist[0]);
        self::assertSame('Prazo definido para ' . $prazo, $hist[1]);
        self::assertCount(1, $rel->filter('.ps-mrel-hist > .ps-mrel-hist-item--criada > span.ps-mrel-ponto'));

        $rodape = $rel->filter('.ps-mrel-meta > .ps-mrel-rodape');
        $form   = $rodape->filter('.ps-mrel-rodape > form.ps-mrel-alternar-form');
        self::assertSame('/tarefas/' . $meta->getId() . '/concluir', $form->attr('action'));
        self::assertSame('Marcar como concluída', trim($form->filter('form > button[type="submit"].ps-mrel-alternar--concluir')->text()));
        $abrirEmMetas = $rodape->filter('.ps-mrel-rodape > a.ps-mrel-modulo');
        self::assertSame('Abrir em Metas', trim($abrirEmMetas->text()));
        self::assertSame('/tarefas/' . $meta->getId(), $abrirEmMetas->attr('href'));
    }

    #[TestDox('relatório de meta atrasada e de concluída: pílulas, frase do prazo em atraso, "Reabrir meta" no rodapé')]
    public function testAtrasadaEConcluida(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $atrasada        = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE, '-3 days midnight', 'Juntar procuração');
        $concluida       = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_CONCLUIDA, '+5 days midnight', 'Pedir certidão');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $relA = $this->relatorio($crawler, $atrasada);
        self::assertSame('Atrasada', trim($relA->filter('.ps-mrel-topo > .ps-mrel-situacao')->text()));
        $prazoA = $relA->filter('.ps-mrel-topo > .ps-mrel-prazo.ps-mrel-prazo--atraso');
        self::assertSame('3 dias em atraso · prazo ' . $atrasada->getPrazo()->format('d/m/Y'), trim($prazoA->text()));
        self::assertSame('3 dias em atraso até hoje', trim($relA->filter('.ps-mrel-hist > .ps-mrel-hist-item--atraso .ps-mrel-hist-texto')->text()));

        $relC = $this->relatorio($crawler, $concluida);
        self::assertSame('Concluída', trim($relC->filter('.ps-mrel-topo > .ps-mrel-situacao')->text()));
        self::assertSame('Concluída no prazo ' . $concluida->getPrazo()->format('d/m/Y'), trim($relC->filter('.ps-mrel-topo > .ps-mrel-prazo')->text()));
        self::assertCount(1, $relC->filter('.ps-mrel-hist > .ps-mrel-hist-item--concluida'));
        $reabrir = $relC->filter('.ps-mrel-rodape > form.ps-mrel-alternar-form');
        self::assertSame('/tarefas/' . $concluida->getId() . '/reabrir', $reabrir->attr('action'));
        self::assertSame('Reabrir meta', trim($reabrir->filter('button.ps-mrel-alternar--reabrir')->text()));
    }

    #[TestDox('"Marcar como concluída" do rodapé (token real): conclui a meta e volta para a aba')]
    public function testConcluirPeloDrawer(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');
        $id              = (int) $meta->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $client->submit($this->relatorio($crawler, $meta)->filter('.ps-mrel-rodape > form.ps-mrel-alternar-form')->form());

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->em()->getConnection()->fetchOne('SELECT status FROM tarefa WHERE id = :id', ['id' => $id]));
    }

    #[TestDox('⋮ da meta: "Abrir relatório" é o primeiro item e aponta a meta da linha; a linha anuncia o diálogo')]
    public function testItemAbrirRelatorioNoMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $itens = $crawler->filter('#psMetaMenu' . $meta->getId() . ' > *');
        $primeiro = $itens->first();
        self::assertSame('button', $primeiro->nodeName());
        self::assertSame((string) $meta->getId(), $primeiro->attr('data-ps-meta-relatorio'));
        self::assertSame('Abrir relatório', trim($primeiro->text()));
        self::assertCount(1, $primeiro->filter('i.bi-file-earmark-text'));

        $link = $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"] > a.ps-meta-abrir');
        self::assertSame('/tarefas/' . $meta->getId(), $link->attr('href'), 'sem JS, a linha continua levando à meta');
        self::assertSame('psMetaRelatorio', $link->attr('aria-controls'));
    }

    #[TestDox('N8: o prazo da meta aberta mostra o ano ("vence dd/mm/aaaa")')]
    public function testPrazoComAno(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $prazo = $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"] .ps-meta-linha > .ps-meta-prazo');
        self::assertSame('vence ' . $meta->getPrazo()->format('d/m/Y'), trim($prazo->text()));
    }

    #[TestDox('estado vazio: pasta sem meta não desenha drawer nem fundo')]
    public function testSemMetasSemDrawer(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('#tarefas > .ps-grade > .ps-metas > .ps-vazio'));
        self::assertCount(0, $crawler->filter('#psMetaRelatorio, #psMetaRelatorioFundo, .ps-mrel'));
    }

    #[TestDox('isolamento: meta de OUTRA pasta do mesmo escritório não entra no drawer desta')]
    public function testDrawerSoTemAsMetasDaPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $outra           = $this->criarPastaDe($tenant, $user);
        $minha           = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');
        $alheia          = $this->criarMeta($outra, $user, [$user], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Meta da outra pasta');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame([(string) $minha->getId()], $crawler->filter('#psMetaRelatorio > .ps-mrel-meta')->each(static fn (Crawler $r) => (string) $r->attr('data-meta-rel')));
        self::assertCount(0, $crawler->filter('[data-meta-rel="' . $alheia->getId() . '"]'));
        self::assertStringNotContainsString('Meta da outra pasta', $crawler->filter('#tarefas')->html());
    }

    // =========================================================================
    // Sino "Alertado: Nome às HH:MM"
    // =========================================================================

    #[TestDox('sino sem alerta: contorno, "Alertar para verificar"; depois do alerta REAL (token real): cheio, laranja e "Alertado: Nome às HH:MM. Clique para alertar de novo"')]
    public function testSinoDepoisDoAlerta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $bruno           = $this->criarColega($tenant, 'Bruno Responsável');
        $meta            = $this->criarMeta($pasta, $user, [$bruno], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $antes = $this->sino($crawler, $meta);
        self::assertSame('Alertar para verificar', $antes->attr('title'));
        self::assertCount(1, $antes->filter('button > i.bi-bell'));
        self::assertStringNotContainsString('is-alertado', (string) $antes->attr('class'));

        $client->submit($crawler->filter('#psMetaSino' . $meta->getId() . ' form.ps-meta-alertar')->form());
        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');

        $hora = (string) $this->em()->getConnection()->fetchOne(
            "SELECT to_char(criada_em, 'HH24:MI') FROM notificacao WHERE tarefa_id = :t AND tipo = :tipo",
            ['t' => $meta->getId(), 'tipo' => AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO],
        );
        $depois = $this->sino($this->abrir($client, $pasta), $meta);
        $titulo = 'Alertado: Bruno Responsável às ' . $hora . '. Clique para alertar de novo';
        self::assertSame($titulo, $depois->attr('title'));
        self::assertSame($titulo, $depois->attr('aria-label'));
        self::assertCount(1, $depois->filter('button > i.bi-bell-fill'));
        self::assertCount(0, $depois->filter('button > i.bi-bell'));
        self::assertStringContainsString('is-alertado', (string) $depois->attr('class'));
    }

    #[TestDox('isolamento do sino: alerta de meta de outra pasta, notificação de outro tipo e notificação de OUTRO escritório não acendem o sino')]
    public function testSinoIsolado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [, $outroTenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $outra           = $this->criarPastaDe($tenant, $user);
        $bruno           = $this->criarColega($tenant, 'Bruno Responsável');
        $meta            = $this->criarMeta($pasta, $user, [$bruno], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Juntar procuração');
        $daOutra         = $this->criarMeta($outra, $user, [$bruno], Tarefa::STATUS_PENDENTE, '+5 days midnight', 'Meta irmã');

        $this->notificar($daOutra, $bruno, $tenant);
        $this->notificar($meta, $bruno, $tenant, Notificacao::TIPO_TAREFA_CRIADA);
        $this->notificar($meta, $bruno, $outroTenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $sino = $this->sino($crawler, $meta);
        self::assertSame('Alertar para verificar', $sino->attr('title'));
        self::assertCount(1, $sino->filter('button > i.bi-bell'));
        self::assertStringNotContainsString('is-alertado', (string) $sino->attr('class'));
    }
}
