<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Vistoria do desenho 02, c5: a tela da pasta mostra erro pelo aviso dela (`avisarErroPs`, o `gmAviso` do dc), não
 * pelo alert() do navegador. O explorador de Documentos tem o aviso dele (e o alert só sem ele). Ficam a volta para quem roda sem o pasta-dialogos.js (`|| window.alert`), os ramos que já
 * testam `avisarPs`, e o alert de propósito antes de recarregar a página (marcado no comentário logo acima).
 */
#[CoversNothing]
final class PastaSemAlertNativoTest extends TestCase
{
    private const ARQUIVOS = [
        'public/js/pasta-apensado.js', 'public/js/pasta-arquivos.js', 'public/js/pasta-cadeado-pedidos.js',
        'public/js/pasta-processo.js', 'templates/pasta/_detalhes_obs.html.twig',
        'templates/pasta/_financeiro.html.twig', 'templates/pasta/_financeiro_obs_script.html.twig',
        'templates/pasta/_financeiro_pagamentos_script.html.twig', 'templates/pasta/_resp_script.html.twig',
        'templates/pasta/show.html.twig',
    ];

    #[TestDox('Nenhum alert() solto nos arquivos da tela da pasta')]
    public function testSemAlertSolto(): void
    {
        $soltos = [];
        foreach (self::ARQUIVOS as $relativo) {
            $linhas = file(dirname(__DIR__, 3) . '/' . $relativo) ?: [];
            foreach ($linhas as $i => $linha) {
                if (preg_match('/(?<![.\w])(?:window\.)?alert\(/', $linha) !== 1 || $this->permitida($linha, $linhas[$i - 1] ?? '')) {
                    continue;
                }
                $soltos[] = $relativo . ':' . ($i + 1);
            }
        }

        self::assertSame([], $soltos);
    }

    private function permitida(string $linha, string $anterior): bool
    {
        return str_contains($linha, 'avisarErroPs || window.alert')
            || str_contains($linha, 'avisarPs')
            || str_contains($anterior, 'Fica o alert do navegador');
    }
}
