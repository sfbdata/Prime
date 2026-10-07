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
 * Lote L6 no Registro dos expedientes (desenho 1.2.3, dc L.1300-1325 e `regsItens` L.5966-5978):
 * dica do "editado" (PAS-033), linha sem lixeira nem "· N min" (PAS-037), edição com o rodapé
 * dentro do editor (PAS-032) e a caixa de resposta sob o cartão (PAS-030/031).
 *
 * O que muda na tela roda no navegador; aqui se prova o que o Twig desenha e o script.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaRegistroL6TelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function registrar(Pasta $pasta, User $autor, Tenant $tenant, string $texto, string $quando, ?PastaMensagem $respostaA = null, ?string $editadaEm = null): PastaMensagem
    {
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($texto)
            ->setRespostaA($respostaA);
        if ($editadaEm !== null) {
            $msg->setEditadaEm(new \DateTimeImmutable($editadaEm));
        }

        (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($msg, new \DateTimeImmutable($quando));

        $this->em()->persist($msg);
        $this->em()->flush();

        return $msg;
    }

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function html(object $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    #[TestDox('PAS-033: a dica do "editado" é "Publicado às HH:MM · última edição dd/mm/aaaa às HH:MM", no Twig e no JS')]
    public function testDicaDoEditado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg             = $this->registrar($pasta, $user, $tenant, 'Combinado', '2026-10-01 09:12:00', null, '2026-10-02 14:05:00');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $marca = $crawler->filter('#pasta-msg-' . $msg->getId() . ' > .ps-anotacao-topo > .pasta-msg-editado');
        self::assertSame('editado', trim($marca->text()));
        self::assertSame('Publicado às 09:12 · última edição 02/10/2026 às 14:05', $marca->attr('title'));
        self::assertStringNotContainsString('Editado em', $this->html($client));
        self::assertStringContainsString("marca.title = (publicado ? 'Publicado às ' + publicado + ' · ' : '') + 'última edição '", $this->html($client));
    }

    #[TestDox('PAS-037: na linha do Registro só o lápis, com "Editável por mais N minutos"; a lixeira fica escondida para o menu')]
    public function testLinhaSemLixeiraNemMinutos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg             = $this->registrar($pasta, $user, $tenant, 'Combinado', '-14 minutes -30 seconds');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $acoes = $crawler->filter('#pasta-msg-' . $msg->getId() . ' > .ps-anotacao-topo > .ps-anotacao-acoes');
        self::assertSame('Editável por mais 1 minuto', $acoes->filter('.ps-anotacao-acoes > .btn-editar-msg-pasta')->attr('title'), 'singular no último minuto');
        self::assertNotNull($acoes->filter('.ps-anotacao-acoes > .btn-excluir-msg-pasta[data-url][data-csrf]')->attr('hidden'));
        self::assertCount(0, $crawler->filter('#timelineList .ps-anotacao-janela'), 'sem o "· N min" na linha');
        self::assertCount(1, $crawler->filter('#psRegMenu > .js-reg-excluir'), 'excluir continua no menu');
    }

    #[TestDox('PAS-032: a edição do Registro usa o rodapé dentro do editor (Visível para a equipe · Cancelar · Salvar), como Detalhes')]
    public function testEdicaoComRodapeNoEditor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $this->abrir($client, $pasta);
        $html = $this->html($client);

        $ini = strpos($html, "const btnEditar = e.target.closest('.btn-editar-msg-pasta');");
        self::assertNotFalse($ini);
        $edicao = substr($html, $ini, 6000);
        self::assertStringContainsString("area.className = 'pasta-msg-edit-area ps-anotacao-texto ps-anotacao-edicao';", $edicao);
        self::assertStringContainsString("rodape.className = 'ps-anotacao-edicao-rodape';", $edicao);
        self::assertStringContainsString("nota.textContent = 'Visível para a equipe';", $edicao);
        self::assertStringContainsString("btnCancelar.className = 'ps-anotacao-edicao-cancelar';", $edicao);
        self::assertStringContainsString("btnSalvar.className = 'ps-btn ps-btn--primario';", $edicao);
        self::assertStringContainsString("moldura.className = 'ps-compositor-caixa';", $edicao);
        // Ordem do desenho: nota, espaço, Cancelar, Salvar.
        $ordem = array_map(static fn (string $t): int|false => strpos($edicao, $t), [
            'rodape.appendChild(nota);', 'rodape.appendChild(espaco);', 'rodape.appendChild(btnCancelar);', 'rodape.appendChild(btnSalvar);',
        ]);
        self::assertNotContains(false, $ordem);
        $ordenada = $ordem;
        sort($ordenada);
        self::assertSame($ordenada, $ordem);
        self::assertStringNotContainsString('btn btn-sm btn-success', $edicao, 'sai o botão verde fora do editor');
    }

    #[TestDox('PAS-030: Responder abre a caixa sob o cartão da raiz (faixa, campo, Cancelar e Responder) e envia ao mesmo endpoint com resposta_a')]
    public function testCaixaDeRespostaSobOCartao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Original', '-2 hours');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        self::assertCount(1, $crawler->filter('#pasta-msg-' . $raiz->getId() . ' > .ps-anotacao-topo > .btn-responder-msg-pasta[data-resposta-a="' . $raiz->getId() . '"]'));
        self::assertCount(0, $crawler->filter('#timelineRespondendo, #timelineRespostaA'), 'a faixa do compositor de cima saiu');

        $html = $this->html($client);
        $ini  = strpos($html, 'function abrirCaixaResposta(botao) {');
        self::assertNotFalse($ini);
        $caixa = substr($html, $ini, 6500);
        self::assertStringContainsString("const raiz   = document.getElementById('pasta-msg-' + raizId);", $caixa);
        self::assertStringContainsString('raiz.after(el);', $caixa, 'logo abaixo do cartão da raiz');
        self::assertStringContainsString("para.textContent = 'Respondendo a ' + (botao.dataset.respostaNome || 'Autor desconhecido');", $caixa);
        self::assertStringContainsString("campo.placeholder = 'Escreva a resposta…';", $caixa);
        self::assertStringContainsString("cancelar.textContent = 'Cancelar';", $caixa);
        self::assertStringContainsString("enviar.appendChild(document.createTextNode('Responder'));", $caixa);
        self::assertStringContainsString("enviar.disabled = campo.value.trim() === '';", $caixa, 'Responder apagado sem texto');
        self::assertStringContainsString("e.key === 'Enter' && (e.ctrlKey || e.metaKey)", $caixa);
        self::assertStringContainsString("e.key === 'Escape'", $caixa);
        self::assertStringContainsString("fd.append('resposta_a', raizId);", $caixa);
        self::assertStringContainsString("fd.append('_token', token.value);", $caixa);
        self::assertStringContainsString('await fetch(urlEnviar,', $caixa, 'o mesmo pasta_enviar_mensagem');
        self::assertStringContainsString('const conta = inserirMensagem(data);', $caixa);
        self::assertStringContainsString("abrirCaixaResposta(btnResponder);", $html);

        // O texto vai em parágrafos ESCAPADOS (o servidor sanitiza de novo).
        $par = substr($html, (int) strpos($html, 'function paragrafosDe(texto) {'), 400);
        self::assertStringContainsString(".replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')", $par);
    }

    #[TestDox('PAS-030: a resposta pela caixa grava sob a raiz (mesmo endpoint, mesmo CSRF do compositor)')]
    public function testEnvioDaCaixaGravaComoResposta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Original', '-2 hours');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $token   = $crawler->filter('#formTimelineMensagem input[name="_token"]')->attr('value');

        $client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem', [
            '_token'     => $token,
            'resposta_a' => (string) $raiz->getId(),
            'conteudo'   => '<p>Feito &amp; conferido</p>',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($raiz->getId(), $data['respostaA']);
    }

    #[TestDox('PAS-031: o Responder de uma RESPOSTA leva o autor dela (id e nome) para o "@Nome "; o da raiz não')]
    public function testResponderARespostaLevaOAutor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Original', '-2 hours');
        $resposta        = $this->registrar($pasta, $user, $tenant, 'Resposta', '-1 hour', $raiz);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $daResposta = $crawler->filter('#pasta-msg-' . $resposta->getId() . ' > .ps-anotacao-topo > .btn-responder-msg-pasta');
        self::assertSame((string) $raiz->getId(), $daResposta->attr('data-resposta-a'), 'continua ligando à raiz');
        self::assertSame((string) $user->getId(), $daResposta->attr('data-resposta-autor-id'));
        self::assertSame($user->getFullName(), $daResposta->attr('data-resposta-autor-nome'));
        $daRaiz = $crawler->filter('#pasta-msg-' . $raiz->getId() . ' > .ps-anotacao-topo > .btn-responder-msg-pasta');
        self::assertNull($daRaiz->attr('data-resposta-autor-id'), 'responder à raiz não pré-preenche menção');

        $html  = $this->html($client);
        $caixa = substr($html, (int) strpos($html, 'function abrirCaixaResposta(botao) {'), 9000);
        self::assertStringContainsString("campo.value = '@' + primeiro + ' ';", $caixa);
        self::assertStringContainsString('campo._mencoes = [{ id: autorId, nome: primeiro }];', $caixa);
        self::assertStringContainsString('html = PastaMencoes.paraEnvio(campo, html);', $caixa, 'vira o token @[Nome](user:ID) no envio');
        // O cartão montado no navegador (sempre de quem publicou) leva o mesmo contrato.
        self::assertStringContainsString('data-resposta-autor-id="' . $user->getId() . '" data-resposta-autor-nome="${esc(data.autorNome ?? \'\')}"', $html);
    }

    #[TestDox('PAS-031: o token que a caixa manda vira a menção com o nome do banco')]
    public function testTokenDaCaixaViraMencao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Original', '-2 hours');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $token   = $crawler->filter('#formTimelineMensagem input[name="_token"]')->attr('value');

        $client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem', [
            '_token'     => $token,
            'resposta_a' => (string) $raiz->getId(),
            'conteudo'   => '<p>@[Admin](user:' . $user->getId() . ') feito</p>',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('user:' . $user->getId(), $data['conteudo'], 'gravado como token');
        self::assertStringContainsString($user->getFullName(), $data['conteudoHtml'], 'o rótulo é o nome do banco');
    }
}
