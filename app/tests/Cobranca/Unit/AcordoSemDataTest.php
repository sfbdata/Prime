<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Acordo SEM DATA — decisão do dono de 17/08: se a contabilidade tem um acordo sem data, o espelho
 * grava sem data. Ver `docs/specs/cobranca-espelho-violacoes-do-importe.md` §2.
 *
 * Estes testes existem para ficarem VERMELHOS se alguém repuser as opiniões removidas. Cada um diz,
 * no nome, qual defeito ele reintroduz.
 */
#[CoversClass(Acordo::class)]
#[CoversClass(Obrigacao::class)]
final class AcordoSemDataTest extends TestCase
{
    /**
     * 🔴 PROVA POR REINTRODUÇÃO da 7ª violação. Até 17/08 o construtor fazia
     * `$this->dataAcordo = new \DateTimeImmutable()`. Repor essa linha derruba este teste.
     *
     * Era a mesma opinião do importador ("todo acordo tem data, e na dúvida é hoje"), só que uma
     * camada abaixo — e por isso ninguém a via.
     */
    #[Test]
    #[TestDox('7a violacao: o construtor NAO inventa data — acordo novo nasce sem data')]
    public function construtorNaoInventaData(): void
    {
        $acordo = new Acordo();

        self::assertNull($acordo->getDataAcordo(), 'O construtor voltou a inventar data (7ª violação).');
        self::assertFalse($acordo->temData());
    }

    #[Test]
    #[TestDox('Data atribuida: temData() passa a valer e a data volta intacta')]
    public function comDataAtribuidaTemDataEhVerdadeiro(): void
    {
        $acordo = new Acordo();
        $acordo->setDataAcordo(new \DateTimeImmutable('2025-03-14'));

        self::assertTrue($acordo->temData());
        self::assertSame('2025-03-14', $acordo->getDataAcordo()?->format('Y-m-d'));
    }

    /** A data pode voltar a ser nula — é o que permite corrigir um dado errado sem inventar outro. */
    #[Test]
    #[TestDox('A data aceita voltar a null')]
    public function dataAceitaNull(): void
    {
        $acordo = new Acordo();
        $acordo->setDataAcordo(new \DateTimeImmutable('2025-03-14'));
        $acordo->setDataAcordo(null);

        self::assertNull($acordo->getDataAcordo());
        self::assertFalse($acordo->temData());
    }

    /**
     * 🔑 O ESTADO QUE O DONO EXIGIU NO MODELO: "não calculado" ≠ "calculado e deu R$ 0,00".
     *
     * Sem esta distinção o sistema afirma um valor que não tem, e a gerência não consegue julgar a
     * falta batendo o olho na coluna.
     */
    #[Test]
    #[TestDox('Substituida por acordo SEM data: encargo NAO CALCULADO, distinto de zero')]
    public function substituidaPorAcordoSemDataTemEncargoNaoCalculado(): void
    {
        $acordo = new Acordo();
        $obrigacao = new Obrigacao();
        $obrigacao->setAcordoSubstituto($acordo);

        self::assertTrue($obrigacao->encargosNaoCalculados());
    }

    #[Test]
    #[TestDox('Substituida por acordo COM data: encargo calculado, mesmo valendo zero')]
    public function substituidaPorAcordoComDataTemEncargoCalculado(): void
    {
        $acordo = new Acordo();
        $acordo->setDataAcordo(new \DateTimeImmutable('2025-03-14'));
        $obrigacao = new Obrigacao();
        $obrigacao->setAcordoSubstituto($acordo);

        self::assertFalse($obrigacao->encargosNaoCalculados());

        // Zero de VERDADE: calculado, deu zero. Distinguível do caso acima, que é o ponto.
        $obrigacao->definirEncargos(0, 0, 0, 0, new \DateTimeImmutable('2025-03-14'));
        self::assertFalse($obrigacao->encargosNaoCalculados());
        self::assertSame(0, $obrigacao->getJuros());
    }

    /** Obrigação comum (não substituída) nunca cai no estado "não calculado". */
    #[Test]
    #[TestDox('Obrigacao nao substituida nunca fica NAO CALCULADA')]
    public function obrigacaoSemAcordoSubstitutoNaoEhNaoCalculada(): void
    {
        self::assertFalse((new Obrigacao())->encargosNaoCalculados());
    }
}
