<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * FPN-014: botão direito no cartão do Push (desenho 1.2.3, dc L.5394 `ctx`). O menu é HTML do
 * servidor que o pasta-push.js preenche e posiciona; cada item aciona um botão que o teor JÁ
 * traz. Aqui se prova o contrato de marcação dos dois lados: o menu, o cartão e os gatilhos do
 * teor que o JS procura. O clique em si é do smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushMenuContextoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';

    #[TestDox('Arranjo: um menu só, filho direto da seção do Push, escondido, com os 5 itens do desenho na ordem')]
    public function testMenuComOsCincoItens(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '73000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->criarPublicacao($tenant, '73000002', self::NUMERO_DA_PASTA, '2026-08-21', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $menu = $crawler->filter('#push > .ps-push > #pushMenuContexto.ps-push-menu[role="menu"][hidden]');
        self::assertCount(1, $menu, 'um menu para a lista inteira, não um por cartão');
        self::assertCount(1, $menu->filter('#pushMenuContexto > .ps-push-menu-titulo'));

        $itens = $menu->filter('#pushMenuContexto > button.ps-push-menu-item[role="menuitem"]');
        self::assertSame(
            ['Adicionar nota técnica', 'Criar tarefa', 'Marcar como lida', 'Abrir documento', 'Copiar texto'],
            $itens->each(static fn ($b): string => trim($b->text())),
        );
        self::assertSame(
            ['bi bi-journal-plus', 'bi bi-check2-square', 'bi bi-envelope-open', 'bi bi-file-earmark-text', 'bi bi-clipboard'],
            $itens->each(static fn ($b): ?string => $b->filter('i')->attr('class')),
        );
        foreach (['js-push-menu-nota', 'js-push-menu-tarefa', 'js-push-menu-lida', 'js-push-menu-documento', 'js-push-menu-copiar'] as $i => $classe) {
            self::assertStringContainsString($classe, (string) $itens->eq($i)->attr('class'));
        }
        // Separadores entre os grupos, como no desenho ('-'): nota | tarefa, lida, documento | copiar.
        $filhos = $menu->filter('#pushMenuContexto > .ps-push-menu-item, #pushMenuContexto > .ps-push-menu-sep')
            ->each(static fn ($n): string => str_contains((string) $n->attr('class'), 'ps-push-menu-sep') ? '-' : 'i');
        self::assertSame(['i', '-', 'i', 'i', 'i', '-', 'i'], $filhos);
        self::assertCount(2, $menu->filter('#pushMenuContexto > .ps-push-menu-sep[role="separator"]'));
        // O item lida grava com o MESMO token do botão do teor (pasta_push_lida_<pasta>).
        self::assertNotSame('', (string) $menu->attr('data-lida-token'));
        // Itens do desenho sem lastro aqui não aparecem.
        self::assertStringNotContainsString('Encaminhar', $menu->text());
        self::assertStringNotContainsString('Link externo', $menu->text());
    }

    #[TestDox('O cartão expõe o que o JS procura: data-push-id no <li>, o cartão-botão com aria-controls e a dica do desenho')]
    public function testCartaoExpoeOsGatilhos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '73000003', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cab = $crawler->filter('.ps-push-lista > .ps-push-item[data-push-id="' . $pub->getId() . '"] > .ps-push-cartao > .ps-push-cab');
        self::assertCount(1, $cab);
        self::assertSame('push-teor-' . $pub->getId(), $cab->attr('aria-controls'));
        self::assertSame('Clique com o botão direito para adicionar uma nota técnica', $cab->attr('title'));
        self::assertCount(1, $cab->filter('.ps-push-assunto-txt'), 'o título do menu vem daqui');
        // "Marcar como (não) lida" do menu grava na mesma rota do botão do teor.
        self::assertSame(
            "/pasta/{$pasta->getId()}/push/{$pub->getId()}/lida",
            $crawler->filter('.ps-push-lista > .ps-push-item[data-push-id="' . $pub->getId() . '"]')->attr('data-push-lida-url'),
        );
        self::assertCount(1, $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"] > #push-teor-' . $pub->getId()));
    }

    #[TestDox('O teor traz os botões que o menu aciona: Criar tarefa e o "Adicionar nota técnica" do bloco desta publicação')]
    public function testTeorTrazOsBotoesQueOMenuAciona(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '73000004', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTexto('<p>Teor completo da publicação.</p>');
        $pub->setLink('https://pje.exemplo.jus.br/documento/73000004');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('.ps-push-acoes > .js-push-criar-meta'));
        self::assertCount(1, $crawler->filter('.ps-push-acoes > .js-push-lida[data-url][data-token]'), 'o menu clica este quando o teor está na página');
        self::assertSame(
            'https://pje.exemplo.jus.br/documento/73000004',
            $crawler->filter('.ps-push-doc > a.ps-push-doc-link[href]')->attr('href'),
            '"Abrir documento" do menu abre este link',
        );
        self::assertCount(1, $crawler->filter('#notas-publicacao-' . $pub->getId() . ' .js-nota-nova'));
        self::assertSame('Teor completo da publicação.', trim($crawler->filter('.djen-teor')->text()), '"Copiar texto" lê daqui');
    }

    /** Textos dos itens do menu, na ordem. @return list<string> */
    private function itensDoMenu(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('#pushMenuContexto > button.ps-push-menu-item')->each(static fn ($b): string => trim($b->text()));
    }

    #[TestDox('FPN-014c: quem só VÊ a pasta não recebe nota nem tarefa no menu, nem a dica do cartão; lida e leituras ficam')]
    public function testLeitorSemEscritaNoMenu(): void
    {
        $client          = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub    = $this->criarPublicacao($tenant, '73000011', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $leitor = $this->criarUsuarioSemPermissaoDoModulo($tenant);

        // Irmão: o admin (edita) recebe tudo, e o cartão aceita nota.
        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertSame(['Adicionar nota técnica', 'Criar tarefa', 'Marcar como lida', 'Abrir documento', 'Copiar texto'], $this->itensDoMenu($crawler));
        $item = $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"]');
        self::assertSame('1', $item->attr('data-push-aceita-nota'));
        self::assertSame('Clique com o botão direito para adicionar uma nota técnica', $item->filter('.ps-push-cab')->attr('title'));

        // O leitor: o pasta_push_lida aceita VIEW, então "Marcar como lida" fica.
        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();
        self::assertSame(['Marcar como lida', 'Abrir documento', 'Copiar texto'], $this->itensDoMenu($crawler));
        self::assertCount(1, $crawler->filter('#pushMenuContexto > .ps-push-menu-sep'), 'sem nota, sem o separador dela');
        $item = $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"]');
        self::assertSame('0', $item->attr('data-push-aceita-nota'));
        self::assertNull($item->filter('.ps-push-cab')->attr('title'), 'a dica não promete nota a quem não pode');
    }

    #[TestDox('FPN-014c: na pasta excluída (lápide) só sobram as leituras — nem nota, nem tarefa, nem lida (o listener recusaria o POST)')]
    public function testLapideSoLeituras(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '73000012', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertSame(['Abrir documento', 'Copiar texto'], $this->itensDoMenu($crawler));
        $item = $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"]');
        self::assertSame('0', $item->attr('data-push-aceita-nota'));
        self::assertNull($item->filter('.ps-push-cab')->attr('title'));
    }

    #[TestDox('Sem publicação não há menu (nem cartão para clicar)')]
    public function testSemPublicacaoSemMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertCount(0, $crawler->filter('#pushMenuContexto'));
    }
}
