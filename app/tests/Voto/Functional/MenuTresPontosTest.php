<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Contrato do partial do menu de três pontos (desenho 18): item com `href` vira link, item com `acao` vira
 * <form method="post"> com o token e os campos extras, `destrutivo` pinta de vermelho (.vKd) e `confirmar` leva o
 * texto da janela de confirmação. Lista vazia não desenha nada.
 */
final class MenuTresPontosTest extends KernelTestCase
{
    private function desenhar(array $itens): string
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('voto/assembleia/_menu_tres_pontos.html.twig', ['itens' => $itens]);
    }

    #[TestDox('Link e formulário: o link leva o href, o formulário leva POST, token e campos, e o destrutivo fica vermelho')]
    public function testLinkEFormulario(): void
    {
        $html = $this->desenhar([
            ['rotulo' => 'Link e QR Code', 'icone' => 'bi-qr-code', 'href' => '/voto/x'],
            ['rotulo' => 'Apagar simulação', 'icone' => 'bi-trash', 'acao' => '/voto/apagar', 'token' => 'tok123', 'campos' => ['pauta' => '7'], 'destrutivo' => true, 'confirmar' => 'Apagar esta simulação?'],
        ]);
        $menu = (new Crawler($html))->filter('.vKw');

        self::assertCount(1, $menu->filter('.vK[aria-label="Mais opções"] > i.bi-three-dots-vertical'));
        $link = $menu->filter('.vKw > .vKm > a');
        self::assertSame('/voto/x', $link->attr('href'));
        self::assertSame('Link e QR Code', trim($link->text()));
        self::assertSame('', (string) $link->attr('class'));

        $form = $menu->filter('.vKw > .vKm > form[method="post"]');
        self::assertSame('/voto/apagar', $form->attr('action'));
        self::assertSame('Apagar esta simulação?', $form->attr('data-voto-confirmar'));
        self::assertSame('tok123', $form->filter('input[name="_token"]')->attr('value'));
        self::assertSame('7', $form->filter('input[name="pauta"]')->attr('value'));
        self::assertSame('Apagar simulação', trim($form->filter('button.vKd')->text()));
        self::assertCount(1, $menu->filter('.vKm[hidden]'), 'o menu nasce fechado');
    }

    #[TestDox('Item só com atributos vira botão type=button (para o menu dentro de um <form>), sem <form> aninhado')]
    public function testItemDeBotaoComAtributos(): void
    {
        $html = $this->desenhar([
            ['rotulo' => 'Subir', 'icone' => 'bi-arrow-up', 'atributos' => ['data-voto-opcao-mover' => '-1', 'hidden' => 'hidden']],
            ['rotulo' => 'Remover', 'icone' => 'bi-trash', 'destrutivo' => true, 'atributos' => ['data-voto-envia' => '/voto/x?a=1&b=2']],
        ]);
        $menu = (new Crawler($html))->filter('.vKw > .vKm');

        self::assertCount(0, $menu->filter('form, a'));
        $subir = $menu->filter('button[type="button"][role="menuitem"]')->eq(0);
        self::assertSame('-1', $subir->attr('data-voto-opcao-mover'));
        self::assertNotNull($subir->attr('hidden'));
        self::assertSame('Subir', trim($subir->text()));
        $remover = $menu->filter('button.vKd');
        self::assertSame('/voto/x?a=1&b=2', $remover->attr('data-voto-envia'), 'o valor é escapado no HTML e volta igual');
    }

    #[TestDox('Extensão do ciclo: item com `janela` abre uma janela e com `revelar` mostra uma faixa, ambos botões fora de formulário')]
    public function testJanelaERevelar(): void
    {
        $html = $this->desenhar([
            ['rotulo' => 'Suspender votação', 'icone' => 'bi-pause-circle', 'janela' => '#janela-ciclo-5-sus'],
            ['rotulo' => 'Excluir rascunho', 'icone' => 'bi-trash', 'revelar' => '#excluir-9', 'destrutivo' => true],
        ]);
        $menu = (new Crawler($html))->filter('.vKm');

        $janela = $menu->filter('button[type="button"][data-voto-janela="#janela-ciclo-5-sus"]');
        self::assertSame('Suspender votação', trim($janela->text()));
        self::assertCount(1, $janela->filter('i.bi-pause-circle'));
        self::assertCount(1, $menu->filter('button.vKd[type="button"][data-voto-revelar="#excluir-9"]'));
        self::assertCount(0, $menu->filter('form'));
    }

    #[TestDox('Lista vazia não desenha o menu')]
    public function testListaVazia(): void
    {
        self::assertSame('', trim($this->desenhar([])));
    }
}
