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
    /**
     * Ninguém acerta o próprio banco de horas — nem super-admin. A trava é sobre a identidade,
     * não sobre o papel: quem tem o papel já poderia se autoconceder e depois apagar o rastro.
     */
    public static function recusarAutoLancamento(User $colaborador, User $autor): void
    {
        if ($colaborador->getId() === $autor->getId()) {
            throw new HorasPagasInvalidaException('Você não pode lançar horas pagas para si mesmo.');
        }
    }

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

        if (trim($input->motivo) === '') {
            throw new HorasPagasInvalidaException('Informe o motivo do lançamento.');
        }

        $competencia = new \DateTimeImmutable(sprintf('%04d-%02d-01', $input->ano, $input->mes));
        $mesAtual    = new \DateTimeImmutable('first day of this month 00:00:00');

        if ($competencia > $mesAtual) {
            throw new HorasPagasInvalidaException('A competência não pode ser futura.');
        }
    }
}
