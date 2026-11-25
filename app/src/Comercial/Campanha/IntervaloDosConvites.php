<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Master\Repository\ConfiguracaoGlobalRepository;

/**
 * O intervalo entre dois convites ao mesmo contato salvo ({@see PublicosDaCampanha}, público `contatos`). Decisão do
 * Samuel de 25/11/2026: o menor possível (90 dias foi recusado); o Master o troca na Central de envios, em
 * `configuracao_global` (`comercial.intervaloConviteDias`). Padrão 7 dias, mínimo 1 (nunca o mesmo endereço duas vezes no
 * mesmo dia), máximo 365. Lido a cada chamada: o worker vive horas.
 *
 * A conta é por dia do calendário, não por hora: com N dias, quem recebeu no dia D volta a poder receber no dia D+N, à
 * hora que a campanha rodar. Assim a recorrente semanal não perde a vez por diferença de minutos entre uma execução e a outra.
 */
final class IntervaloDosConvites
{
    public const PADRAO = 7;
    public const MINIMO = 1;
    public const MAXIMO = 365;
    public const ORIGEM_PADRAO = 'padrao';

    /** @param ?ConfiguracaoGlobalRepository $configuracoes null nos testes que fixam o padrão */
    public function __construct(private readonly ?ConfiguracaoGlobalRepository $configuracoes = null)
    {
    }

    /** Os dias do intervalo: o do painel, sempre dentro da faixa (valor fora dela cai no padrão). */
    public function dias(): int
    {
        return $this->doPainel() ?? self::PADRAO;
    }

    /** De onde vem o valor: `painel` (o Master gravou um valor válido) ou `padrao`. */
    public function origem(): string
    {
        return $this->doPainel() === null ? self::ORIGEM_PADRAO : ChaveDasCampanhas::PAINEL;
    }

    public static function valido(int $dias): bool
    {
        return $dias >= self::MINIMO && $dias <= self::MAXIMO;
    }

    /**
     * O primeiro instante em que o contato ainda NÃO pode receber: quem foi convidado de `$corte` em diante (inclusive)
     * fica de fora. Com 1 dia, o corte é a meia-noite de hoje (convidado hoje, fora); com N, a meia-noite de N-1 dias atrás.
     */
    public function corte(\DateTimeImmutable $agora, ?int $dias = null): \DateTimeImmutable
    {
        $dias ??= $this->dias();

        return $agora->setTime(0, 0)->modify('-' . ($dias - 1) . ' days');
    }

    private function doPainel(): ?int
    {
        $gravado = $this->configuracoes?->intervaloDeConviteDias();

        return $gravado !== null && self::valido($gravado) ? $gravado : null;
    }
}
