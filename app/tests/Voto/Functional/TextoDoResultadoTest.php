<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Voto\DTO\ResultadoDaOpcao;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\Enum\TipoDaPauta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/** Texto do resultado e rótulo de abstenção/branco por tipo de pauta (regra resTx e BR do desenho 18). Só renderiza Twig (KernelTestCase): sem banco. */
final class TextoDoResultadoTest extends KernelTestCase
{
    /** @return iterable<string, array{ResultadoDaPauta, string}> */
    public static function resultados(): iterable
    {
        yield 'Sim aprova' => [self::decidido(TipoDaPauta::Deliberacao, 'Sim'), 'Aprovada'];
        yield 'Não rejeita' => [self::decidido(TipoDaPauta::Deliberacao, 'Não'), 'Rejeitada'];
        yield 'Sim com espaço e caixa' => [self::decidido(TipoDaPauta::Deliberacao, ' SIM '), 'Aprovada'];
        yield 'outra opção da pauta' => [self::decidido(TipoDaPauta::Deliberacao, 'Adiar'), 'Opção vencedora: 1 Adiar'];
        yield 'eleição mostra o eleito' => [self::decidido(TipoDaPauta::EleicaoDeCargo, 'Ana'), 'Eleito: 1 Ana'];
        yield 'eleição chamada Sim não vira aprovada' => [self::decidido(TipoDaPauta::EleicaoDeCargo, 'Sim'), 'Eleito: 1 Sim'];
        yield 'critério não atingido na pauta' => [self::semDecisao(TipoDaPauta::Deliberacao, ResultadoDaPauta::CRITERIO_NAO_ATINGIDO), 'Rejeitada: não atingiu o critério exigido'];
        yield 'critério não atingido na eleição' => [self::semDecisao(TipoDaPauta::EleicaoDeCargo, ResultadoDaPauta::CRITERIO_NAO_ATINGIDO), 'Critério não atingido'];
        yield 'empate' => [self::semDecisao(TipoDaPauta::Deliberacao, ResultadoDaPauta::EMPATE), 'Empate'];
        yield 'quórum' => [self::semDecisao(TipoDaPauta::Deliberacao, ResultadoDaPauta::QUORUM_NAO_ATINGIDO), 'Quórum não atingido'];
    }

    #[DataProvider('resultados')]
    #[TestDox('O resumo do resultado segue a regra do desenho conforme o tipo da pauta')]
    public function testResumo(ResultadoDaPauta $resultado, string $esperado): void
    {
        self::assertSame($esperado, $this->renderizar("{{ resumo(r) }}", $resultado));
    }

    #[TestDox('Deliberação chama de Abstenções e eleição de Votos em branco')]
    public function testRotuloDoBranco(): void
    {
        self::assertSame('Abstenções', $this->renderizar('{{ rotulo_do_branco(r) }}', self::decidido(TipoDaPauta::Deliberacao, 'Sim')));
        self::assertSame('Votos em branco', $this->renderizar('{{ rotulo_do_branco(r) }}', self::decidido(TipoDaPauta::EleicaoDeCargo, 'Ana')));
    }

    private function renderizar(string $corpo, ResultadoDaPauta $resultado): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate("{% from 'voto/_texto_do_resultado.html.twig' import resumo, rotulo_do_branco %}" . $corpo)->render(['r' => $resultado]);
    }

    private static function decidido(TipoDaPauta $tipo, string $rotulo): ResultadoDaPauta
    {
        $opcao = new ResultadoDaOpcao(1, $rotulo, 3, '3.000000', '100.00');

        return new ResultadoDaPauta(ResultadoDaPauta::DECIDIDO, $opcao, [$opcao], false, 0, '0.000000', 3, '3.000000', '3.000000', '4.000000', 4, '75.00', '50.00', true, [$opcao], tipo: $tipo);
    }

    private static function semDecisao(TipoDaPauta $tipo, string $situacao): ResultadoDaPauta
    {
        return new ResultadoDaPauta($situacao, null, [], false, 0, '0.000000', 0, '0.000000', '0.000000', '4.000000', 4, '0.00', '50.00', false, tipo: $tipo);
    }
}
