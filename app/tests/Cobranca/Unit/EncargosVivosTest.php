<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\EncargosVivos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * F1 (spec "encargos ao vivo" §6.1): o serviço hidrata EM MEMÓRIA os encargos de cada obrigação VIVA
 * para a data de HOJE (relógio injetado), reusando o motor puro `CalculadoraEncargos` — sem persistir,
 * e sem tocar em obrigação congelada (Liquidada/Substituída, que carregam o snapshot).
 *
 * A config já chega RESOLVIDA (o chamador resolve 1× por caso, onde o caso está): o serviço é um
 * aplicador puro da fórmula sobre uma data de referência, sem dependência de repositório/entidade de caso.
 */
#[CoversClass(EncargosVivos::class)]
final class EncargosVivosTest extends TestCase
{
    #[TestDox('Hidrata a obrigação Viva com o juros de HOJE (relógio fixo em 20/07/2026), sem persistir')]
    public function testHidrataObrigacaoVivaParaHoje(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-07-20'));
        $sut = new EncargosVivos($clock, new CalculadoraEncargos());

        // Linha real: P=170,00, venc 13/01/2026 → 188 dias de atraso em 20/07/2026.
        $obrigacao = (new Obrigacao())
            ->setDescricao('Boleto TOPLIFE')
            ->setValorOriginal(17000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-13'));

        // Config TOPLIFE I (juros 1% a.m., multa 2%, honorários 20%, carência 30), já resolvida.
        $sut->hidratar(ConfigEncargos::padraoTopLife(2000), [$obrigacao]);

        // juros = 170,00 * 1% * 188/30, meio-para-baixo = 10,65.
        self::assertSame(1065, $obrigacao->getJuros(), 'juros vivo de hoje');
        self::assertSame(340, $obrigacao->getMulta(), 'multa fixa 2% do principal');
        self::assertSame(0, $obrigacao->getCorrecao());
        // exigível (INV-E2: SEM honorários) reflete o vivo.
        self::assertSame(17000 + 1065 + 340 + 0, $obrigacao->valorExigivel());
    }

    #[TestDox('Não toca obrigação congelada (Liquidada/Substituída): mantém o snapshot')]
    public function testNaoTocaCongelada(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-07-20'));
        $sut = new EncargosVivos($clock, new CalculadoraEncargos());

        $congelada = (new Obrigacao())
            ->setDescricao('Congelada')
            ->setValorOriginal(17000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-13'));
        $congelada->definirEncargos(999, 111, 0, 222, new \DateTimeImmutable('2026-02-01'));
        $congelada->congelarEncargos(new \DateTimeImmutable('2026-02-01'));

        $sut->hidratar(ConfigEncargos::padraoTopLife(2000), [$congelada]);

        self::assertSame(999, $congelada->getJuros(), 'snapshot intacto');
        self::assertSame(111, $congelada->getMulta(), 'snapshot intacto');
    }

    #[TestDox('Paridade ao centavo com a planilha real de 20/07: linha TOPLIFE II NN:60006')]
    public function testParidadeComPlanilhaReal(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-07-20'));
        $sut = new EncargosVivos($clock, new CalculadoraEncargos());

        // NN:60006 (TOPLIFE II, honorários 15%): P=170,00, venc 13/01/2026 → 188 dias →
        // juros 10,65 · multa 3,40 · correção 0 · honorários 27,61 · total 211,66. Valores LITERAIS
        // da planilha real (não recalculados aqui): é a prova de que o caminho vivo bate ao centavo.
        $obrigacao = (new Obrigacao())
            ->setDescricao('Boleto TOPLIFE II')
            ->setValorOriginal(17000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-13'));

        $sut->hidratar(ConfigEncargos::padraoTopLife(1500), [$obrigacao]);

        self::assertSame(1065, $obrigacao->getJuros());
        self::assertSame(340, $obrigacao->getMulta());
        self::assertSame(0, $obrigacao->getCorrecao());
        self::assertSame(2761, $obrigacao->getHonorarios());
        self::assertSame(21166, $obrigacao->totalComHonorarios());
    }

    #[TestDox('Paridade ao centavo com a prova real do Apêndice A: linha TOPLIFE I com 240 dias de atraso')]
    public function testParidadeApendiceATopLifeI240Dias(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-07-20'));
        $sut = new EncargosVivos($clock, new CalculadoraEncargos());

        // Apêndice A (spec cascata) reproduzido pelo caminho VIVO: P=170,00, venc 22/11/2025 → 240 dias
        // de atraso em 20/07/2026, carteira TOPLIFE I (honorários 20%). Valores LITERAIS da prova real:
        // juros 13,60 · multa 3,40 · correção 0,00 · honorários 37,40 · exigível 187,00 · total 224,40.
        $obrigacao = (new Obrigacao())
            ->setDescricao('Boleto TOPLIFE I')
            ->setValorOriginal(17000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2025-11-22'));

        $sut->hidratar(ConfigEncargos::padraoTopLife(2000), [$obrigacao]);

        self::assertSame(1360, $obrigacao->getJuros());
        self::assertSame(340, $obrigacao->getMulta());
        self::assertSame(0, $obrigacao->getCorrecao());
        self::assertSame(3740, $obrigacao->getHonorarios());
        self::assertSame(18700, $obrigacao->valorExigivel(), 'exigível SEM honorários (INV-E2)');
        self::assertSame(22440, $obrigacao->totalComHonorarios());
    }
}
