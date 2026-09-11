<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A tela do ponto passou a bloquear o botão para quem está fora do raio, por decisão do dono em
 * 11/09/2026. Antes ela só travava por falta de posição, e quem estava fora apertava e levava erro.
 *
 * 🔑 **O invariante que estes testes protegem é o sentido do desvio.** A regra continua sendo a do
 * servidor (`PontoController::batida`). A tela é cortesia, calcula com uma posição possivelmente
 * antiga e menos precisa, e por isso tem de ser **MAIS PERMISSIVA**: só bloqueia quem está fora
 * mesmo descontando a margem de precisão do GPS.
 *
 * Divergir para o lado permissivo é inofensivo — o servidor recusa e a pessoa vê a mensagem.
 * Divergir para o lado rígido transforma imprecisão de GPS em **falta**, que num módulo de risco
 * ALTO é o dano que não se pode aceitar. Medido em produção: a precisão do GPS passa de 100 m com
 * frequência, e o raio é de 100 m.
 *
 * Spec: `docs/specs/ponto-batida-nao-se-perde-no-navegador.md`.
 *
 * Confere o ARRANJO do JS no fonte, que é o que a suíte alcança: PHPUnit não executa JavaScript.
 * O escopo do dado que chega à tela é provado por `App\Tests\Ponto\Functional\CercaNaTelaDoPontoTest`.
 */
final class CercaDaTelaNaoEndureceARegraTest extends TestCase
{
    private const CAMINHO_TELA = __DIR__ . '/../../../templates/ponto/index.html.twig';

    #[TestDox('a tela só bloqueia quem está fora mesmo descontando a margem de precisão do GPS')]
    public function testBloqueioDescontaAMargemDePrecisao(): void
    {
        $corpo = $this->corpoDaFuncao('function avaliarPosicao(');

        self::assertMatchesRegularExpression(
            '/\(\s*menorDistancia\s*-\s*margem\s*\)\s*>\s*maisProxima\.raio/',
            $corpo,
            'sem descontar a margem, a tela vira MAIS rígida que o servidor e transforma GPS '
            . 'impreciso em falta'
        );
        self::assertStringContainsString(
            'currentLocation.precisaoGps',
            $corpo,
            'a margem tem que ser a precisão real da leitura, não um número inventado'
        );
    }

    #[TestDox('existe a situação indeterminada: precisão maior que a distância não é estar fora')]
    public function testExisteSituacaoIndeterminada(): void
    {
        $corpo = $this->corpoDaFuncao('function avaliarPosicao(');

        self::assertStringContainsString(
            "'indeterminado'",
            $corpo,
            'com 120 m de erro e 70 m de distância o aparelho não sabe dizer se está dentro: '
            . 'tratar isso como "fora" é decidir contra a pessoa sem informação'
        );
        self::assertMatchesRegularExpression(
            '/SEDES\.length === 0/',
            $corpo,
            'sem sede cadastrada a tela não tem como avaliar e não pode bloquear por conta própria'
        );
    }

    #[TestDox('o botão só é bloqueado pela cerca quando a avaliação diz fora, nunca em indeterminado')]
    public function testBotaoSoBloqueiaNoForaCerteza(): void
    {
        $corpo = $this->corpoDaFuncao('function updateButtonState(');

        self::assertStringContainsString(
            "avaliarPosicao().situacao === 'fora'",
            $corpo,
            'o bloqueio da tela tem que depender da avaliação com margem, não da distância crua'
        );
        self::assertStringNotContainsString(
            "'indeterminado'",
            $corpo,
            'indeterminado não pode bloquear: quem decide nesse caso é o servidor'
        );
    }

    #[TestDox('home office continua dispensando a cerca na tela, como no servidor')]
    public function testHomeOfficeNaoPassaPelaCerca(): void
    {
        $corpo = $this->corpoDaFuncao('function updateButtonState(');
        $antesDaCerca = substr($corpo, 0, (int) strpos($corpo, 'avaliarPosicao()'));

        self::assertStringContainsString(
            'homeOfficeHoje',
            $antesDaCerca,
            'o ramo de home office tem que sair ANTES da cerca, senão quem está liberado do dia '
            . 'passa a ser bloqueado por estar em casa — o servidor dispensa o geofencing nesse caso'
        );
    }

    /** Corpo de uma função do script, delimitado por contagem de chaves. */
    private function corpoDaFuncao(string $assinatura): string
    {
        $fonte  = (string) file_get_contents(self::CAMINHO_TELA);
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, sprintf('não achei `%s` na tela do ponto', $assinatura));

        $abre = strpos($fonte, '{', $inicio);
        self::assertIsInt($abre, 'não achei a abertura do corpo da função');

        $profundidade = 0;
        for ($i = $abre; $i < strlen($fonte); $i++) {
            if ($fonte[$i] === '{') {
                $profundidade++;
            } elseif ($fonte[$i] === '}') {
                $profundidade--;
                if ($profundidade === 0) {
                    return substr($fonte, $inicio, $i - $inicio + 1);
                }
            }
        }

        self::fail('o corpo da função não fecha — chaves desbalanceadas na tela');
    }
}
