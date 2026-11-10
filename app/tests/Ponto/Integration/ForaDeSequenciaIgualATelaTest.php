<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Ponto\Service\LinhasDaFolhaImpressa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Paridade (spec ponto-desenho-0811, E16, P35): a folha em PDF marca "Horário fora de sequência: revisar" nos mesmos
 * dias em que a célula "Saldo dia" da tela (`ponto/_folha_saldo_dia.html.twig`) mostra "Fora de sequência".
 */
#[Group('ponto')]
#[CoversClass(LinhasDaFolhaImpressa::class)]
final class ForaDeSequenciaIgualATelaTest extends KernelTestCase
{
    /** @param array<string, string> $horarios */
    #[DataProvider('dias')]
    #[TestDox('Mesma resposta da tela: $_dataName')]
    public function testMesmaRegraDaTela(array $horarios, bool $fora): void
    {
        self::bootKernel();
        $linha = $horarios + ['entrada' => '', 'repouso' => '', 'retorno' => '', 'saida' => ''];
        $html = static::getContainer()->get(Environment::class)->render('ponto/_folha_saldo_dia.html.twig', [
            'linha' => $linha + ['chaveDia' => '2026-10-05', 'saldoDia' => null, 'registroIncompleto' => false, 'isFeriado' => false, 'nomeFeriado' => null],
            'diaTemBatida' => true,
            'hojeDaFolha' => '2026-11-09',
            'registroEmOutroEscritorio' => false,
        ]);

        self::assertSame($fora, str_contains($html, 'Fora de sequência'), 'a tela');
        self::assertSame($fora, LinhasDaFolhaImpressa::foraDeSequencia($linha), 'a folha em PDF');
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function dias(): iterable
    {
        yield 'em ordem' => [['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '17:00:00'], false];
        yield 'retorno antes do repouso' => [['entrada' => '08:00:00', 'repouso' => '13:00:00', 'retorno' => '12:00:00', 'saida' => '17:00:00'], true];
        yield 'saída antes da entrada, sem intervalo' => [['entrada' => '08:00:00', 'saida' => '07:59:59'], true];
        yield 'sem repouso, retorno antes da entrada' => [['entrada' => '08:00:00', 'retorno' => '07:00:00'], true];
        yield 'sem entrada, saída antes do retorno' => [['repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '12:30:00'], true];
        yield 'só os segundos fora' => [['entrada' => '08:00:30', 'repouso' => '08:00:10'], true];
        yield 'iguais' => [['entrada' => '08:00:00', 'repouso' => '08:00:00'], false];
        yield 'uma batida só' => [['saida' => '17:00:00'], false];
        yield 'sem batida' => [[], false];
    }
}
