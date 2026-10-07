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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PMC-001 e PMC-027 — o gancho do botão direito: o cartão do processo e a linha da meta dizem,
 * em `data-ps-menu-contexto`, o id do ⋮ que JÁ existe dentro deles (filho direto do cartão;
 * dentro do `.ps-meta-acoes` na meta). O JS só abre esse menu — ver `PastaMenuBotaoDireitoJsTest`.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaMenuBotaoDireitoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function criarMeta(Pasta $pasta, User $autor): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Juntar procuração');
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+5 days midnight'));
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        $meta->addResponsavel($autor);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    #[TestDox('processo: o cartão aponta para o PRÓPRIO ⋮ (filho direto) e tem o title do desenho')]
    public function testGanchoNoCartaoDoProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarProcesso($tenant, '07011345720258070007');
        $b               = $this->criarProcesso($tenant, '07022222220258070007');
        $this->vincular($pasta, $a);
        $this->vincular($pasta, $b);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        foreach ([$a, $b] as $processo) {
            $cartao = $crawler->filter('.ps-processos > .ps-registro > article.ps-processo[data-processo-id="' . $processo->getId() . '"]');
            self::assertCount(1, $cartao);
            $alvo = (string) $cartao->attr('data-ps-menu-contexto');
            self::assertSame('psProcMenu' . $processo->getId(), $alvo);
            self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-menu#' . $alvo . '[role="menu"]'), 'o menu apontado é o ⋮ deste cartão');
            self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-topo > .ps-processo-acoes > .js-proc-menu-gatilho[aria-controls="' . $alvo . '"]'), 'o ⋮ continua (teclado e toque)');
            self::assertSame('Clique com o botão direito para mais opções', $cartao->attr('title'));
        }
    }

    #[TestDox('meta: a linha aponta para o PRÓPRIO ⋮, que continua na linha')]
    public function testGanchoNaLinhaDaMeta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $meta            = $this->criarMeta($pasta, $user);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linha = $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"]');
        self::assertCount(1, $linha);
        $alvo = (string) $linha->attr('data-ps-menu-contexto');
        self::assertSame('psMetaMenu' . $meta->getId(), $alvo);
        self::assertCount(1, $linha->filter('article.ps-meta > .ps-meta-acoes > .ps-pop#' . $alvo . '[role="menu"]'));
        self::assertCount(1, $linha->filter('article.ps-meta > .ps-meta-acoes > .ps-meta-menu-btn[data-ps-pop="' . $alvo . '"]'));
    }

    #[TestDox('meta sem o módulo Tarefas: sem ⋮, sem gancho (o botão direito fica com o navegador)')]
    public function testMetaSemModuloSemGancho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $meta            = $this->criarMeta($pasta, $user);
        $soPasta         = $this->criarUsuarioSemPermissaoDoModulo($tenant);

        $this->logarComTenant($client, $soPasta, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linha = $crawler->filter('article.ps-meta[data-meta-id="' . $meta->getId() . '"]');
        self::assertCount(1, $linha);
        self::assertNull($linha->attr('data-ps-menu-contexto'));
    }
}
