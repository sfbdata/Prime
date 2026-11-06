<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O relógio "Tempo trabalhado" da tela /ponto faz a mesma conta da folha
 * (`CalculadoraJornada::calcularMinutosDaEscolha`), pedido de 05/11/2026 depois da revisão do #666.
 *
 * Antes, com o retorno batido sem o repouso (entrada 08:00, retorno 13:00, saída 17:00), o relógio
 * contava 08:00 a 17:00 e somava de novo 13:00 a 17:00: 13 h na tela contra 9 h na folha. E com o
 * repouso sem retorno, mas com saída, parava no repouso (4 h) enquanto a folha contava o span (9 h).
 *
 * A regra da folha: intervalo válido (retorno depois do repouso) conta os dois trechos; sem ele, o span
 * da entrada até a saída. Ao vivo (sem saída), o fim é "agora", e em repouso sem retorno o relógio para
 * no repouso. Uma conta só serve o relógio e o contador de horas excedentes.
 *
 * O container não tem node: a conta em si foi conferida com node fora dele (casos no docblock do PR).
 * Aqui se trava que só existe uma conta e que ela tem a regra da folha.
 */
#[Group('ponto')]
final class RelogioContaComoAFolhaTest extends KernelTestCase
{
    private function corpoDaConta(): string
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $tela = (string) file_get_contents($dir . '/templates/ponto/index.html.twig');

        self::assertSame(1, substr_count($tela, 'function calcSegundosTrabalhados('), 'uma conta só');
        self::assertStringNotContainsString('segundosComoNaFolha', $tela, 'o contador usa a mesma conta do relógio');
        self::assertSame(5, substr_count($tela, 'calcSegundosTrabalhados(agora)'), 'relógio (3 estados) e excedente usam a mesma conta');
        self::assertSame(1, preg_match('/function calcSegundosTrabalhados\(agora\) \{(.*?)\n        \}\n/s', $tela, $m));

        return $m[1];
    }

    #[TestDox('Retorno sem repouso: o relógio conta o span da entrada à saída, sem somar o trecho do retorno de novo')]
    public function testRetornoSemRepousoNaoDobra(): void
    {
        $conta = $this->corpoDaConta();

        self::assertStringContainsString('const intervaloValido = tRepouso && tRetorno && tRetorno > tRepouso;', $conta);
        self::assertStringContainsString('if (intervaloValido) {', $conta, 'só intervalo válido divide em dois trechos');
        self::assertStringNotContainsString('if (tRetorno) {', $conta, 'a soma solta do trecho do retorno era o que dobrava');
        self::assertStringContainsString('total = Math.max(0, fim - tEntrada);', $conta, 'sem intervalo válido, o span');
    }

    #[TestDox('Par invertido com saída (saída antes da entrada, repouso antes da entrada, saída antes do retorno): 0, como a folha')]
    public function testParInvertidoZera(): void
    {
        $conta = $this->corpoDaConta();

        self::assertStringContainsString(
            'if (tSaida && (tSaida < tEntrada || (intervaloValido && (tRepouso < tEntrada || tSaida < tRetorno)))) return 0;',
            $conta,
        );
    }

    #[TestDox('Repouso sem retorno: ao vivo para no repouso; com saída, conta o span como a folha')]
    public function testRepousoSemRetorno(): void
    {
        $conta = $this->corpoDaConta();

        self::assertStringContainsString('} else if (tRepouso && !tRetorno && !tSaida) {', $conta);
        self::assertStringContainsString('const fim = tSaida || agora;', $conta);
    }
}
