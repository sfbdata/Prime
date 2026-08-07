<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Service\Importacao\RegistrarEmissaoNaCarteira;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Dados atualizados até" na tela da carteira.
 *
 * Duas regras, e as duas vieram de medição no dado real:
 * 1. a data é a **emissão do relatório**, não a hora da importação;
 * 2. entre os tipos, vale a **mais antiga** — medido na AMLI, cujo cadastro é de 06/08 e a
 *    inadimplência de 04/08. Mostrar 06/08 diria "em dia" com a dívida parada dois dias atrás.
 */
#[CoversClass(Carteira::class)]
final class CarteiraDadosAtualizadosAteTest extends TestCase
{
    #[TestDox('Sem importação nenhuma, não há data')]
    public function testSemImportacaoNaoHaData(): void
    {
        self::assertNull((new Carteira())->getDadosAtualizadosAte());
    }

    #[TestDox('🔑 Com tipos em datas diferentes, vale a MAIS ANTIGA — o elo mais fraco manda')]
    public function testValeAMaisAntigaEntreOsTipos(): void
    {
        $carteira = new Carteira();
        // O caso real da AMLI: cadastro reemitido em 06/08, inadimplência ainda de 04/08.
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::CADASTRO, new \DateTimeImmutable('2026-08-06 15:22'));
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::INADIMPLENCIA, new \DateTimeImmutable('2026-08-04 18:06'));

        self::assertSame(
            '04/08/2026 18:06',
            $carteira->getDadosAtualizadosAte()?->format('d/m/Y H:i'),
            'a dívida é de 04/08: anunciar 06/08 diria que está em dia quando não está',
        );
    }

    #[TestDox('Dentro do mesmo tipo a data só AVANÇA: reimportar arquivo antigo não envelhece a tela')]
    public function testDentroDoMesmoTipoSoAvanca(): void
    {
        $carteira = new Carteira();
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::RECEITAS, new \DateTimeImmutable('2026-08-06 10:00'));
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::RECEITAS, new \DateTimeImmutable('2026-07-01 10:00'));

        self::assertSame(
            '06/08/2026',
            $carteira->getDadosAtualizadosAte()?->format('d/m/Y'),
            'completar histórico com um arquivo antigo não pode fazer a tela dizer que os dados envelheceram',
        );
    }

    #[TestDox('Relatório sem linha de emissão legível não apaga o que já havia')]
    public function testEmissaoNulaNaoApagaOQueJaHavia(): void
    {
        $carteira = new Carteira();
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::ACORDOS, new \DateTimeImmutable('2026-08-04 09:14'));
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::ACORDOS, null);

        self::assertSame('04/08/2026', $carteira->getDadosAtualizadosAte()?->format('d/m/Y'));
    }

    #[TestDox('O detalhamento por tipo fica disponível para a tela')]
    public function testDetalhamentoPorTipo(): void
    {
        $carteira = new Carteira();
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::CADASTRO, new \DateTimeImmutable('2026-08-06 15:22'));
        $carteira->registrarEmissaoImportada(RegistrarEmissaoNaCarteira::RECEITAS, new \DateTimeImmutable('2026-08-04 17:35'));

        $porTipo = $carteira->getEmissaoPorTipoDeRelatorio();

        self::assertCount(2, $porTipo);
        self::assertSame('06/08/2026 15:22', $porTipo[RegistrarEmissaoNaCarteira::CADASTRO]->format('d/m/Y H:i'));
        self::assertSame('04/08/2026 17:35', $porTipo[RegistrarEmissaoNaCarteira::RECEITAS]->format('d/m/Y H:i'));
    }
}
