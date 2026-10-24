<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * Os tetos do "Reenviar o link de confirmação" pedido pela própria pessoa: um link a cada 2 minutos e 5 a cada 24 h
 * por conta (o do cadastro conta). Usado pela rota logada (`ContaConfirmacaoController`) e pela pública
 * (`ReenvioPublicoDeConfirmacaoController`), com a mesma regra.
 *
 * Moram fora do UseCase de propósito: a automação do servidor pode chamar o `ReenviarConfirmacaoDeEmailUseCase`
 * direto, sem teto.
 */
final class TetoDoReenvioDeConfirmacao
{
    /** Teto curto: um link a cada 2 minutos por conta. */
    public const INTERVALO = '-2 minutes';

    /**
     * Teto diário: com 5 links nas últimas 24 h (contando o do cadastro), não sai outro. Sem ele, uma conta criada
     * com o e-mail de outra pessoa mandaria 30 e-mails por hora à vítima, sem fim.
     */
    public const TETO_DIARIO = 5;
    public const JANELA_DIARIA = '-24 hours';

    public const AGUARDE = 'Aguarde um instante antes de pedir outro link de confirmação.';
    public const TETO_DIARIO_ATINGIDO = 'Você já pediu vários links hoje. Confira a caixa de spam ou tente de novo amanhã.';

    public function __construct(
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** O texto da recusa se a conta bateu num dos tetos (o diário primeiro); null se pode reenviar. */
    public function recusa(User $user): ?string
    {
        $agora = $this->relogio->now();

        if ($this->confirmacaoEmailRepository->contarEmissoesDesde($user, $agora->modify(self::JANELA_DIARIA)) >= self::TETO_DIARIO) {
            return self::TETO_DIARIO_ATINGIDO;
        }

        if ($this->confirmacaoEmailRepository->houveEmissaoDesde($user, $agora->modify(self::INTERVALO))) {
            return self::AGUARDE;
        }

        return null;
    }

    /**
     * A mesma regra sobre instantes guardados fora do banco (timestamps): o marcador neutro do cadastro de um e-mail
     * que já tem conta (`ContaAConfirmar::guardarNeutro`) recusa no mesmo clique em que a conta nova recusaria.
     *
     * @param list<int> $instantes
     */
    public function recusaPelosInstantes(array $instantes): ?string
    {
        $agora = $this->relogio->now();
        $desdeODia = $agora->modify(self::JANELA_DIARIA)->getTimestamp();
        $desdeOIntervalo = $agora->modify(self::INTERVALO)->getTimestamp();

        if (count(array_filter($instantes, static fn (int $t): bool => $t >= $desdeODia)) >= self::TETO_DIARIO) {
            return self::TETO_DIARIO_ATINGIDO;
        }

        if (array_filter($instantes, static fn (int $t): bool => $t >= $desdeOIntervalo) !== []) {
            return self::AGUARDE;
        }

        return null;
    }
}
