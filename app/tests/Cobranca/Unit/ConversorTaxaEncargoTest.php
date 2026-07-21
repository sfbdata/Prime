<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\EntradaTaxaEncargos;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversorTaxaEncargo::class)]
final class ConversorTaxaEncargoTest extends TestCase
{
    private function conversor(): ConversorTaxaEncargo
    {
        return new ConversorTaxaEncargo(new CalculadoraEncargos());
    }

    #[Test]
    public function herdaDeixaTudoNull(): void
    {
        $e = new EntradaTaxaEncargos(); // tudo modo 'herda' por default
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(taxaJurosMensalBp: 100), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertNull($out['taxaJurosMensalBp']);
        self::assertNull($out['taxaMultaBp']);
        self::assertNull($out['taxaHonorariosBp']);
    }

    #[Test]
    public function percentPassaDireto(): void
    {
        $e = new EntradaTaxaEncargos(modoJuros: 'percent', jurosBp: 150);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(150, $out['taxaJurosMensalBp']);
    }

    #[Test]
    public function reaisDeJurosDerivaATaxaDoDia(): void
    {
        // 240 dias entre venc e ref; P=17000; R$13,60 (1360) => 100 bp.
        $e = new EntradaTaxaEncargos(modoJuros: 'reais', jurosReais: 1360);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(100, $out['taxaJurosMensalBp']);
    }

    #[Test]
    public function reaisDeMultaUsaBasePrincipalPorDefault(): void
    {
        // multa base Principal (default): R$3,40 (340) sobre P=17000 => 200 bp (2%).
        $e = new EntradaTaxaEncargos(modoMulta: 'reais', multaReais: 340);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(200, $out['taxaMultaBp']);
    }

    #[Test]
    public function roundTripPorEncargoReproduzValorDigitadoComQuantizacaoDeAteUmCentavo(): void
    {
        // P=17.000, 240 dias de atraso (venc 2025-05-06 -> ref 2026-01-01). Digita R$ em modo
        // 'reais' nos 4 encargos, deriva o bp e recompõe o valor do dia via o motor real (forward).
        // A quantização em bp inteiro pode devolver um valor levemente diferente do digitado —
        // provado ≤1 centavo em todos os casos (valores confirmados rodando o código real).
        $venc = new \DateTimeImmutable('2025-05-06');
        $ref = new \DateTimeImmutable('2026-01-01');
        $principal = 17000;

        $e = new EntradaTaxaEncargos(
            modoJuros: 'reais', jurosReais: 1400,
            modoMulta: 'reais', multaReais: 341,
            modoCorrecao: 'reais', correcaoReais: 341,
            modoHonorarios: 'reais', honorariosReais: 2000,
        );
        $out = $this->conversor()->overrides($e, new ConfigEncargos(), $principal, $venc, $ref);

        self::assertSame(103, $out['taxaJurosMensalBp']);
        self::assertSame(201, $out['taxaMultaBp']);
        self::assertSame(201, $out['taxaCorrecaoBp']);
        self::assertSame(1048, $out['taxaHonorariosBp']);

        $calc = new CalculadoraEncargos();
        $jurosHoje = $calc->calcular($principal, $venc, new ConfigEncargos(taxaJurosMensalBp: $out['taxaJurosMensalBp']), $ref)['juros'];
        $multaHoje = CalculadoraEncargos::valorDeTaxa($principal, $out['taxaMultaBp']);
        $correcaoHoje = CalculadoraEncargos::valorDeTaxa($principal, $out['taxaCorrecaoBp']);
        $baseHon = $principal + $jurosHoje + $multaHoje + $correcaoHoje;
        $honorariosHoje = CalculadoraEncargos::valorDeTaxa($baseHon, $out['taxaHonorariosBp']);

        self::assertSame(1401, $jurosHoje);
        self::assertLessThanOrEqual(1, abs($jurosHoje - 1400));
        self::assertSame(342, $multaHoje);
        self::assertLessThanOrEqual(1, abs($multaHoje - 341));
        self::assertSame(342, $correcaoHoje);
        self::assertLessThanOrEqual(1, abs($correcaoHoje - 341));
        self::assertSame(2000, $honorariosHoje);
        self::assertLessThanOrEqual(1, abs($honorariosHoje - 2000));
    }

    #[Test]
    public function baseCompostaEncadeiaJurosMultaECorrecaoDoDiaAteHonorarios(): void
    {
        // jurosBp=100 herdado (dias=240 => jurosHoje=1360>0). Com baseMulta/baseCorrecao/
        // baseHonorarios = Composta, a base de cada um inclui os encargos anteriores do dia —
        // prova o encadeamento (não é só "usa o principal" disfarçado).
        $venc = new \DateTimeImmutable('2025-05-06');
        $ref = new \DateTimeImmutable('2026-01-01');
        $principal = 17000;
        $e = new EntradaTaxaEncargos(
            modoMulta: 'reais', multaReais: 400,
            modoCorrecao: 'reais', correcaoReais: 400,
            modoHonorarios: 'reais', honorariosReais: 400,
        );

        $baseCasoComposta = new ConfigEncargos(
            taxaJurosMensalBp: 100,
            baseMulta: BaseEncargo::Composta,
            baseCorrecao: BaseEncargo::Composta,
            baseHonorarios: BaseEncargo::Composta,
        );
        $outComposta = $this->conversor()->overrides($e, $baseCasoComposta, $principal, $venc, $ref);

        self::assertSame(218, $outComposta['taxaMultaBp']);
        self::assertSame(213, $outComposta['taxaCorrecaoBp']);
        self::assertSame(209, $outComposta['taxaHonorariosBp']);

        // Prova de que a base realmente mudou: mesmo R$ digitado, mesmo principal, mas SEM somar
        // juros/multa/correção do dia (base Principal) o bp derivado é outro (maior — base menor).
        $baseCasoPrincipal = new ConfigEncargos(
            taxaJurosMensalBp: 100,
            baseMulta: BaseEncargo::Principal,
            baseCorrecao: BaseEncargo::Principal,
            baseHonorarios: BaseEncargo::Principal,
        );
        $outPrincipal = $this->conversor()->overrides($e, $baseCasoPrincipal, $principal, $venc, $ref);

        self::assertSame(235, $outPrincipal['taxaMultaBp']);
        self::assertSame(235, $outPrincipal['taxaCorrecaoBp']);
        self::assertSame(235, $outPrincipal['taxaHonorariosBp']);
        self::assertNotSame($outComposta['taxaMultaBp'], $outPrincipal['taxaMultaBp']);
        self::assertNotSame($outComposta['taxaCorrecaoBp'], $outPrincipal['taxaCorrecaoBp']);
        self::assertNotSame($outComposta['taxaHonorariosBp'], $outPrincipal['taxaHonorariosBp']);
    }

    #[Test]
    public function bordaDiasNaoPositivoZeraApenasOJurosNaoAMulta(): void
    {
        // Vencimento no futuro em relação à referência: diasDeAtraso() degrada para 0.
        // taxaJurosBpDeValor() checa dias<=0 explicitamente e devolve 0 (o override de juros some).
        // Já taxaDeValor() da multa NÃO depende de dias, só de base/valor positivos — continua
        // derivando um bp normalmente mesmo com a dívida ainda não vencida. Comportamento REAL
        // observado (não é o "provavelmente 0" hipotetizado a priori): fixado aqui pelo teste.
        $venc = new \DateTimeImmutable('2025-05-06');
        $refFutura = new \DateTimeImmutable('2025-01-01'); // antes do vencimento
        $principal = 17000;
        $e = new EntradaTaxaEncargos(modoJuros: 'reais', jurosReais: 1000, modoMulta: 'reais', multaReais: 1000);

        $out = $this->conversor()->overrides($e, new ConfigEncargos(), $principal, $venc, $refFutura);

        self::assertSame(0, $out['taxaJurosMensalBp']);
        self::assertSame(588, $out['taxaMultaBp']);
    }

    #[Test]
    public function bordaPrincipalNaoPositivoZeraTudoEmModoReais(): void
    {
        // Principal <= 0: taxaJurosBpDeValor e taxaDeValor degradam para 0 (não existe % sobre
        // base zero/negativa). Comportamento REAL confirmado: os dois zeram.
        $venc = new \DateTimeImmutable('2025-05-06');
        $ref = new \DateTimeImmutable('2026-01-01');
        $e = new EntradaTaxaEncargos(modoJuros: 'reais', jurosReais: 1000, modoMulta: 'reais', multaReais: 1000);

        $out = $this->conversor()->overrides($e, new ConfigEncargos(), 0, $venc, $ref);

        self::assertSame(0, $out['taxaJurosMensalBp']);
        self::assertSame(0, $out['taxaMultaBp']);
    }

    #[Test]
    public function decisaoConversorDerivaTaxaMesmoDentroDaJanelaDeToleranciaJurosMulta(): void
    {
        // decisão: o conversor deriva a taxa; o gate de tolerância é do motor (leitura) e o
        // desabilitar do campo R$ é da UI (Task 9/spec §5). dias=5 < tolerância=10 (o motor
        // zeraria a multa no CÁLCULO), mas o CONVERSOR ainda deriva o bp normalmente aqui — para
        // que a taxa já esteja salva quando a incidência começar (dia 11).
        $vencTol = new \DateTimeImmutable('2026-01-01');
        $refTol = new \DateTimeImmutable('2026-01-06'); // 5 dias de atraso
        $principal = 17000;
        $e = new EntradaTaxaEncargos(modoMulta: 'reais', multaReais: 340);

        $out = $this->conversor()->overrides($e, new ConfigEncargos(toleranciaJurosMultaDias: 10), $principal, $vencTol, $refTol);

        self::assertSame(200, $out['taxaMultaBp']);
        self::assertNotNull($out['taxaMultaBp']);
    }

    #[Test]
    public function modoDesconhecidoLancaExcecao(): void
    {
        // `modo` vem de campo hidden; um valor inesperado tem de estourar alto, nunca cair
        // silenciosamente em 'herda' e descartar a taxa que o usuário digitou.
        $e = new EntradaTaxaEncargos(modoJuros: 'xyz');

        $this->expectException(\InvalidArgumentException::class);

        $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));
    }
}
