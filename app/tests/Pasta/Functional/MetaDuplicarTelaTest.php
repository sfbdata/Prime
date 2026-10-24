<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PMC-032 — "Duplicar" no ⋮ da meta (desenho 02, D:3736): item com o ícone `bi bi-copy`, entre "Editar nome" e
 * "Encaminhar via Chat I.A" (ou "Copiar link", sem a Central), num POST `tarefa_duplicar` com CSRF por id. Só com a
 * meta editável: na pasta excluída (lápide) não aparece. O comportamento da rota é do `DuplicarMetaControllerTest`.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class MetaDuplicarTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarMeta(Pasta $pasta, User $autor, string $status): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Juntar procuração ' . $status);
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+5 days midnight'));
        $meta->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $meta->setDataConclusao(new \DateTimeImmutable('-1 hour'));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        $meta->addResponsavel($autor);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    /** @return array{Crawler, list<Tarefa>} */
    private function abrirPasta(bool $excluida): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setCriadoPor($user);
        $this->em()->flush();
        $metas = [$this->criarMeta($pasta, $user, Tarefa::STATUS_PENDENTE), $this->criarMeta($pasta, $user, Tarefa::STATUS_CONCLUIDA)];
        if ($excluida) {
            $pasta->marcarExcluida($user, new \DateTimeImmutable());
            $this->em()->flush();
        }

        $this->logarComTenant($client, $user, $tenant);
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return [$crawler, $metas];
    }

    private function menu(Crawler $crawler, Tarefa $meta): Crawler
    {
        return $crawler->filter('[data-ps-metas] > .ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"] > .ps-meta-acoes > #psMetaMenu' . $meta->getId());
    }

    /**
     * O item de cada filho direto do ⋮, pelo que o identifica.
     *
     * @return list<string>
     */
    private static function itens(Crawler $menu): array
    {
        return $menu->children()->each(static function (Crawler $c): string {
            $classe = (string) $c->attr('class');
            foreach (['ps-meta-duplicar', 'js-meta-encaminhar', 'js-ps-copiar', 'ps-meta-concluir', 'ps-meta-reabrir', 'ps-meta-excluir', 'ps-pop-sep'] as $marca) {
                if (str_contains(' ' . $classe . ' ', ' ' . $marca . ' ')) {
                    return $marca;
                }
            }
            if ($c->attr('data-ps-meta-renomear') !== null) {
                return 'renomear';
            }

            return $c->attr('data-ps-meta-relatorio') !== null ? 'relatorio' : $c->nodeName();
        });
    }

    #[TestDox('pasta viva: "Duplicar" (bi bi-copy) logo depois de "Editar nome" e antes de "Encaminhar"/"Copiar link", na meta aberta e na concluída')]
    public function testDuplicarNaPosicaoDoDesenho(): void
    {
        [$crawler, $metas] = $this->abrirPasta(false);

        foreach ($metas as $meta) {
            $menu = $this->menu($crawler, $meta);
            self::assertCount(1, $menu);
            $itens = self::itens($menu);
            $i     = array_search('ps-meta-duplicar', $itens, true);
            self::assertIsInt($i, 'o ⋮ tem o Duplicar: ' . implode(', ', $itens));
            self::assertSame('renomear', $itens[$i - 1], 'logo depois de "Editar nome"');
            self::assertContains($itens[$i + 1], ['js-meta-encaminhar', 'js-ps-copiar'], 'antes de "Encaminhar via Chat I.A" (ou de "Copiar link", sem a Central)');

            $form = $menu->filter('#psMetaMenu' . $meta->getId() . ' > form.ps-meta-duplicar');
            self::assertSame('/tarefas/' . $meta->getId() . '/duplicar', $form->attr('action'));
            self::assertSame('post', strtolower((string) $form->attr('method')));
            self::assertNull($form->attr('data-ps-cf'), 'sem janela de confirmação: a cópia nasce na hora');
            self::assertNotSame('', (string) $form->filter('form.ps-meta-duplicar > input[type="hidden"][name="_token"]')->attr('value'));
            $botao = $form->filter('form.ps-meta-duplicar > button.ps-pop-item[type="submit"][role="menuitem"]');
            self::assertCount(1, $botao);
            self::assertCount(1, $botao->filter('button > i.bi.bi-copy'));
            self::assertSame('Duplicar', trim($botao->filter('button > span')->text()));
        }
    }

    #[TestDox('lápide: o ⋮ não oferece "Duplicar" (o servidor recusaria)')]
    public function testLapideSemDuplicar(): void
    {
        [$crawler, $metas] = $this->abrirPasta(true);

        foreach ($metas as $meta) {
            $menu = $this->menu($crawler, $meta);
            self::assertCount(1, $menu, 'o ⋮ continua, com as leituras');
            self::assertNotContains('ps-meta-duplicar', self::itens($menu));
            self::assertCount(0, $crawler->filter('form[action="/tarefas/' . $meta->getId() . '/duplicar"]'));
        }
    }

    #[TestDox('o botão "Nova meta" leva a marca que pré-preenche o prazo em 7 dias (D:4045)')]
    public function testNovaMetaComAMarcaDoPrazo(): void
    {
        [$crawler] = $this->abrirPasta(false);

        self::assertCount(1, $crawler->filter('[data-ps-metas] > .ps-card-cab > button[data-bs-target="#modalCriarTarefa"][data-ps-nova-meta]'));
    }
}
