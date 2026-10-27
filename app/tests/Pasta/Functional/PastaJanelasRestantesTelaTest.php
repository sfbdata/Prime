<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote S6-JS do Ciclo 6 na página renderizada: o X (PAS-022r) e o arraste (PAS-023r) moram em
 * elementos que o servidor entrega — a alça é o 1º filho de cada janela, e é nele que o
 * pasta-show.js põe o X e prende o PsArrastar. Arranjo com combinador de FILHO DIRETO; o X em
 * si nasce no navegador e é smoke do dono. E a página não sai mais com o `alert` do Excluir
 * (PAS-034b).
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaJanelasRestantesTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('PAS-022r/023r: título no topo do menu do Registro, busca no topo da lista do responsável, cabeçalho no topo do Histórico e da Timeline')]
    public function testAlcasSaoOPrimeiroFilho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#psRegMenu.ps-reg-menu[role="menu"] > .ps-processo-menu-titulo:first-child'));

        self::assertCount(1, $crawler->filter('#pastaRespMenu.pasta-resp-menu > .pasta-resp-busca-wrap:first-child'));
        self::assertCount(1, $crawler->filter('#pastaRespMenu > .pasta-resp-busca-wrap + #pastaRespLista[role="listbox"]'), 'a lista é irmã da busca');

        self::assertCount(1, $crawler->filter('#psHistorico.ps-drawer > .ps-drawer-cab:first-child > button.ps-drawer-fechar'), 'o drawer já tem o próprio X');
        self::assertCount(1, $crawler->filter('#psTimeline.ps-tl > .ps-tl-cab:first-child > button.ps-tl-fechar'), 'a Timeline já tem o próprio X');

        // Menu sem tarja: não há cabeçalho, a alça é o fundo do painel (a faixa do X).
        self::assertCount(1, $crawler->filter('#psPrioridade.ps-pop'));
        self::assertCount(0, $crawler->filter('#psPrioridade > .ps-pop-tarja'));
        self::assertCount(1, $crawler->filter('#psMenuAcoes.ps-pop > .ps-pop-tarja:first-child'), 'o ⋮ do cabeçalho segue com a tarja');
    }

    #[TestDox('PAS-034b: o Excluir avisa "Número não confere" pelo aviso da tela; a marcação do aviso e da janela está na página')]
    public function testExcluirSemAlertNativo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeCliente('Fulano de Tal');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // Os textos vêm do catálogo escapados para JS (`|e('js')`): desfaz o `\uXXXX` para ler a frase.
        $onsubmit = (string) preg_replace_callback(
            '/\\\\u([0-9A-Fa-f]{4})/',
            static fn (array $m): string => (string) mb_chr((int) hexdec($m[1]), 'UTF-8'),
            (string) $crawler->filter('#psMenuAcoes > form.js-excluir-pasta')->attr('onsubmit'),
        );
        self::assertStringContainsString("window.avisarPs('Número não confere. Digite " . $pasta->getNup() . " (o NUP da pasta, não o número da URL). A pasta não foi excluída.');", $onsubmit);
        self::assertStringContainsString('O navegador bloqueou a janela de confirmação.', $onsubmit, 'prompt silenciado (null imediato) avisa');
        self::assertStringNotContainsString('alert(', $onsubmit);
        // C7-PAS-03: "Excluir a pasta N (cliente)?" como o desenho (dc L.4336)
        self::assertStringContainsString("window.prompt('Excluir a pasta " . $pasta->getNup() . ' (FULANO DE TAL)?\\nEssa ação vai para a lixeira e fica registrada no histórico.\\n\\nPara confirmar, digite ' . $pasta->getNup() . ' (o NUP da pasta, não o número da URL):\')', $onsubmit);
        self::assertSame((string) $pasta->getNomeCliente(), $crawler->filter('#psMenuAcoes > form.js-excluir-pasta')->attr('data-cliente'));
        self::assertSame('Excluir a pasta {nup} ({cliente})?', self::catalogoDoMenu()['excluir_pergunta_cliente']);

        self::assertCount(1, $crawler->filter('#psAviso.ps-aviso'));
        self::assertCount(1, $crawler->filter('#psConfirmar.ps-cf-fundo'));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString("window.alert('Erro de comunicação. Tente novamente.')", $html, 'Favoritos');
        self::assertStringNotContainsString("window.confirm('Descartar as alterações não salvas dos dados da pasta?')", $html, 'Editar dados');
    }

    #[TestDox('C7-PAS-03: pasta sem cliente pergunta "Excluir a pasta N?", sem os parênteses do cliente')]
    public function testExcluirSemClientePerguntaSoONumero(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#psMenuAcoes > form.js-excluir-pasta');
        self::assertSame('', $form->attr('data-cliente'));
        // Os textos vêm do catálogo escapados para JS (`|e('js')`): desfaz o `\uXXXX` para ler a frase.
        $onsubmit = (string) preg_replace_callback(
            '/\\\\u([0-9A-Fa-f]{4})/',
            static fn (array $m): string => (string) mb_chr((int) hexdec($m[1]), 'UTF-8'),
            (string) $form->attr('onsubmit'),
        );
        self::assertStringContainsString("window.prompt('Excluir a pasta " . $pasta->getNup() . '?\\nEssa ação vai para a lixeira e fica registrada no histórico.\\n\\nPara confirmar, digite ' . $pasta->getNup() . ' (o NUP da pasta, não o número da URL):\')', $onsubmit);
        self::assertSame('Excluir a pasta {nup}?', self::catalogoDoMenu()['excluir_pergunta']);
    }

    /** @return array<string, string> */
    private static function catalogoDoMenu(): array
    {
        return CatalogoPtBr::carregar()['pasta_cabecalho']['cabecalho']['menu'];
    }
}
