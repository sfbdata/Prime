<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PAS-041: os itens do ⋮ da observação de Detalhes que o desenho tem e o sistema sabe fazer
 * (dc L.5958 `regsLinha` + L.6465 `extrasObs`): Copiar texto · Citar no novo registro · Criar
 * meta a partir deste registro · — · Editar · Excluir comentário, com os minutos da janela ou
 * "· bloqueado". Responder (migration) e Imprimir (IMP-A1) ficam de fora.
 *
 * Arranjo por FILHO DIRETO do menu: o item está no ⋮ desta observação, não em outro lugar.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaDetalhesObsMenuTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const MOTIVO_PRAZO = 'O prazo de 15 minutos para editar ou excluir terminou.';
    private const MOTIVO_AUTOR = 'Só o autor pode editar ou excluir, nos 15 minutos após publicar.';

    private function criarObs(Pasta $pasta, Tenant $tenant, User $autor, string $quando = 'now'): PastaObservacaoDetalhes
    {
        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setTenant($tenant);
        $obs->setAutor($autor);
        $obs->setConteudo('Relato do cliente sobre o acidente.');
        (new \ReflectionProperty(PastaObservacaoDetalhes::class, 'criadaEm'))->setValue($obs, new \DateTimeImmutable($quando));
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    /** @return array{Crawler, list<string>} o menu e o texto dos itens, na ordem */
    private function menu(Crawler $crawler, PastaObservacaoDetalhes $obs): array
    {
        $id   = $obs->getId();
        $menu = $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > #psObsMenu{$id}[role=\"menu\"]");
        self::assertCount(1, $menu);

        $itens = $menu->filter("#psObsMenu{$id} > .ps-processo-menu-item")->each(
            static fn (Crawler $i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''),
        );

        return [$menu, $itens];
    }

    #[TestDox('PAS-041: autor dentro da janela — Copiar, Citar, Criar meta, separador, "Editar · 10 min" e "Excluir comentário · 10 min" ligados ao cartão')]
    public function testAutorDentroDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame([
            'Responder',
            'Copiar texto',
            'Citar no novo registro',
            'Criar meta a partir deste registro',
            'Enviar no Chat I.A',
            'Imprimir',
            'Editar · 10 min',
            'Excluir comentário · 10 min',
        ], $itens);

        $id = $obs->getId();
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-citar + .js-obs-criar-meta[role=\"menuitem\"] > i.bi-check2-square"));
        // C6-EDT-04 (dc L.5958): os extras (Citar, Criar meta, Enviar no Chat I.A, Imprimir) vêm antes do separador.
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-criar-meta + .js-obs-enviar-chat + .js-obs-imprimir + .ps-processo-menu-sep[role=\"separator\"] + .js-obs-editar"));
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-editar[data-obs-id=\"{$id}\"] > i.bi-pencil"));
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-excluir[data-obs-id=\"{$id}\"] > i.bi-trash3"));
        self::assertCount(0, $menu->filter('[disabled], .bi-lock'));
        // A ação real é o botão da janela do cartão, que o item aciona.
        self::assertCount(1, $crawler->filter("#detalhes-obs-{$id} .ps-anotacao-acoes > .btn-editar-obs-det"));
        self::assertCount(1, $crawler->filter("#detalhes-obs-{$id} .ps-anotacao-acoes > .btn-excluir-obs-det[hidden]"), 'C6-PAS-03: escondida, o ⋮ a clica');
        // PAS-039 (dc L.5958): Responder é o primeiro item e clica o botão do cartão.
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .ps-processo-menu-titulo + .js-obs-responder[role=\"menuitem\"] > i.bi-reply-fill"));
        self::assertCount(1, $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > .btn-responder-obs-det[data-resposta-a=\"{$id}\"]"));
    }

    #[TestDox('PAS-041/C7-FPN-02: autor passados 20 min — "Editar · bloqueado" e "Excluir · bloqueado", aria-disabled e clicáveis, com bi-lock e o motivo do prazo no aviso')]
    public function testAutorForaDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-20 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame(['Responder', 'Copiar texto', 'Citar no novo registro', 'Criar meta a partir deste registro', 'Enviar no Chat I.A', 'Imprimir', 'Editar · bloqueado', 'Excluir · bloqueado'], $itens);
        $bloqueados = $menu->filter('.ps-obs-menu > .ps-processo-menu-item[aria-disabled="true"]');
        self::assertCount(2, $bloqueados);
        self::assertCount(2, $bloqueados->filter('i.bi-lock'));
        // C7-FPN-02 (dc 5958): clicável — o `disabled` engolia o clique; o motivo vai no aviso.
        self::assertCount(0, $menu->filter('.ps-obs-menu > .ps-processo-menu-item[disabled]'));
        foreach ($bloqueados as $b) {
            self::assertSame(self::MOTIVO_PRAZO, $b->getAttribute('title'));
            self::assertSame(self::MOTIVO_PRAZO, $b->getAttribute('data-aviso'));
        }
        self::assertCount(0, $menu->filter('.js-obs-editar, .js-obs-excluir'), 'item bloqueado não aciona nada');
    }

    #[TestDox('PAS-041: observação de OUTRO autor — bloqueados com o motivo do autor')]
    public function testOutroAutor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $outro, '-2 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame(['Imprimir', 'Editar · bloqueado', 'Excluir · bloqueado'], array_slice($itens, -3));
        $bloqueados = $menu->filter('.ps-obs-menu > .ps-processo-menu-item[aria-disabled="true"]');
        self::assertCount(2, $bloqueados);
        foreach ($bloqueados as $b) {
            self::assertFalse($b->hasAttribute('disabled'), 'C7-FPN-02: bloqueado clicável');
            self::assertSame(self::MOTIVO_AUTOR, $b->getAttribute('title'));
            self::assertSame(self::MOTIVO_AUTOR, $b->getAttribute('data-aviso'));
        }
    }

    #[TestDox('PAS-041: quem só vê a pasta não recebe "Criar meta a partir deste registro"')]
    public function testLeitorSemCriarMeta(): void
    {
        $client          = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $leitor          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $admin);

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertNotContains('Criar meta a partir deste registro', $itens);
        self::assertCount(0, $menu->filter('.js-obs-criar-meta'));
        $html     = (string) $client->getResponse()->getContent();
        $ini      = (int) strpos($html, 'function inserirItem(data)');
        $montador = substr($html, $ini, (int) strpos($html, 'obsLista.insertAdjacentHTML', $ini) - $ini);
        self::assertStringNotContainsString('js-obs-criar-meta', $montador, 'nem no cartão montado em JS');
    }

    #[TestDox('PAS-041: na lápide o ⋮ é só leitura — Copiar e Imprimir; sem Citar (C6-PAS-02: não há compositor), Criar meta, Editar/Excluir')]
    public function testLapide(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame(['Copiar texto', 'Enviar no Chat I.A', 'Imprimir'], $itens, 'na lápide só leitura: Enviar no Chat I.A (cópia) e Imprimir ficam');
        self::assertCount(0, $menu->filter('.ps-processo-menu-sep'));
        // PAS-039: nem o item Responder nem o botão do cartão.
        self::assertCount(0, $crawler->filter('#detalhesObsLista .btn-responder-obs-det, #detalhesObsLista .js-obs-responder'));
    }

    #[TestDox('PAS-041: o cartão montado em JS traz os mesmos itens novos, na ordem, e o script os liga ao modal Nova meta e aos botões do cartão')]
    public function testCartaoDoJsEScript(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        $html = (string) $client->getResponse()->getContent();

        $ini      = strpos($html, 'function inserirItem(data)');
        self::assertNotFalse($ini);
        $montador = substr($html, $ini, (int) strpos($html, 'obsLista.insertAdjacentHTML', $ini) - $ini);
        $ordem    = ['js-obs-copiar', 'js-obs-citar', 'js-obs-criar-meta', 'js-obs-enviar-chat', 'js-obs-imprimir', 'ps-processo-menu-sep', 'js-obs-editar', 'js-obs-excluir'];
        $pos      = array_map(static fn (string $c): int|false => strpos($montador, $c), $ordem);
        self::assertNotContains(false, $pos, 'falta item no cartão do JS');
        $ordenado = $pos;
        sort($ordenado);
        self::assertSame($ordenado, $pos, 'a ordem é a do Twig');
        self::assertStringContainsString('<i class="bi bi-check2-square"></i><span>Criar meta a partir deste registro</span>', $montador);
        self::assertMatchesRegularExpression('/<span>Editar · \d+ min<\/span>/', $montador);
        self::assertMatchesRegularExpression('/<span>Excluir comentário · \d+ min<\/span>/', $montador);

        $fn = substr($html, (int) strpos($html, 'function criarMetaObs(item)'), 1400);
        self::assertStringContainsString("getElementById('modalCriarTarefa')", $fn);
        self::assertStringContainsString('descricao.value = texto.slice(0, 600)', $fn);
        self::assertStringContainsString("titulo.value = ''", $fn, 'o desenho abre o título em branco');
        self::assertStringContainsString('p.setDate(p.getDate() + 7)', $fn);
        self::assertStringContainsString("modal.dataset.origemPush = '1'", $fn, 'o pasta-push.js limpa ao fechar');
        self::assertStringContainsString('bootstrap.Modal.getOrCreateInstance(modal).show()', $fn);

        self::assertStringContainsString("if (itemEditar) { acionarDoCartao(itemEditar, '.btn-editar-obs-det'); return; }", $html);
        self::assertStringContainsString("if (itemExcluir) { acionarDoCartao(itemExcluir, '.btn-excluir-obs-det'); return; }", $html);
        $acionar = substr($html, (int) strpos($html, 'function acionarDoCartao(item, seletor)'), 500);
        self::assertStringContainsString("querySelector('.ps-anotacao-acoes ' + seletor)", $acionar);
        self::assertStringContainsString('alvo.click()', $acionar);
    }

    #[TestDox('dc L.6872: "Enviar no Chat I.A" no ⋮ da observação, entre "Criar meta…" e "Imprimir", com o NUP da pasta; o script abre a Central (bj-central:abrir) e copia "Pasta N · cabeçalho" + 900 caracteres')]
    public function testEnviarNoChat(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        [$menu] = $this->menu($crawler, $obs);
        $id     = $obs->getId();
        $item   = $menu->filter("#psObsMenu{$id} > .js-obs-criar-meta + .js-obs-enviar-chat[role=\"menuitem\"]");
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-enviar-chat + .js-obs-imprimir"));
        self::assertSame('Enviar no Chat I.A', trim($item->text()));
        self::assertSame((string) $pasta->getNup(), $item->attr('data-pasta-nup'));
        self::assertCount(1, $item->filter('i.bi-chat-dots'));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("if (itemChat) { enviarObsNoChat(itemChat); return; }", $html);
        $fn = substr($html, (int) strpos($html, 'function enviarObsNoChat(item)'), 1500);
        self::assertStringContainsString("new CustomEvent('bj-central:abrir')", $fn);
        self::assertStringContainsString(".slice(0, 900)", $fn);
        self::assertStringContainsString("'Pasta ' + (item.dataset.pastaNup || '') + ' · ' + cab", $fn);
        self::assertStringContainsString('Chat I.A aberto. Texto copiado para colar na conversa', $fn);

        $flutuante = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/central-flutuante.js');
        self::assertStringContainsString("document.addEventListener('bj-central:abrir', function () { if (!ui.aberto) { abrir(); } });", $flutuante);
    }

    #[TestDox('PAS-039: a resposta fica recuada sob a raiz, com "Resposta a NOME"; a contagem é só das raízes')]
    public function testRespostaRecuadaSobARaiz(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->criarObs($pasta, $tenant, $user, '-2 hours');
        $resposta        = $this->criarObs($pasta, $tenant, $user, '-1 hour');
        $resposta->setRespostaA($raiz);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#detalhesObsLista > article.detalhes-obs-item#detalhes-obs-' . $raiz->getId()));
        self::assertCount(0, $crawler->filter('#detalhesObsLista > #detalhes-obs-' . $resposta->getId()));
        $cartao = $crawler->filter('#detalhesObsLista > .ps-respostas[data-resposta-de="' . $raiz->getId() . '"] > article.detalhes-obs-item.ps-anotacao--resposta#detalhes-obs-' . $resposta->getId());
        self::assertCount(1, $cartao);
        self::assertSame('Resposta a ' . $user->getFullName(), trim($cartao->filter('.ps-anotacao-resposta-rot > span')->text()));
        self::assertSame('1', trim($crawler->filter('#detalhes-obs-count')->text()), 'respostas não contam');

        // A resposta responde à RAIZ, levando o autor dela para o "@"; a raiz não pré-preenche.
        $naResposta = $cartao->filter('.ps-anotacao-topo > .btn-responder-obs-det');
        self::assertSame((string) $raiz->getId(), $naResposta->attr('data-resposta-a'));
        self::assertSame((string) $user->getId(), $naResposta->attr('data-resposta-autor-id'));
        $naRaiz = $crawler->filter('#detalhes-obs-' . $raiz->getId() . ' > .ps-anotacao-topo > .btn-responder-obs-det');
        self::assertSame((string) $raiz->getId(), $naRaiz->attr('data-resposta-a'));
        self::assertNull($naRaiz->attr('data-resposta-autor-id'));
        // O ⋮ da resposta também tem Responder.
        [, $itens] = $this->menu($crawler, $resposta);
        self::assertSame('Responder', $itens[0]);
    }

    #[TestDox('PAS-039: a resposta cuja original foi excluída fica solta, com "Resposta a uma mensagem excluída"')]
    public function testRespostaOrfa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->criarObs($pasta, $tenant, $user, '-2 hours');
        $resposta        = $this->criarObs($pasta, $tenant, $user, '-1 hour');
        $resposta->setRespostaA($raiz);
        $this->em()->flush();
        $this->em()->remove($raiz);
        $this->em()->flush();
        $this->em()->clear();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $cartao = $crawler->filter('#detalhesObsLista > article.ps-anotacao--resposta#detalhes-obs-' . $resposta->getId());
        self::assertCount(1, $cartao);
        self::assertSame('Resposta a uma mensagem excluída', trim($cartao->filter('.ps-anotacao-resposta-rot > span')->text()));
        self::assertSame((string) $resposta->getId(), $cartao->filter('.btn-responder-obs-det')->attr('data-resposta-a'));
    }

    #[TestDox('PAS-039: o cartão montado em JS traz o Responder no ⋮ e o botão do cartão')]
    public function testCartaoDoJsTemResponder(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        $html     = (string) $client->getResponse()->getContent();
        $ini      = (int) strpos($html, 'function inserirItem(data)');
        $montador = substr($html, $ini, (int) strpos($html, 'obsLista.insertAdjacentHTML', $ini) - $ini);

        self::assertStringContainsString('class="ps-processo-menu-item js-obs-responder" role="menuitem"', $montador);
        self::assertLessThan(strpos($montador, 'js-obs-copiar'), strpos($montador, 'js-obs-responder'), 'Responder antes de Copiar texto, como no Twig');
        self::assertStringContainsString('btn-responder-obs-det', $montador);
        // A caixa de resposta DESTE script (o Registro tem a dele) manda o id da raiz.
        $caixa = substr($html, (int) strpos($html, 'function abrirCaixaRespostaDet(botao) {'), 6000);
        self::assertStringContainsString("fd.append('resposta_a', raizId);", $caixa);
        self::assertStringContainsString('fetch(urlEnviar,', $caixa);
    }

    #[TestDox('EDT-010r: o compositor de Detalhes liga a @menção e a observação mostra a menção em destaque')]
    public function testMencaoNaTela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user);
        $obs->setConteudo('<p>Ver com @[Admin Push](user:' . $user->getId() . ')</p>');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertCount(1, $crawler->filter('#formDetalhesObservacao > .ps-compositor-caixa[data-mencionaveis-url="/pasta/' . $pasta->getId() . '/mencionaveis"] > textarea#detalhesObsConteudo'));
        self::assertCount(1, $crawler->filter('#detalhes-obs-' . $obs->getId() . ' .obs-conteudo-det .ql-editor > p > span.ps-mencao[data-user-id="' . $user->getId() . '"]'));

        $html   = (string) $client->getResponse()->getContent();
        $script = substr($html, (int) strpos($html, 'const urlEnviar    = '), 60000);
        self::assertStringContainsString("fdEnvio.set('conteudo', comMencoes(obsConteudo, conteudo));", $script);
        self::assertStringContainsString("fd.append('conteudo', comMencoes(campo, paragrafosDe(texto)));", $script);
        self::assertStringContainsString("campo._mencoes = [{ id: autorId, nome: primeiro }];", $script);
        self::assertStringContainsString("fd.append('conteudo', comMencoes(textarea, textarea.value));", $script);
        self::assertStringContainsString('PastaMencoes.ligar(textarea, edicao.mencoes);', $script);
    }
}
