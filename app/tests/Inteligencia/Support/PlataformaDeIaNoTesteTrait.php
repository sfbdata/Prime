<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Contribua\Service\PlanoPagoDoEscritorio;
use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\DTO\SaldoDeIaOutput;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Service\SaldoDeIaDaPessoa;

/**
 * O repositório da plataforma da BlueJus IA para quem monta a {@see \App\Inteligencia\Service\DisponibilidadeDeInteligencia}
 * à mão: devolve a linha com a chave geral do Master ligada (o padrão dos testes que provam o resto da cadeia) ou
 * desligada.
 */
trait PlataformaDeIaNoTesteTrait
{
    private function plataformaDeIa(bool $ligada = true): ConfiguracaoDaPlataformaDeIaRepository
    {
        $configuracao = new ConfiguracaoDaPlataformaDeIa();
        if ($ligada) {
            $configuracao->ligarParaTodos(new User(), new \DateTimeImmutable());
        }
        $repositorio = $this->createStub(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obter')->willReturn($configuracao);

        return $repositorio;
    }

    /** O escritório do teste paga plano (padrão: o saldo gratuito nem é consultado) ou não. */
    private function planoPagoNoTeste(bool $pago = true): PlanoPagoDoEscritorio
    {
        $planos = $this->createStub(PlanoPagoDoEscritorio::class);
        $planos->method('temPlanoPago')->willReturn($pago);

        return $planos;
    }

    /** O saldo de I.A. da pessoa no teste: com folga (padrão) ou esgotado. */
    private function saldoNoTeste(bool $esgotado = false): SaldoDeIaDaPessoa
    {
        $saldo = $this->createStub(SaldoDeIaDaPessoa::class);
        $saldo->method('de')->willReturn(new SaldoDeIaOutput(
            seAplica: true,
            tokensUsados: $esgotado ? 100_000 : 0,
            tokensDireito: 100_000,
            pecasUsadas: $esgotado ? 3.0 : 0.0,
            pecasDireito: 3,
            percentual: $esgotado ? 100 : 0,
            esgotado: $esgotado,
            perto: $esgotado,
            colegasCadastrados: 0,
        ));

        return $saldo;
    }
}
