<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Encaminhar via Chat I.A" nos registros de Dados, Detalhes e Financeiro e no arquivo do Financeiro (PR4a,
 * `docs/specs/encaminhar-via-chat-ia.md` §4.1; desenho 02, D:1405, D:1936, D:2463, D:6305, D:6869 e D:6958).
 *
 * O ↪ espelhado do cartão e o item "Encaminhar via Chat I.A" dos menus, na posição exata do desenho (combinador de
 * filho direto), levando só identificadores (`data-encaminhar-pasta`, `-tipo`, `-id`); sem a Central (vínculo
 * inativo) e na lápide nada disso é montado. O clique é lido no script (teste de folha): ele dispara
 * `bj-central:despachar` com `{pastaId, tipo, itemId}`. A tela é do smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaEncaminharRegistrosTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const ROTULO = 'Encaminhar via Chat I.A';

    /** @return array{PastaMensagem, PastaObservacaoDetalhes, PastaObservacaoFinanceira, PastaDocumento} */
    private function registros(Pasta $pasta, User $autor, Tenant $tenant): array
    {
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('<p>Cliente ligou pedindo retorno.</p>');
        $this->em()->persist($msg);

        $det = new PastaObservacaoDetalhes();
        $det->setPasta($pasta);
        $det->setTenant($tenant);
        $det->setAutor($autor);
        $det->setConteudo('Relato do cliente sobre o acidente.');
        $this->em()->persist($det);

        $fin = (new PastaObservacaoFinanceira())
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setAutor($autor)
            ->setConteudo('Honorários de 30% combinados.');
        $this->em()->persist($fin);

        $doc = new PastaDocumento();
        $doc->setTitulo('Contrato de honorários.pdf');
        $doc->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
        $doc->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf');
        $doc->setNomeOriginal('contrato.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(14);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $this->em()->persist($doc);

        $this->em()->flush();

        return [$msg, $det, $fin, $doc];
    }

    /** @return array{KernelBrowser, Crawler, Pasta, PastaMensagem, PastaObservacaoDetalhes, PastaObservacaoFinanceira, PastaDocumento} */
    private function abrir(bool $comCentral = true, bool $lapide = false): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        [$msg, $det, $fin, $doc] = $this->registros($pasta, $user, $tenant);
        if ($lapide) {
            $pasta->marcarExcluida($user, new \DateTimeImmutable());
            $this->em()->flush();
        }

        $this->logarComTenant($client, $user, $tenant);
        if (!$comCentral) {
            // O portão da Central é o vínculo ATIVO com o escritório (`central_disponivel()`); o super admin
            // segue vendo a pasta pelo bypass.
            $this->em()->getConnection()->executeStatement(
                'UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?',
                [$user->getId(), $tenant->getId()],
            );
        }

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return [$client, $crawler, $pasta, $msg, $det, $fin, $doc];
    }

    /** O ↪ do desenho (D:1405/1936/2463): 28×26 pela classe do Responder, título e ícone espelhado. */
    private function assertBotaoDoDesenho(Crawler $botao, string $tipo, int $itemId, int $pastaId): void
    {
        self::assertCount(1, $botao);
        self::assertSame('button', $botao->attr('type'));
        self::assertSame(self::ROTULO, $botao->attr('title'));
        self::assertSame(self::ROTULO, $botao->attr('aria-label'));
        self::assertSame($tipo, $botao->attr('data-encaminhar-tipo'));
        self::assertSame((string) $itemId, $botao->attr('data-encaminhar-id'));
        self::assertSame((string) $pastaId, $botao->attr('data-encaminhar-pasta'));
        self::assertStringContainsString('ps-anotacao-acao--responder', (string) $botao->attr('class'), 'o 28×26, #7fb3cf, 15px do Responder (data-acao)');
        $icone = $botao->filter('button > i.bi.bi-reply-fill');
        self::assertCount(1, $icone);
        self::assertSame('display:inline-block;transform:scaleX(-1)', $icone->attr('style'), 'espelhado, como o desenho');
    }

    /** @return list<string> */
    private function rotulos(Crawler $itens): array
    {
        return $itens->each(static fn (Crawler $i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''));
    }

    #[TestDox('D:1405: o ↪ do cartão de Dados vem depois do Responder e antes da hora, com tipo registro_dados e o id da mensagem')]
    public function testBotaoNoCartaoDeDados(): void
    {
        [, $crawler, $pasta, $msg] = $this->abrir();
        $id = $msg->getId();

        $botao = $crawler->filter("#timelineList > #pasta-msg-{$id} > .ps-anotacao-topo > .btn-responder-msg-pasta + button.js-encaminhar + .ps-anotacao-data");
        self::assertCount(1, $botao, 'Responder, ↪, hora: a ordem do desenho');
        $this->assertBotaoDoDesenho(
            $crawler->filter("#pasta-msg-{$id} > .ps-anotacao-topo > .btn-responder-msg-pasta + button.js-encaminhar"),
            'registro_dados',
            (int) $id,
            (int) $pasta->getId(),
        );
        self::assertSame((string) $pasta->getId(), $crawler->filter('#timelineList')->attr('data-encaminhar-pasta'), 'a flag do cartão montado em JS');
    }

    #[TestDox('D:6305: no menu do registro de Dados, "Encaminhar via Chat I.A" (bi-send) vem logo depois de Copiar texto e antes do separador')]
    public function testItemNoMenuDeDados(): void
    {
        [, $crawler] = $this->abrir();

        $menu = $crawler->filter('section.ps-anotacoes > #psRegMenu');
        self::assertSame(
            ['Responder', 'Copiar texto', self::ROTULO, 'Editar', 'Excluir comentário'],
            $this->rotulos($menu->filter('#psRegMenu > .ps-processo-menu-item')),
        );
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-copiar + .js-reg-encaminhar[role="menuitem"] + .ps-processo-menu-sep'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-encaminhar > i.bi.bi-send'));
    }

    #[TestDox('D:2463: o ↪ da observação de Detalhes vem depois do Responder e antes da hora, com tipo registro_detalhes e o id da observação')]
    public function testBotaoNaObservacaoDeDetalhes(): void
    {
        [, $crawler, $pasta, , $det] = $this->abrir();
        $id = $det->getId();

        self::assertCount(1, $crawler->filter("#detalhesObsLista > #detalhes-obs-{$id} > .ps-anotacao-topo > .btn-responder-obs-det + button.js-encaminhar + .ps-anotacao-data"));
        $this->assertBotaoDoDesenho(
            $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > .btn-responder-obs-det + button.js-encaminhar"),
            'registro_detalhes',
            (int) $id,
            (int) $pasta->getId(),
        );
        self::assertSame((string) $pasta->getId(), $crawler->filter('#detalhesObsLista')->attr('data-encaminhar-pasta'));
    }

    #[TestDox('D:6869: no ⋮ da observação de Detalhes, "Encaminhar via Chat I.A" (bi-send) fica entre Copiar texto e Citar no novo registro')]
    public function testItemNoMenuDeDetalhes(): void
    {
        [, $crawler, , , $det] = $this->abrir();
        $id = $det->getId();

        $menu = $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > #psObsMenu{$id}");
        self::assertCount(1, $menu);
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-copiar + .js-obs-encaminhar[role=\"menuitem\"] + .js-obs-citar"));
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-encaminhar > i.bi.bi-send"));
        self::assertSame(self::ROTULO, trim($menu->filter("#psObsMenu{$id} > .js-obs-encaminhar")->text()));
    }

    #[TestDox('D:1936: o ↪ do registro do Financeiro vem depois do Responder e antes da hora, com tipo registro_financeiro e o id do registro')]
    public function testBotaoNoRegistroDoFinanceiro(): void
    {
        [, $crawler, $pasta, , , $fin] = $this->abrir();
        $id = $fin->getId();

        self::assertCount(1, $crawler->filter("#financeiroObsLista > #financeiro-obs-{$id} > .ps-anotacao-topo > .btn-responder-obs + button.js-encaminhar + .ps-anotacao-data"));
        $this->assertBotaoDoDesenho(
            $crawler->filter("#financeiro-obs-{$id} > .ps-anotacao-topo > .btn-responder-obs + button.js-encaminhar"),
            'registro_financeiro',
            (int) $id,
            (int) $pasta->getId(),
        );
        self::assertSame((string) $pasta->getId(), $crawler->filter('#financeiroObsLista')->attr('data-encaminhar-pasta'));
    }

    #[TestDox('D:6305: no menu do registro do Financeiro, "Encaminhar via Chat I.A" (bi-send) vem logo depois de Copiar texto e antes do separador')]
    public function testItemNoMenuDoFinanceiro(): void
    {
        [, $crawler] = $this->abrir();

        $menu = $crawler->filter('#financeiroRelatorio > #financeiroObsMenu');
        self::assertSame(
            ['Responder', 'Copiar texto', self::ROTULO, 'Editar', 'Excluir comentário'],
            $this->rotulos($menu->filter('#financeiroObsMenu > button.ps-fin-menu-item')),
        );
        self::assertCount(1, $menu->filter('#financeiroObsMenu > .js-fin-obs-copiar + .js-fin-obs-encaminhar[role="menuitem"] + .ps-fin-menu-sep'));
        self::assertCount(1, $menu->filter('#financeiroObsMenu > .js-fin-obs-encaminhar > i.bi.bi-send'));
    }

    #[TestDox('D:6958: no ⋮ do arquivo do Financeiro, "Encaminhar via Chat I.A" (bi-send) vem logo depois de Copiar nome do arquivo, com tipo documento e o id do arquivo')]
    public function testItemNoMenuDoArquivoDoFinanceiro(): void
    {
        [, $crawler, $pasta, , , , $doc] = $this->abrir();
        $id = $doc->getId();

        $menu = $crawler->filter("#financeiro-doc-{$id} > .ps-fin-doc-acoes > #financeiro-doc-menu-{$id}");
        self::assertCount(1, $menu);
        self::assertSame(
            ['Abrir', 'Baixar', 'Copiar nome do arquivo', self::ROTULO, 'Renomear', 'Marcar como assinado', 'Enviar por e-mail ao cliente', 'Ver em Documentos', 'Excluir'],
            $this->rotulos($menu->filter("#financeiro-doc-menu-{$id} > .ps-fin-menu-item")),
        );
        $item = $menu->filter("#financeiro-doc-menu-{$id} > .js-fin-doc-copiar-nome + button.ps-fin-menu-item.js-encaminhar[role=\"menuitem\"]");
        self::assertCount(1, $item);
        self::assertCount(1, $item->filter('button > i.bi.bi-send'));
        self::assertSame('documento', $item->attr('data-encaminhar-tipo'));
        self::assertSame((string) $id, $item->attr('data-encaminhar-id'));
        self::assertSame((string) $pasta->getId(), $item->attr('data-encaminhar-pasta'));
        self::assertSame((string) $pasta->getId(), $crawler->filter('#financeiro-docs-lista')->attr('data-encaminhar-pasta'));
    }

    #[TestDox('Sem a Central (vínculo inativo no escritório): nenhum ↪, nenhum item "Encaminhar via Chat I.A" e nenhuma flag nas listas')]
    public function testSomeSemACentral(): void
    {
        [, $crawler] = $this->abrir(comCentral: false);

        self::assertCount(0, $crawler->filter('[data-central-flutuante]'), 'pré-condição: sem o lançador da Central');
        $this->assertSemEncaminhar($crawler);
        // O resto dos menus segue de pé.
        self::assertSame(
            ['Responder', 'Copiar texto', 'Editar', 'Excluir comentário'],
            $this->rotulos($crawler->filter('#psRegMenu > .ps-processo-menu-item')),
        );
    }

    #[TestDox('Na lápide o despacho dá 404 (spec §2.11): nem ↪ nem item, mesmo com a Central')]
    public function testSomeNaLapide(): void
    {
        [, $crawler] = $this->abrir(lapide: true);

        $this->assertSemEncaminhar($crawler);
    }

    private function assertSemEncaminhar(Crawler $crawler): void
    {
        self::assertCount(0, $crawler->filter('.js-encaminhar, .js-reg-encaminhar, .js-obs-encaminhar, .js-fin-obs-encaminhar'));
        self::assertCount(0, $crawler->filter('#timelineList[data-encaminhar-pasta], #detalhesObsLista[data-encaminhar-pasta], #financeiroObsLista[data-encaminhar-pasta], #financeiro-docs-lista[data-encaminhar-pasta]'));
        self::assertCount(0, $crawler->filter('[title="' . self::ROTULO . '"]'));
        foreach (['#psRegMenu', '#financeiroObsMenu', '.ps-obs-menu', '#financeiro-docs-lista .ps-fin-menu'] as $menu) {
            $crawler->filter($menu)->each(fn (Crawler $m) => self::assertStringNotContainsString(self::ROTULO, $m->text()));
        }
    }

    #[TestDox('O clique dispara bj-central:despachar com {pastaId, tipo, itemId}, lidos dos data-* (evento, não window.BJCentral)')]
    public function testScriptDisparaOEvento(): void
    {
        [, $crawler] = $this->abrir();
        self::assertCount(1, $crawler->filter('script[src*="js/pasta-encaminhar.js"]'));

        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/pasta-encaminhar.js');
        self::assertStringContainsString("document.addEventListener('click'", $js);
        self::assertStringContainsString("closest('.js-encaminhar[data-encaminhar-tipo]')", $js);
        self::assertStringContainsString("Number(alvo.getAttribute('data-encaminhar-pasta'))", $js);
        self::assertStringContainsString("Number(alvo.getAttribute('data-encaminhar-id'))", $js);
        self::assertStringContainsString("document.dispatchEvent(new CustomEvent('bj-central:despachar', {", $js);
        self::assertStringContainsString('detail: { pastaId: pastaId, tipo: tipo, itemId: itemId }', $js);
        self::assertStringNotContainsString('BJCentral.despachar(', $js, 'o evento não depende da ordem de carga');
    }

    #[TestDox('Os itens dos menus de registro clicam o ↪ do próprio cartão, e os cartões montados em JS leem a flag do atributo e só põem ids numéricos')]
    public function testMenusECartoesMontadosEmJs(): void
    {
        [$client] = $this->abrir();
        $html = (string) $client->getResponse()->getContent();

        // Dados (show.html.twig): o item do #psRegMenu e o cartão de `inserirMensagem`.
        self::assertStringContainsString("item.classList.contains('js-reg-encaminhar') ? ':scope > .ps-anotacao-topo > .js-encaminhar'", $html);
        self::assertStringContainsString('const encaminharPasta = Number(list.dataset.encaminharPasta || 0);', $html);
        self::assertStringContainsString('data-encaminhar-tipo="registro_dados"' . "\n" . '                        data-encaminhar-id="${Number(data.id)}">', $html);

        // Detalhes: o item do ⋮ (no pasta-encaminhar.js, fora do script de Detalhes) e `htmlDoCartao`.
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/pasta-encaminhar.js');
        self::assertStringContainsString("e.target.closest('.js-obs-encaminhar')", $js);
        self::assertStringContainsString("artigo.querySelector(':scope > .ps-anotacao-topo > .js-encaminhar')", $js);
        self::assertStringContainsString("document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));", $js, 'o ⋮ de Detalhes fecha');
        self::assertStringContainsString('const encaminharPasta = Number(obsLista.dataset.encaminharPasta || 0);', $html);
        self::assertStringContainsString('data-encaminhar-tipo="registro_detalhes"', $html);

        // Financeiro: o item do #financeiroObsMenu, o cartão do `_financeiro_obs_script` e `criarItemDoc`.
        self::assertStringContainsString("item.classList.contains('js-fin-obs-encaminhar') ? '.js-encaminhar'", $html);
        self::assertStringContainsString('data-encaminhar-tipo="registro_financeiro"', $html);
        self::assertStringContainsString('const encaminharPasta = Number(docLista.dataset.encaminharPasta || 0);', $html);
        self::assertStringContainsString('data-encaminhar-id="${Number(id)}">', $html);
    }
}
