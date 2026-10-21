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

    #[TestDox('PAS-025: o menu único #psRegMenu tem, na ordem do desenho, Responder, Copiar texto, Encaminhar via Chat I.A, separador, Editar e Excluir comentário, com os ícones')]
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
        self::assertSame(['Responder', 'Copiar texto', 'Encaminhar via Chat I.A', 'Editar', 'Excluir comentário'], $itens);
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-responder > i.bi-reply-fill'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-copiar > i.bi-clipboard'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-copiar + .js-reg-encaminhar + .ps-processo-menu-sep[role="separator"] + .js-reg-editar > i.bi-pencil'));
        self::assertCount(1, $menu->filter('#psRegMenu > .js-reg-excluir > i.bi-trash3'));
        self::assertCount(1, $menu->filter('#psRegMenu > .ps-processo-menu-titulo.js-reg-menu-titulo'));
    }

    #[TestDox('Desenho (dc L.6454-6466): "Imprimir" mora só no ⋮ da observação de Detalhes; o menu do Registro não tem o item nem o ramo no JS')]
    public function testMenuDoRegistroNaoTemImprimir(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->registrar($pasta, $user, $tenant, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // `menuObs`/`extrasObs` do desenho (dc L.6454-6466) são da observação de Detalhes;
        // o Registro da aba Dados não oferece Imprimir.
        $menu = $crawler->filter('#psRegMenu');
        self::assertCount(1, $menu);
        self::assertCount(0, $menu->filter('.js-reg-imprimir, .js-obs-imprimir, .bi-printer'));
        self::assertStringNotContainsString('Imprimir', $menu->text());

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('js-reg-imprimir', $html);
        self::assertStringNotContainsString('PastaImpressao.registro(', $this->trecho($html, "menuReg.addEventListener('click'", 1000));
    }

    #[TestDox('PAS-025b: na pasta excluída (lápide) o autor dentro da janela não recebe escrita — sem Editar, Excluir nem Responder no cartão e no menu; Copiar texto fica')]
    public function testLapideSemEscritaNoRegistro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        // Do próprio usuário e dentro da janela: fora da lápide teria Editar/Excluir ativos.
        $msg = $this->registrar($pasta, $user, $tenant, '-5 minutes');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $cartao = $crawler->filter("#pasta-msg-{$msg->getId()}");
        self::assertCount(1, $cartao, 'o registro continua à vista');
        self::assertCount(0, $cartao->filter('.ps-anotacao-acoes, .btn-editar-msg-pasta, .btn-excluir-msg-pasta, .btn-responder-msg-pasta'));
        self::assertCount(1, $cartao->filter('.js-reg-menu'), 'o ⋮ fica: Copiar texto é leitura');

        $menu  = $crawler->filter('section.ps-anotacoes > #psRegMenu');
        $itens = $menu->filter('#psRegMenu > .ps-processo-menu-item')->each(
            static fn ($i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''),
        );
        self::assertSame(['Copiar texto'], $itens);
        self::assertCount(0, $menu->filter('.js-reg-responder, .js-reg-editar, .js-reg-excluir, .ps-processo-menu-sep'));

        // O JS do menu tolera a ausência dos itens de escrita (senão o ⋮ quebraria na lápide).
        $abrir = $this->trecho($this->html($client), 'function abrirMenuReg(cartao, gatilho, x, y)', 2200);
        self::assertStringContainsString("if (responder) { responder.hidden = !cartao.querySelector('.btn-responder-msg-pasta'); }", $abrir);
        self::assertStringContainsString('if (!editar || !excluir) {', $abrir);
    }

    #[TestDox('PAS-025c: na lápide o compositor do Registro (#formTimelineMensagem) não é montado e entra a nota de só leitura')]
    public function testLapideSemCompositorNoRegistro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg             = $this->registrar($pasta, $user, $tenant, '-5 minutes');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter("#pasta-msg-{$msg->getId()}"), 'o registro continua à vista');
        self::assertCount(0, $crawler->filter('#formTimelineMensagem, #timelineConteudo, #btnEnviarMensagem'));
        $nota = $crawler->filter('section.ps-anotacoes > .ps-compositor--leitura#timelineSomenteLeitura > .ps-compositor-nota');
        self::assertCount(1, $nota);
        self::assertStringContainsString('só para leitura', $nota->text());
    }

    #[TestDox('PAS-025c: na lápide sem registro o vazio não manda "registrar acima"')]
    public function testLapideVaziaSemConviteParaRegistrar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $vazio = $crawler->filter('#timelineList > #timelineVazio');
        self::assertCount(1, $vazio);
        self::assertCount(0, $vazio->filter('.ps-vazio-nota'));
        self::assertCount(0, $crawler->filter('#formTimelineMensagem'));
    }

    #[TestDox('PAS-025c: na pasta viva o compositor do Registro está lá e a nota de só leitura não')]
    public function testPastaVivaComCompositorNoRegistro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('section.ps-anotacoes > .ps-compositor > form#formTimelineMensagem #timelineConteudo'));
        self::assertCount(0, $crawler->filter('#timelineSomenteLeitura, .ps-compositor--leitura'));
        self::assertCount(1, $crawler->filter('#timelineVazio > .ps-vazio-nota'));
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
        // C6-PAS-01: o fim da janela sai do epoch que o servidor manda; `criadaEmTs` é ATOM e
        // `Number()` sobre ele dava NaN (menu "bloqueado" e lixeira inacessível até recarregar).
        self::assertStringContainsString('data-janela-ate="${Number(data.criadaEmEpoch) + 15 * 60}"', $montador);
        self::assertStringNotContainsString('Number(data.criadaEmTs)', $html);
        self::assertStringContainsString('data-do-autor="1"', $montador);
        // C6-PAS-07: com foto, o avatar do cartão novo é a <img> (igual ao Twig); sem, as iniciais.
        self::assertStringContainsString('<span class="ps-avatar ps-avatar--24">${data.autorFotoUrl ? `<img src="${esc(data.autorFotoUrl)}" alt="">` : esc(iniciaisDe(data.autorNome))}</span>', $montador);
        $data = strpos($montador, '<span class="ps-anotacao-data ps-num"');
        $btn  = strpos($montador, 'class="ps-reg-menu-btn js-reg-menu"');
        self::assertNotFalse($btn, 'o cartão do JS não tem o ⋮');
        self::assertLessThan($btn, (int) $data, 'a hora vem antes do ⋮, como no Twig');
        self::assertStringContainsString('aria-controls="psRegMenu"', $montador);
    }

    /** @return array<string, mixed> o JSON do `pasta_enviar_mensagem` (201) */
    private function enviar(object $client, Pasta $pasta): array
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        $token   = $crawler->filter('#formTimelineMensagem input[name="_token"]')->attr('value');

        $client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem', [
            '_token'   => $token,
            'conteudo' => '<p>Registro novo</p>',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(201);

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    #[TestDox('C6-PAS-01: o envio do Registro devolve `criadaEmEpoch` inteiro, o mesmo instante do ATOM, para o JS calcular a janela')]
    public function testEnvioDevolveOEpochDaCriacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $antes = time();
        $data  = $this->enviar($client, $pasta);

        self::assertIsInt($data['criadaEmEpoch']);
        self::assertSame((new \DateTimeImmutable($data['criadaEmTs']))->getTimestamp(), $data['criadaEmEpoch']);
        self::assertGreaterThanOrEqual($antes - 5, $data['criadaEmEpoch']);
        self::assertLessThanOrEqual(time() + 5, $data['criadaEmEpoch']);

        $msg = $this->em()->find(PastaMensagem::class, $data['id']);
        self::assertNotNull($msg);
        self::assertSame($msg->getCriadaEm()->getTimestamp(), $data['criadaEmEpoch'], 'a mesma base do `|date(\'U\')` do Twig');
    }

    #[TestDox('C6-PAS-07: o envio devolve a URL da foto do autor quando ele tem foto (rota da foto, como o Twig) e null quando não tem')]
    public function testEnvioDevolveAFotoDoAutor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        self::assertNull($this->enviar($client, $pasta)['autorFotoUrl'], 'sem foto: o JS mostra as iniciais');

        // A requisição limpa o EntityManager: relê o autor antes de pendurar o perfil nele
        $autor   = $this->em()->find(\App\Entity\Auth\User::class, $user->getId());
        $profile = new \App\Profile\Entity\UserProfile($autor);
        $profile->setFotoUrl('foto-autor.jpg');
        $this->em()->persist($profile);
        $this->em()->flush();

        $url = $this->enviar($client, $pasta)['autorFotoUrl'];
        self::assertSame('/perfil/foto/foto-autor.jpg', $url);
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

        $itens = $this->trecho($html, "menuReg.addEventListener('click'", 1000);
        self::assertStringContainsString('item.disabled', $itens);
        self::assertStringContainsString("'.btn-responder-msg-pasta'", $itens);
        self::assertStringContainsString("'.ps-anotacao-acoes .btn-editar-msg-pasta'", $itens);
        self::assertStringContainsString("'.ps-anotacao-acoes .btn-excluir-msg-pasta'", $itens);
        self::assertStringContainsString('if (alvo) { alvo.click(); }', $itens);
        self::assertStringContainsString('copiarTextoReg(item, cartao)', $itens);

        $copiar = $this->trecho($html, 'function copiarTextoReg(item, cartao)', 1200);
        self::assertStringContainsString(".pasta-msg-conteudo .ql-editor", $copiar);
        self::assertStringContainsString('navigator.clipboard.writeText(texto)', $copiar);
        self::assertStringContainsString("avisar('Texto copiado')", $copiar);
    }
}
