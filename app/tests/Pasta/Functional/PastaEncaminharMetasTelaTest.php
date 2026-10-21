<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Encaminhar via Chat I.A" no ⋮ da meta da aba Metas e no ⋮ do prazo do trilho da aba Dados (PR4b,
 * `docs/specs/encaminhar-via-chat-ia.md` §4.1; desenho 02, D:3737 `metaMenu` e D:6561 "Próximos prazos").
 *
 * O item na posição do desenho (combinador de filho direto), com `bi-send` e só identificadores em `data-*`
 * (`data-encaminhar-pasta`, `-tipo`, `-id`): no ⋮ da meta, tipo `meta`; no ⋮ do prazo, `meta` quando a linha é uma
 * meta e `prazo` (PastaPrazo.id) quando é um prazo registrado. Sem a Central (vínculo inativo) e na lápide o item não
 * é montado. O clique é lido nos scripts (teste de folha): eles disparam `bj-central:despachar`.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaEncaminharMetasTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const ROTULO = 'Encaminhar via Chat I.A';

    private function criarMeta(Pasta $pasta, User $autor, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Protocolar contestação');
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+2 days midnight'));
        $meta->setStatus($status);
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        $meta->addResponsavel($autor);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    /** @return array{Crawler, Pasta, Tarefa, Tarefa, PastaPrazo} */
    private function abrir(bool $comCentral = true, bool $lapide = false): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setCriadoPor($user);
        $aberta    = $this->criarMeta($pasta, $user);
        $concluida = $this->criarMeta($pasta, $user, Tarefa::STATUS_CONCLUIDA);

        $prazo = new PastaPrazo($tenant, $pasta, $user);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, 'Réplica', new \DateTimeImmutable('+5 days midnight'), null, 'Dra. Ana');
        $this->em()->persist($prazo);
        $this->em()->flush();

        if ($lapide) {
            $pasta->marcarExcluida($user, new \DateTimeImmutable());
            $this->em()->flush();
        }

        $this->logarComTenant($client, $user, $tenant);
        if (!$comCentral) {
            // O portão da Central é o vínculo ATIVO com o escritório (`central_disponivel()`); o super admin segue
            // vendo a pasta pelo bypass.
            $this->em()->getConnection()->executeStatement(
                'UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?',
                [$user->getId(), $tenant->getId()],
            );
        }

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return [$crawler, $pasta, $aberta, $concluida, $prazo];
    }

    private function assertItemDoDesenho(Crawler $item, string $tipo, int $itemId, int $pastaId): void
    {
        self::assertCount(1, $item);
        self::assertSame('button', $item->attr('type'));
        self::assertSame('menuitem', $item->attr('role'));
        self::assertSame(self::ROTULO, trim($item->text()));
        self::assertCount(1, $item->filter('button > i.bi.bi-send'), 'o ícone do desenho');
        self::assertSame($tipo, $item->attr('data-encaminhar-tipo'));
        self::assertSame((string) $itemId, $item->attr('data-encaminhar-id'));
        self::assertSame((string) $pastaId, $item->attr('data-encaminhar-pasta'));
    }

    // =========================================================================
    // ⋮ da meta (D:3737)
    // =========================================================================

    #[TestDox('D:3737: no ⋮ da meta, "Encaminhar via Chat I.A" vem depois de "Editar nome" e antes de "Copiar link", tipo meta e o id da meta')]
    public function testItemNoMenuDaMeta(): void
    {
        [$crawler, $pasta, $aberta, $concluida] = $this->abrir();

        foreach ([$aberta, $concluida] as $meta) {
            $id   = (int) $meta->getId();
            $item = $crawler->filter("article.ps-meta[data-meta-id=\"{$id}\"] > .ps-meta-acoes > #psMetaMenu{$id} > button[data-ps-meta-renomear=\"{$id}\"] + button.js-meta-encaminhar");
            $this->assertItemDoDesenho($item, 'meta', $id, (int) $pasta->getId());
            self::assertCount(1, $crawler->filter("#psMetaMenu{$id} > button.js-meta-encaminhar + button.js-ps-copiar"), 'logo antes de "Copiar link"');
            self::assertCount(1, $crawler->filter("#psMetaMenu{$id} > .js-meta-encaminhar"), 'um item por menu');
        }
    }

    #[TestDox('⋮ da meta sem a Central (vínculo inativo): sem o item; o resto do menu continua')]
    public function testMenuDaMetaSemCentral(): void
    {
        [$crawler, , $aberta] = $this->abrir(comCentral: false);

        $menu = $crawler->filter('#psMetaMenu' . $aberta->getId());
        self::assertCount(1, $menu);
        self::assertCount(1, $menu->filter('button.js-ps-copiar'));
        self::assertCount(0, $menu->filter('.js-meta-encaminhar'));
        self::assertStringNotContainsString(self::ROTULO, $menu->text());
    }

    #[TestDox('⋮ da meta na lápide: sem o item (o despacho dá 404 na pasta excluída)')]
    public function testMenuDaMetaNaLapide(): void
    {
        [$crawler, , $aberta, $concluida] = $this->abrir(lapide: true);

        foreach ([$aberta, $concluida] as $meta) {
            $menu = $crawler->filter('#psMetaMenu' . $meta->getId());
            self::assertCount(1, $menu, 'o ⋮ da lápide continua com as leituras');
            self::assertCount(0, $menu->filter('.js-meta-encaminhar'));
        }
        self::assertCount(0, $crawler->filter('[data-encaminhar-tipo]'), 'nenhum encaminhar na pasta excluída');
    }

    // =========================================================================
    // ⋮ do prazo no trilho "Próximos prazos" (D:6561)
    // =========================================================================

    #[TestDox('D:6561: no ⋮ do prazo que é meta, o item vem logo depois de "Ver nas metas", tipo meta e o id da meta')]
    public function testItemNoMenuDoPrazoDaMeta(): void
    {
        [$crawler, $pasta, $aberta] = $this->abrir();
        $id = (int) $aberta->getId();

        $item = $crawler->filter("[data-trilho=\"prazos\"] > .ps-prazo[data-prazo-id=\"{$id}\"] > .ps-prazo-mais-wrap > #psPrazoMenu{$id} > button[data-ps-ir-aba=\"tarefas-tab\"] + button.js-prazo-encaminhar");
        $this->assertItemDoDesenho($item, 'meta', $id, (int) $pasta->getId());
        // Depois dele, como no desenho: "Alertar" (só quando há colega a alertar; aqui o único responsável sou eu) e o separador.
        self::assertCount(1, $crawler->filter("#psPrazoMenu{$id} > button.js-prazo-encaminhar + div.ps-pop-sep"));
    }

    #[TestDox('D:6561: no ⋮ do prazo registrado, o item vem depois de "Ver nas metas", tipo prazo e o id do PastaPrazo')]
    public function testItemNoMenuDoPrazoRegistrado(): void
    {
        [$crawler, $pasta, , , $prazo] = $this->abrir();
        $id = (int) $prazo->getId();

        $item = $crawler->filter("[data-trilho=\"prazos\"] > .ps-prazo[data-prazo-manual=\"{$id}\"] > .ps-prazo-mais-wrap > #psPrazoMenum{$id} > button[data-ps-ir-aba=\"tarefas-tab\"] + button.js-prazo-encaminhar");
        $this->assertItemDoDesenho($item, 'prazo', $id, (int) $pasta->getId());
        self::assertCount(1, $crawler->filter("#psPrazoMenum{$id} > button.js-prazo-encaminhar + div.ps-pop-sep"));
    }

    #[TestDox('⋮ do prazo sem a Central: sem o item nas duas linhas')]
    public function testMenuDoPrazoSemCentral(): void
    {
        [$crawler] = $this->abrir(comCentral: false);

        $menus = $crawler->filter('[data-trilho="prazos"] .ps-pop--prazo');
        self::assertCount(2, $menus, 'a meta aberta e o prazo registrado');
        self::assertCount(0, $menus->filter('.js-prazo-encaminhar'));
        self::assertStringNotContainsString(self::ROTULO, $crawler->filter('[data-trilho="prazos"]')->text());
    }

    #[TestDox('⋮ do prazo na lápide: sem o item')]
    public function testMenuDoPrazoNaLapide(): void
    {
        [$crawler] = $this->abrir(lapide: true);

        $menus = $crawler->filter('[data-trilho="prazos"] .ps-pop--prazo');
        self::assertCount(2, $menus);
        self::assertCount(0, $menus->filter('.js-prazo-encaminhar'));
    }

    // =========================================================================
    // Scripts (folha): o clique dispara o evento da janela, só com ids
    // =========================================================================

    #[TestDox('pasta-metas.js e pasta-prazos.js: o clique no item dispara bj-central:despachar com {pastaId, tipo, itemId} lidos dos data-*')]
    public function testScriptsDisparamOEvento(): void
    {
        $raiz = \dirname(__DIR__, 3) . '/public/js/';

        $metas = (string) file_get_contents($raiz . 'pasta-metas.js');
        self::assertStringContainsString("closest('.js-meta-encaminhar')", $metas);
        self::assertStringContainsString("new CustomEvent('bj-central:despachar'", $metas);
        self::assertStringContainsString("detail: { pastaId: pastaId, tipo: 'meta', itemId: itemId }", $metas);

        $prazos = (string) file_get_contents($raiz . 'pasta-prazos.js');
        self::assertStringContainsString("closest('.js-prazo-encaminhar')", $prazos);
        self::assertStringContainsString("tipo !== 'meta' && tipo !== 'prazo'", $prazos);
        self::assertStringContainsString("new CustomEvent('bj-central:despachar'", $prazos);
        self::assertStringContainsString('detail: { pastaId: pastaId, tipo: tipo, itemId: itemId }', $prazos);

        foreach ([$metas, $prazos] as $js) {
            self::assertStringNotContainsString("closest('.js-encaminhar", $js, 'classe própria: o ouvinte dos registros não dispara de novo');
        }
    }
}
