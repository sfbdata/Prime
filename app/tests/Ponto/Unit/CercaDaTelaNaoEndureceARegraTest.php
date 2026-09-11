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
 * ALTO é o dano que não se pode aceitar. Medido em produção em 11/09/2026, sobre 1.683 batidas
 * desde 01/06: mediana de 27,1 m, **p90 de 99,0 m** e 7,5% acima de 100 m — contra raio de 100 m.
 * Uma em cada dez leituras carrega incerteza do tamanho do raio inteiro. ⚠️ A amostra é de batidas
 * ACEITAS, então é otimista: as recusadas por posição não estão nela.
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
            '/\(\s*menorExcedente\s*-\s*margem\s*\)\s*>\s*0/',
            $corpo,
            'sem descontar a margem, a tela vira MAIS rígida que o servidor e transforma GPS '
            . 'impreciso em falta'
        );

        // 🪤 Pinar a EXPRESSÃO INTEIRA, e não só a presença de `precisaoGps`. Um
        // `Math.min(Number(currentLocation.precisaoGps) || 0, 30)` endurece a tela e passava nos
        // dois asserts anteriores: a forma da comparação continuava a mesma e a string também.
        self::assertStringContainsString(
            'const margem = Math.max(Number(currentLocation.precisaoGps) || 0, 0);',
            $corpo,
            'a margem é a precisão real, sem teto e sem piso negativo: teto endurece a tela, '
            . 'precisão negativa somaria à distância e endureceria também'
        );

        // Medido na produção em 11/09/2026, sobre 1.683 batidas desde 01/06: p90 de 99 m, com raio
        // de 100 m. Uma em cada dez leituras tem incerteza do tamanho do raio inteiro — é esse
        // número que torna a margem obrigatória, e não uma cortesia.
        self::assertStringContainsString(
            'menorExcedente = Math.min(menorExcedente, distancia - sede.raio)',
            $corpo,
            'com raios diferentes, olhar só a sede mais próxima bloquearia quem o servidor aceita'
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

    #[TestDox('a posição continua sendo atualizada com a página aberta, não só na carga')]
    public function testPosicaoNaoCongelaNaCarga(): void
    {
        $fonte = (string) file_get_contents(self::CAMINHO_TELA);

        // 🔴 Desde que o botão bloqueia por raio, decidir com a leitura da carga vira armadilha:
        // quem abre a tela no caminho, longe do escritório, ficaria travado mesmo depois de chegar.
        self::assertStringContainsString(
            'navigator.geolocation.watchPosition(',
            $fonte,
            'sem atualização contínua, o bloqueio usa uma posição velha e não há como destravar'
        );
        self::assertMatchesRegularExpression(
            '/watchPosition\(\s*aplicarPosicao/',
            $fonte,
            'a atualização contínua tem que reavaliar a posição e o botão, não só guardar o dado'
        );
    }

    #[TestDox('quem está fora da área recebe uma saída na tela, não só um botão cinza')]
    public function testBloqueadoTemCaminhoDeSaida(): void
    {
        $corpo = $this->corpoDaFuncao('function guardarPosicao(');

        self::assertStringContainsString(
            "btnAtualizarPagina.classList.remove('d-none')",
            $corpo,
            'a liberação do dia é decidida no servidor e só chega na carga: sem o botão de '
            . 'atualizar, quem consegue a liberação com a página aberta continua bloqueado'
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
