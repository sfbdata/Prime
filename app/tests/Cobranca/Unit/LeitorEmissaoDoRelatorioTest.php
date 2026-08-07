<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Importacao\LeitorEmissaoDoRelatorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A data que a tela mostra como "dados atualizados até" sai do rodapé do relatório, não do relógio da
 * importação. Ver `LeitorEmissaoDoRelatorio`.
 */
#[CoversClass(LeitorEmissaoDoRelatorio::class)]
final class LeitorEmissaoDoRelatorioTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/cadastro_endereco_de_cobranca.xlsx';

    #[TestDox('Lê a data de emissão do rodapé, com hora')]
    public function testLeADataDeEmissao(): void
    {
        $emissao = (new LeitorEmissaoDoRelatorio())->ler(self::FIXTURE);

        self::assertNotNull($emissao, 'a fixture tem a linha "Emissão: 06/08/2026 19:30"');
        self::assertSame('06/08/2026 19:30', $emissao->format('d/m/Y H:i'));
    }

    #[TestDox('Arquivo inexistente devolve null em vez de estourar — é dado de tela, não de dinheiro')]
    public function testArquivoInexistenteNaoEstoura(): void
    {
        self::assertNull((new LeitorEmissaoDoRelatorio())->ler('/nao/existe/arquivo.xlsx'));
    }

    #[TestDox('Arquivo que não é planilha devolve null em vez de derrubar a importação')]
    public function testArquivoInvalidoNaoDerrubaAImportacao(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'emissao') . '.xlsx';
        file_put_contents($tmp, 'isto não é uma planilha');

        try {
            self::assertNull((new LeitorEmissaoDoRelatorio())->ler($tmp));
        } finally {
            @unlink($tmp);
        }
    }
}
