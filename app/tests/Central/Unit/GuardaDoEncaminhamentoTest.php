<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Entity\Tenant\Tenant;
use App\Tests\Central\Support\MontaEncaminhamentoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As perguntas de acesso do despacho: lista vazia não é "todos veem", e o registro do Financeiro pede o financeiro. */
#[CoversClass(GuardaDoEncaminhamento::class)]
final class GuardaDoEncaminhamentoTest extends TestCase
{
    use MontaEncaminhamentoTrait;

    #[TestDox('todosVeem com ninguém para receber é falso (despachar para ninguém não é despacho)')]
    public function testListaVaziaNaoEhTodosVeem(): void
    {
        $tenant = new Tenant();
        $pasta = $this->pastaDe($tenant);
        $guarda = $this->guarda($pasta, $this->acessoAPasta([1, 2]), $this->createStub(ParticipanteRepository::class));

        self::assertFalse($guarda->todosVeem([], $pasta));
        self::assertFalse($guarda->todosVeem([], $pasta, TipoDeItemEncaminhado::Pasta));
        self::assertTrue($guarda->todosVeem([$this->pessoa(2, 'Bruno')], $pasta), 'controle');
    }

    #[TestDox('Registro do Financeiro: quem vê a pasta mas não o financeiro não pode receber; nos outros tipos, pode')]
    public function testRegistroDoFinanceiro(): void
    {
        $tenant = new Tenant();
        $pasta = $this->pastaDe($tenant);
        [$ana, $bruno] = [$this->pessoa(1, 'Ana'), $this->pessoa(2, 'Bruno')];
        $guarda = $this->guarda($pasta, $this->acessoAPasta([1, 2]), $this->createStub(ParticipanteRepository::class), quemVeOFinanceiro: [1]);

        self::assertFalse($guarda->podeVer($bruno, $pasta, TipoDeItemEncaminhado::RegistroFinanceiro));
        self::assertTrue($guarda->podeVer($ana, $pasta, TipoDeItemEncaminhado::RegistroFinanceiro));
        self::assertTrue($guarda->podeVer($bruno, $pasta, TipoDeItemEncaminhado::RegistroDados));
        self::assertTrue($guarda->podeVer($bruno, $pasta));
        self::assertFalse($guarda->todosVeem([$ana, $bruno], $pasta, TipoDeItemEncaminhado::RegistroFinanceiro), 'um que não vê trava o grupo');

        $semPasta = $this->guarda($pasta, $this->acessoAPasta([1]), $this->createStub(ParticipanteRepository::class));
        self::assertFalse($semPasta->podeVer($bruno, $pasta, TipoDeItemEncaminhado::RegistroFinanceiro), 'sem a pasta, o financeiro nem é perguntado');
    }
}
