<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Novidades\Service\LeitorDeNovidades;
use App\Shared\VersaoDoSistema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O app/config/novidades.yaml (escrito pelo robô no PR de release) é coerente com a versão e o
 * CHANGELOG: toda versão dele foi lançada, a mais nova vem primeiro, e o texto respeita a regra
 * do dono de não ter travessão. Roda no portão Qualidade.
 */
#[CoversNothing]
final class NovidadesArquivoTest extends TestCase
{
    private const ARQUIVO = __DIR__ . '/../../config/novidades.yaml';
    private const CHANGELOG = __DIR__ . '/../../../CHANGELOG.md';

    #[TestDox('o arquivo real é lido sem erro e tem a versão atual ou anteriores')]
    public function testArquivoReal(): void
    {
        $versoes = (new LeitorDeNovidades(self::ARQUIVO))->versoes();

        self::assertNotEmpty($versoes);
        self::assertTrue(version_compare($versoes[0]->versao, VersaoDoSistema::ATUAL, '<='),
            'Novidades anuncia versão que ainda não foi lançada.');
    }

    #[TestDox('versões em ordem decrescente, todas presentes no CHANGELOG, sem travessão')]
    public function testCoerenteComOChangelog(): void
    {
        $changelog = (string) file_get_contents(self::CHANGELOG);
        $anterior = null;
        foreach ((new LeitorDeNovidades(self::ARQUIVO))->versoes() as $v) {
            if ($anterior !== null) {
                self::assertTrue(version_compare($v->versao, $anterior, '<'), "Fora de ordem: $v->versao depois de $anterior.");
            }
            self::assertStringContainsString("## [$v->versao] - ", $changelog, "Versão $v->versao não está no CHANGELOG.");
            foreach ($v->itens as $item) {
                self::assertDoesNotMatchRegularExpression('/[—–]/u', $item, "Travessão em: $item");
            }
            $anterior = $v->versao;
        }
    }
}
