<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;

final class GuardaHorasPagas
{
    public static function recusarOutroTenant(LancamentoHorasPagas $lancamento, Tenant $tenant): void
    {
        if ($lancamento->getTenant()?->getId() !== $tenant->getId()) {
            throw new HorasPagasInvalidaException('Lançamento não pertence a este escritório.');
        }
    }

    public static function validarInput(LancamentoHorasPagasInput $input): void
    {
        // Defesa própria: o Assert\Choice do DTO só roda no binding do Symfony Form. Um valor fora
        // de {descontar, acrescentar} chegando aqui (typo, chamada direta) cairia no `else` de
        // `minutosComSinal()` e inverteria o sinal — desconto viraria crédito.
        if (!in_array($input->operacao, [
            LancamentoHorasPagasInput::OPERACAO_DESCONTAR,
            LancamentoHorasPagasInput::OPERACAO_ACRESCENTAR,
        ], true)) {
            throw new HorasPagasInvalidaException('Operação inválida.');
        }

        if (($input->horas * 60) + $input->minutos <= 0) {
            throw new HorasPagasInvalidaException('Informe uma quantidade de horas maior que zero.');
        }

        // Teto de SANIDADE (não de negócio, que o dono recusou): sem ele, `horas=100000000` gera
        // 6.000.000.000 minutos, estoura o `integer` do Postgres no INSERT e o admin leva um 500 no
        // lugar de uma recusa legível. A mesma regra está no DTO — aqui porque quem valida
        // quantidade/competência é o UseCase, e o formulário não é a única porta.
        if ($input->horas > LancamentoHorasPagasInput::HORAS_MAXIMAS) {
            throw new HorasPagasInvalidaException(sprintf(
                'A quantidade de horas não pode passar de %d.',
                LancamentoHorasPagasInput::HORAS_MAXIMAS,
            ));
        }

        $motivo = trim($input->motivo);

        if ($motivo === '') {
            throw new HorasPagasInvalidaException('Informe o motivo do lançamento.');
        }

        // Mínimo da spec §3: o motivo é a única defesa documental de um lançamento que altera verba
        // trabalhista. Conta DEPOIS do trim — senão "  x" passaria com um caractere só.
        if (mb_strlen($motivo) < LancamentoHorasPagasInput::MOTIVO_MINIMO) {
            throw new HorasPagasInvalidaException(sprintf(
                'O motivo precisa ter ao menos %d caracteres.',
                LancamentoHorasPagasInput::MOTIVO_MINIMO,
            ));
        }

        $competencia = new \DateTimeImmutable(sprintf('%04d-%02d-01', $input->ano, $input->mes));
        $mesAtual    = new \DateTimeImmutable('first day of this month 00:00:00');

        if ($competencia > $mesAtual) {
            throw new HorasPagasInvalidaException('A competência não pode ser futura.');
        }
    }
}
