<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Cantos de até 4px (padrão PJe, trilha A §1 item 8, e Guia visual) nas telas sem desenho próprio que
 * ainda tinham sobras de 6 a 16px: canto de cartão, botão, campo ou ícone fica em 4px (3px onde era 5px).
 *
 * Ficam de fora, de propósito: pílula (999px, ou raio igual à metade da altura) de contador, etiqueta
 * e seletor que acompanha os filtros em pílula; a barra de rolagem; e os círculos (50%). Cobrança
 * (`cobrancas.css`, `pasta-arquivos.css`) e a Central (`central-flutuante.css`, guia de 6px no desenho) têm
 * desenho próprio e não entram aqui.
 */
#[CoversNothing]
final class CantosAte4pxTelasLegadasTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../';

    /** Seletores que são pílula ou barra de rolagem de propósito. */
    private const PILULAS = [
        '.kb-column-count',
        '.kb-tag',
        '.js-pasta-ordenar',
        '.marcadores-popover-badge',
        '::-webkit-scrollbar-thumb',
    ];

    /** @return iterable<string, array{string}> */
    public static function arquivos(): iterable
    {
        foreach ([
            'templates/tenant/sedes.html.twig',
            'templates/tenant/criar.html.twig',
            'templates/kanban/board.html.twig',
            'templates/kanban/index.html.twig',
            'templates/notificacao/index.html.twig',
            'templates/pasta/_tabela.html.twig',
            'templates/pasta/demandas.html.twig',
            'templates/pasta/zip_recusado.html.twig',
            'templates/termo/aceite.html.twig',
            'templates/admin/platform/_base_platform.html.twig',
            'templates/legal/politica_privacidade.html.twig',
        ] as $arquivo) {
            yield $arquivo => [$arquivo];
        }
    }

    #[DataProvider('arquivos')]
    #[TestDox('$arquivo: nenhum canto entre 5 e 98px fora das pílulas de propósito')]
    public function testCantosAte4px(string $arquivo): void
    {
        $conteudo = (string) file_get_contents(self::RAIZ . $arquivo);
        $sobras = [];

        // Cada regra: o seletor (ou a linha, no estilo inline) e o valor do border-radius.
        preg_match_all('/([^{};]*)\{([^}]*)\}|style="([^"]*)"/', $conteudo, $blocos, PREG_SET_ORDER);
        foreach ($blocos as $bloco) {
            $seletor = trim($bloco[1] ?? '');
            $corpo = ($bloco[2] ?? '') . ($bloco[3] ?? '');
            if ($this->ehPilula($seletor)) {
                continue;
            }
            preg_match_all('/border-radius\s*:\s*([^;"}]+)/', $corpo, $raios);
            foreach ($raios[1] as $valor) {
                if ($this->temSobra($valor)) {
                    $sobras[] = ($seletor !== '' ? $seletor : 'inline') . ' => ' . trim($valor);
                }
            }
        }

        self::assertSame([], $sobras);
    }

    private function ehPilula(string $seletor): bool
    {
        foreach (self::PILULAS as $pilula) {
            if (str_contains($seletor, $pilula)) {
                return true;
            }
        }

        return false;
    }

    private function temSobra(string $valor): bool
    {
        preg_match_all('/([\d.]+)(px|rem|em)/', $valor, $medidas, PREG_SET_ORDER);
        foreach ($medidas as $medida) {
            $px = (float) $medida[1] * ($medida[2] === 'px' ? 1 : 16);
            if ($px > 4 && $px < 99) {
                return true;
            }
        }

        return false;
    }
}
