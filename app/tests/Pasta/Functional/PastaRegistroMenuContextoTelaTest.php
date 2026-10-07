<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PAS-025: o menu do botão direito no cartão do Registro (desenho dc L.5958, `menu` de
 * `regsLinha`) — Responder, Copiar texto, Editar e Excluir com os minutos ou "· bloqueado" — e
 * o ⋮ discreto que leva o mesmo menu ao teclado e ao toque. Markup pelo Twig e pelo cartão
 * montado em JS; o comportamento é lido no script (teste de folha), a tela é do smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaRegistroMenuContextoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function registrar(Pasta $pasta, User $autor, Tenant $tenant, string $quando): PastaMensagem
    {
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('<p>Cliente ligou pedindo retorno.</p>');
        (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($msg, new \DateTimeImmutable($quando));
        $this->em()->persist($msg);
        $this->em()->flush();

        return $msg;
    }

    private function html(object $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    /** O trecho do script do Registro entre dois marcadores. */
    private function trecho(string $html, string $de, int $tamanho = 4000): string
    {
        $ini = strpos($html, $de);
        self::assertNotFalse($ini, "não achou: {$de}");

        return substr($html, $ini, $tamanho);
    }

    #[TestDox('PAS-025/EDT-003: o menu único #psRegMenu tem, na ordem do desenho, Responder, Copiar texto, separador, Editar, Excluir comentário e Imprimir, com os ícones')]
    public function testMenuUnicoComOsItensDoDesenho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->registrar($pasta, $user, $tenant, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $menu = $crawler->filter('section.ps-anotacoes > #psRegMenu.ps-reg-menu[role="menu"][hidden]');
        self::assertCount(1, $menu, 'um menu só, fechado, dentro do cartão do Registro');
        self::assertCount(1, $crawler->filter('#psRegMenu'));
        $itens = $menu->filter('#psRegMenu > .ps-processo-menu-item')->each(
            static fn ($i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''),
        );
        self::assertSame(['Responder', 'Copiar texto', 'Editar', 'Excluir comentário', 'Imprimir'], $itens);
        // EDT-003 (dc L.6463): "Imprimir" é o último item, com a impressora.
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-excluir + button.js-reg-imprimir[role="menuitem"]:last-child > i.bi.bi-printer'));
        // Não é o `.js-obs-imprimir` de Detalhes: aquele procura o cartão como ANCESTRAL do item.
        self::assertCount(0, $menu->filter('#psRegMenu .js-obs-imprimir'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-responder > i.bi-reply-fill'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-copiar > i.bi-clipboard'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-copiar + .ps-processo-menu-sep[role="separator"] + .js-reg-editar > i.bi-pencil'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-excluir > i.bi-trash3'));
        self::assertCount(1, $menu->filter('#psRegMenu > .ps-processo-menu-titulo.js-reg-menu-titulo'));
    }

    #[TestDox('PAS-025: cada cartão tem o ⋮ discreto logo depois da hora (teclado e toque) e carrega o fim da janela e se é do autor')]
    public function testCartaoTemOMenuEOsDadosDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $meu             = $this->registrar($pasta, $user, $tenant, '-5 minutes');
        $alheio          = $this->registrar($pasta, $outro, $tenant, '-30 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        foreach ([$meu, $alheio] as $msg) {
            $id      = $msg->getId();
            $gatilho = $crawler->filter("#pasta-msg-{$id} > .ps-anotacao-topo > .ps-anotacao-data + button.js-reg-menu.ps-reg-menu-btn[aria-haspopup=\"menu\"][aria-controls=\"psRegMenu\"]");
            self::assertCount(1, $gatilho, 'o ⋮ é o irmão logo depois da hora');
            self::assertSame('Mais ações do registro', $gatilho->attr('aria-label'));
            self::assertCount(1, $gatilho->filter('i.bi-three-dots-vertical'));
        }

        $artigo = $crawler->filter("#pasta-msg-{$meu->getId()}");
        $criada = $meu->getCriadaEm();
        self::assertSame((string) ($criada->getTimestamp() + 15 * 60), $artigo->attr('data-janela-ate'));
        self::assertSame('1', $artigo->attr('data-do-autor'));
        self::assertSame('0', $crawler->filter("#pasta-msg-{$alheio->getId()}")->attr('data-do-autor'));
        // As ações reais que o menu aciona continuam no cartão do autor dentro da janela.
        self::assertCount(1, $crawler->filter("#pasta-msg-{$meu->getId()} .ps-anotacao-acoes > .btn-editar-msg-pasta"));
        self::assertCount(0, $crawler->filter("#pasta-msg-{$alheio->getId()} .ps-anotacao-acoes"));
    }

    #[TestDox('PAS-025: o cartão montado em JS (registro recém-enviado) tem o mesmo ⋮ e os dados da janela')]
    public function testCartaoDoJsTemOMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        $html = $this->html($client);

        $ini      = (int) strpos($html, 'function inserirMensagem(data)');
        $montador = substr($html, $ini, (int) strpos($html, 'list.classList.remove(\'ps-registro--vazio\')', $ini) - $ini);
        self::assertStringContainsString('data-janela-ate="${Math.floor(Number(data.criadaEmTs) / 1000) + 15 * 60}"', $montador);
        self::assertStringContainsString('data-do-autor="1"', $montador);
        $data = strpos($montador, '<span class="ps-anotacao-data ps-num"');
        $btn  = strpos($montador, 'class="ps-reg-menu-btn js-reg-menu"');
        self::assertNotFalse($btn, 'o cartão do JS não tem o ⋮');
        self::assertLessThan($btn, (int) $data, 'a hora vem antes do ⋮, como no Twig');
        self::assertStringContainsString('aria-controls="psRegMenu"', $montador);
    }

    #[TestDox('PAS-025: o botão direito abre o menu no ponto do clique, poupando links, botões, edição e texto selecionado; o ⋮ abre o mesmo menu')]
    public function testScriptDoBotaoDireito(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        $html = $this->html($client);

        $ctx = $this->trecho($html, "list.addEventListener('contextmenu'", 700);
        self::assertStringContainsString(".closest('.ps-anotacao[id^=\"pasta-msg-\"]')", $ctx);
        foreach (['a, button', 'textarea', '[contenteditable="true"]', '.ps-anotacao-edicao'] as $poupado) {
            self::assertStringContainsString($poupado, $ctx, "o botão direito em {$poupado} segue com o menu do navegador");
        }
        self::assertStringContainsString('window.getSelection', $ctx);
        self::assertStringContainsString('e.preventDefault()', $ctx);
        self::assertStringContainsString('abrirMenuReg(cartao, null, e.clientX, e.clientY)', $ctx);

        $clique = $this->trecho($html, "const gatilho = e.target.closest('.js-reg-menu');", 200);
        self::assertStringContainsString("abrirMenuReg(gatilho.closest('.ps-anotacao'), gatilho)", $clique);
    }

    #[TestDox('PAS-025: Editar/Excluir levam "· N min" na janela; fora dela "· bloqueado", cadeado, desabilitados e com o motivo; os itens clicam as ações reais do cartão')]
    public function testScriptDosItens(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        $html = $this->html($client);

        $abrir = $this->trecho($html, 'function abrirMenuReg(cartao, gatilho, x, y)', 3000);
        self::assertStringContainsString("'Editar · ' + resto + ' min', 'bi-pencil'", $abrir);
        self::assertStringContainsString("'Excluir comentário · ' + resto + ' min', 'bi-trash3'", $abrir);
        self::assertStringContainsString("'Editar · bloqueado', 'bi-lock', motivo", $abrir);
        self::assertStringContainsString("'Excluir · bloqueado', 'bi-lock', motivo", $abrir);
        self::assertStringContainsString("cartao.querySelector('.ps-anotacao-acoes .btn-editar-msg-pasta') && resto > 0", $abrir);
        self::assertStringContainsString("cartao.dataset.doAutor === '1' ? REG_MOTIVO_PRAZO : REG_MOTIVO_AUTOR", $abrir);
        self::assertStringContainsString("responder.hidden = !cartao.querySelector('.btn-responder-msg-pasta')", $abrir);
        self::assertStringContainsString(':not([disabled])', $abrir, 'o foco não cai num item bloqueado');

        self::assertStringContainsString("const REG_MOTIVO_PRAZO = 'O prazo de 15 minutos para editar ou excluir terminou.';", $html);
        self::assertStringContainsString("const REG_MOTIVO_AUTOR = 'Só o autor pode editar ou excluir, nos 15 minutos após publicar.';", $html);

        $pintar = $this->trecho($html, 'function pintarItemReg(', 500);
        self::assertStringContainsString('item.disabled = !pode', $pintar);
        self::assertStringContainsString("item.setAttribute('aria-disabled', 'true'); item.title = motivo;", $pintar);

        $minutos = $this->trecho($html, 'function minutosRestantesReg(cartao)', 500);
        self::assertStringContainsString('Math.min(15, Math.max(1, Math.ceil(ms / 60000)))', $minutos, 'a regra do servidor: teto, de 1 a 15');
        self::assertStringContainsString('if (!ate || ms <= 0) { return 0; }', $minutos);

        $itens = $this->trecho($html, "menuReg.addEventListener('click'", 1300);
        self::assertStringContainsString('item.disabled', $itens);
        self::assertStringContainsString("'.btn-responder-msg-pasta'", $itens);
        self::assertStringContainsString("'.ps-anotacao-acoes .btn-editar-msg-pasta'", $itens);
        self::assertStringContainsString("'.ps-anotacao-acoes .btn-excluir-msg-pasta'", $itens);
        self::assertStringContainsString('if (alvo) { alvo.click(); }', $itens);
        self::assertStringContainsString('copiarTextoReg(item, cartao)', $itens);

        // EDT-003: Imprimir manda o item (com autor e data do cartão alvo) e o PRÓPRIO cartão à janela.
        self::assertStringContainsString("window.PastaImpressao.registro(item, cartao)", $itens);
        $imprimir = $this->trecho($html, "const imprimir = menuReg.querySelector('.js-reg-imprimir');", 500);
        self::assertStringContainsString("imprimir.setAttribute('data-autor', autorEl ? autorEl.textContent.trim() : '')", $imprimir);
        self::assertStringContainsString("imprimir.setAttribute('data-quando', dataEl && dataEl.title ? dataEl.title.replace(' ', ', ') : '')", $imprimir);
        self::assertStringNotContainsString('data-tipo', $imprimir, 'o registro do desenho não tem tipo');

        $copiar = $this->trecho($html, 'function copiarTextoReg(item, cartao)', 1200);
        self::assertStringContainsString(".pasta-msg-conteudo .ql-editor", $copiar);
        self::assertStringContainsString('navigator.clipboard.writeText(texto)', $copiar);
        self::assertStringContainsString("avisar('Texto copiado')", $copiar);
    }
}
