<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\DTO\DespacharNaMetaInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Validação do despacho no servidor (C9, C11, C12): 5.000 caracteres, natureza do enum, prazo não antes de hoje. */
#[CoversClass(DespacharNaMetaInput::class)]
final class DespacharNaMetaInputTest extends TestCase
{
    private ValidatorInterface $validador;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $this->hoje = new \DateTimeImmutable('2026-10-22');
    }

    #[TestDox('5.000 caracteres (com acento) passam; 5.001 são recusados')]
    public function testLimiteDoTexto(): void
    {
        self::assertCount(0, $this->validador->validate(new DespacharNaMetaInput(str_repeat('ç', 5000), hoje: $this->hoje)));
        self::assertCount(1, $this->validador->validate(new DespacharNaMetaInput(str_repeat('ç', 5001), hoje: $this->hoje)));
    }

    #[TestDox('Prazo de hoje passa; ontem é recusado; vazio passa; data inválida é recusada')]
    public function testPrazo(): void
    {
        self::assertCount(0, $this->validador->validate(new DespacharNaMetaInput('Ok', prazo: '2026-10-22', hoje: $this->hoje)));
        self::assertCount(1, $this->validador->validate(new DespacharNaMetaInput('Ok', prazo: '2026-10-21', hoje: $this->hoje)));
        self::assertCount(0, $this->validador->validate(new DespacharNaMetaInput('Ok', prazo: null, hoje: $this->hoje)));
        self::assertGreaterThan(0, count($this->validador->validate(new DespacharNaMetaInput('Ok', prazo: '2026-13-40', hoje: $this->hoje))));
    }

    #[TestDox('Natureza fora do enum e ação desconhecida são recusadas')]
    public function testNaturezaEAcao(): void
    {
        self::assertCount(0, $this->validador->validate(new DespacharNaMetaInput('Ok', 'devolver', 'cumpra_se', hoje: $this->hoje)));
        self::assertCount(1, $this->validador->validate(new DespacharNaMetaInput('Ok', natureza: 'urgente', hoje: $this->hoje)));
        self::assertCount(1, $this->validador->validate(new DespacharNaMetaInput('Ok', 'apagar', hoje: $this->hoje)));
    }
}
