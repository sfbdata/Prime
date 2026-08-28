<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Tests\Factory\Cobranca\EventoHistoricoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A timeline do Histórico (redesenho 1a): chip de família por evento e os quatro filtros.
 *
 * ⚠️ O recorte é no CLIENTE, e PHPUnit não executa JS — o que este teste prova é o CONTRATO de que o JS
 * depende, e só ele: cada item carrega a própria família em `data-hist-familia`, cada botão carrega a
 * chave em `data-hist-filtro`, e as chaves dos botões existem entre as famílias dos itens. Sem isso o
 * filtro clica e não acontece nada — falha silenciosa, que é a pior forma de quebrar.
 *
 * A decisão do dono (28/08) de filtrar no cliente veio de uma medição: o handoff supunha que os filtros
 * exigiriam um recorte novo no `EventoHistoricoRepository`, e não exigem — `doCaso` sempre trouxe o
 * histórico INTEIRO, e é ele que já está no HTML. O teste abaixo também fixa isso: os eventos
 * renderizados são TODOS, não uma página.
 */
#[CoversClass(ObjetoController::class)]
final class TimelineHistoricoFiltrosTest extends CobrancaWebTestCase
{
    #[TestDox('Cada evento leva a própria família no marcador que o JS lê, e um chip que a nomeia')]
    public function testCadaEventoCarregaAFamilia(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Um de cada família com filtro próprio, mais um de `cadastro` (que não tem botão).
        // Lista de pares (e não mapa com o enum na chave): `enum` não é offset válido de array em PHP.
        $tipos = [
            [TipoEventoHistorico::ContatoRealizado, 'contatos'],
            [TipoEventoHistorico::PagamentoRegistrado, 'dinheiro'],
            [TipoEventoHistorico::ObrigacaoCriada, 'obrigacoes'],
            [TipoEventoHistorico::Anotacao, 'anotacoes'],
            [TipoEventoHistorico::Judicializacao, 'cadastro'],
        ];
        foreach ($tipos as [$tipo, $familia]) {
            EventoHistoricoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso, 'tipo' => $tipo,
                'descricao' => 'Evento de ' . $familia,
            ]);
        }

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $itens = $crawler->filter('#tab-historico .cob-hist-item');
        self::assertGreaterThanOrEqual(5, $itens->count(), 'os cinco eventos semeados têm de estar na tela');

        // Nenhum item pode ficar sem família: um `data-hist-familia` vazio some de TODOS os filtros e
        // volta a aparecer só em "Tudo" — o evento existe e o operador não o encontra.
        foreach ($itens->each(static fn ($li) => (string) $li->attr('data-hist-familia')) as $familia) {
            self::assertNotSame('', $familia, 'evento sem família some de todos os filtros');
        }

        $familiasNaTela = array_unique($itens->each(static fn ($li) => (string) $li->attr('data-hist-familia')));
        foreach (['contatos', 'dinheiro', 'obrigacoes', 'anotacoes', 'cadastro'] as $esperada) {
            self::assertContains($esperada, $familiasNaTela, "faltou um evento da família {$esperada}");
        }

        // O ponto do trilho é colorido POR família (`is-<familia>`) — é o que deixa varrer a coluna.
        self::assertCount($itens->count(), $crawler->filter('#tab-historico .cob-hist-ponto'));
        self::assertGreaterThan(0, $crawler->filter('#tab-historico .cob-hist-ponto.is-dinheiro')->count());
    }

    #[TestDox('Os quatro filtros + Tudo existem, o primeiro nasce ativo, e cada chave casa com uma família')]
    public function testOsFiltrosCasamComAsFamiliasRenderizadas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        EventoHistoricoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'tipo' => TipoEventoHistorico::PagamentoRegistrado, 'descricao' => 'Recebimento',
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $botoes = $crawler->filter('#tab-historico .cob-hist-filtro');
        self::assertSame(
            ['tudo', 'contatos', 'dinheiro', 'obrigacoes', 'anotacoes'],
            $botoes->each(static fn ($b) => (string) $b->attr('data-hist-filtro')),
            'os cinco chips, na ordem do desenho',
        );

        // "Tudo" nasce ativo: sem isso a tela abre com um filtro aplicado que ninguém escolheu.
        self::assertStringContainsString('active', (string) $botoes->eq(0)->attr('class'));
        self::assertSame('true', $botoes->eq(0)->attr('aria-pressed'));
        self::assertCount(1, $crawler->filter('#tab-historico .cob-hist-filtro.active'), 'só um chip nasce ativo');

        // O aviso de "nenhum evento deste tipo" existe e nasce escondido — o JS o revela quando o
        // filtro não deixa nada de pé. Sem ele, filtrar até o vazio deixaria a aba muda.
        $vazio = $crawler->filter('#tab-historico [data-hist-vazio]');
        self::assertCount(1, $vazio);
        self::assertNotNull($vazio->attr('hidden'), 'o aviso de vazio nasce escondido');
    }

    #[TestDox('A timeline traz o histórico INTEIRO — o filtro esconde linha, não deixa de buscá-la')]
    public function testATimelineNaoPagina(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // 25 eventos: mais que os "20 por página" que o desenho propunha e que o dono recusou em 28/08,
        // justamente para o Ctrl+F continuar achando o evento antigo.
        for ($i = 1; $i <= 25; $i++) {
            EventoHistoricoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso,
                'tipo' => TipoEventoHistorico::ContatoRealizado,
                'descricao' => 'Contato numero ' . $i,
            ]);
        }

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $painel = $crawler->filter('#tab-historico');
        self::assertStringContainsString('Contato numero 1', $painel->text(), 'o mais antigo continua no HTML');
        self::assertStringContainsString('Contato numero 25', $painel->text());
        self::assertCount(0, $crawler->filter('#tab-historico [data-hist-carregar-mais]'), 'não há paginação nesta aba');
    }
}
