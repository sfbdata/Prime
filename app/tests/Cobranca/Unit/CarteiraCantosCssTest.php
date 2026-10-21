<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Cantos da tela da carteira (`carteira-show.css`) na cópia de 19/10 do desenho "Carteira 1B": 66
 * ocorrências de `border-radius:4px` — cartões, botões, campos, menus, modais e selos.
 *
 * Toda declaração de `border-radius` da folha tem de ser feita só de `4px` e `0`. As EXCEÇÕES são as
 * pílulas que o próprio desenho mantém em 999px, e cada uma está listada com a linha do desenho:
 *   · `.cs-pill`      — selos de modo e de honorários (L93-94);
 *   · `.cs-frescor`   — selo "dados atualizados até" (`freshStyle`, L690);
 *   · `.cs-contador`  — contador de documentos do trilho (L257).
 * Não há círculo (50%) nesta folha.
 *
 * O teste trava as duas metades: nenhum raio novo fora do padrão, e as pílulas não viram quadradas.
 * Que o canto aparece certo na tela é do smoke do dono.
 */
final class CarteiraCantosCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/carteira-show.css';

    private const PILULAS_DO_DESENHO = ['.cs-pill', '.cs-frescor', '.cs-contador'];

    /** @return list<array{seletores: list<string>, raio: string}> */
    private function regrasComRaio(): array
    {
        $css = (string) file_get_contents(self::CSS);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        // Achata os @media: as regras de dentro continuam sendo regras da folha.
        $css = (string) preg_replace('/@media[^{]+\{/', '', $css);

        $regras = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocos, \PREG_SET_ORDER);
        foreach ($blocos as [, $seletores, $corpo]) {
            if (preg_match_all('/border(?:-(?:top|bottom)-(?:left|right))?-radius:\s*([^;]+);/', $corpo, $r)) {
                foreach ($r[1] as $raio) {
                    $regras[] = [
                        'seletores' => array_map(static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s)), explode(',', $seletores)),
                        'raio' => trim($raio),
                    ];
                }
            }
        }

        return $regras;
    }

    #[TestDox('Todo border-radius da carteira é 4px (ou 0), fora as pílulas que o desenho mantém em 999px')]
    public function testTodoRaioEhQuatroPixels(): void
    {
        $regras = $this->regrasComRaio();
        self::assertGreaterThan(15, \count($regras), 'A leitura da folha falhou: quase nenhum raio encontrado');

        $fora = [];
        foreach ($regras as $regra) {
            $ehPilula = array_intersect($regra['seletores'], self::PILULAS_DO_DESENHO) !== [];
            if ($ehPilula && $regra['raio'] === '999px') {
                continue;
            }
            foreach (preg_split('/\s+/', $regra['raio']) ?: [] as $valor) {
                if (!\in_array($valor, ['4px', '0'], true)) {
                    $fora[] = implode(', ', $regra['seletores']) . ' → ' . $regra['raio'];
                    break;
                }
            }
        }

        self::assertSame([], $fora, 'Raio fora do padrão de 4px do desenho de 19/10');
    }

    #[TestDox('As pílulas que o desenho mantém redondas continuam em 999px')]
    public function testPilulasDoDesenhoContinuamRedondas(): void
    {
        foreach (self::PILULAS_DO_DESENHO as $pilula) {
            $raios = array_values(array_unique(array_map(
                static fn (array $r): string => $r['raio'],
                array_filter($this->regrasComRaio(), static fn (array $r): bool => \in_array($pilula, $r['seletores'], true)),
            )));
            self::assertSame(['999px'], $raios, "{$pilula} devia continuar pílula, como no desenho");
        }
    }
}
