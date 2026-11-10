<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Atalho do ponto no avatar com a tela do Ponto desligada pelo Master (spec docs/specs/ponto-desenho-0811.md, E7):
 * o estado do dia continua (as rotas globais ficam abertas), mas o botão de registrar some. PHPUnit não roda o JS;
 * a prova é a fonte, como nos outros testes do atalho.
 */
#[CoversNothing]
#[Group('ponto')]
final class AtalhoDoPontoComATelaDesligadaTest extends TestCase
{
    private const PARCIAL = __DIR__ . '/../../../templates/menu_usuario/_presenca.html.twig';
    private const JS = __DIR__ . '/../../../public/js/presenca-ponto.js';

    #[TestDox('O parcial só entrega a rota da batida com a tela visível para quem vê; o estado do dia segue sempre')]
    public function testParcialSemABatida(): void
    {
        $parcial = (string) file_get_contents(self::PARCIAL);

        self::assertStringContainsString(
            '{% if atalhoPonto %}data-ponto-estado="{{ path(\'ponto_estado_do_dia\') }}"{% if recurso_visivel(\'ponto\') %} data-ponto-batida="{{ path(\'ponto_batida\') }}"{% endif %}',
            $parcial,
        );
    }

    #[TestDox('Sem a rota da batida, o JS não oferece batida: "Bater ponto" some, "Em jornada" fica sem ação, o "Regularizar" some')]
    public function testJsSemBatida(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('var podeBater = !!urlBatida;', $js);
        self::assertMatchesRegularExpression("/case 'bater_entrada':\\s*if \\(!podeBater\\) \\{ return null; \\}/", $js);
        self::assertMatchesRegularExpression(
            "/case 'em_jornada':\\s*if \\(!podeBater\\) \\{ return \\{ texto: 'Em jornada desde ' \\+ h\\.entrada, cor: '#1e7b45', icone: 'bi-play-circle', acao: '', dica: '' \\}; \\}/",
            $js,
        );
        self::assertStringContainsString("if (!podeBater || !estado || estado.aviso !== 'regularizar_ontem'", $js);
        self::assertStringContainsString("if (envioEmAndamento || !atual || !atual.acao) { return; }", $js, 'sem ação, o clique não envia nada');
    }
}
