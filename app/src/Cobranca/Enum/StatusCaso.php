<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Ciclo de vida do Caso de Cobrança (SPEC §17). As fases da SPEC §16 mapeiam 1:1:
 * `ativo` = extrajudicial, `judicializado` = judicializada, `encerrado` = encerrada.
 * "Pronto para encerrar" NÃO é valor deste enum — é indicador derivado (saldo exigível
 * zero e caso não encerrado), pois um caso pode estar judicializado E pronto para encerrar.
 */
enum StatusCaso: string
{
    case Ativo = 'ativo';
    case Judicializado = 'judicializado';
    case Encerrado = 'encerrado';

    /**
     * O caso ainda recebe movimento de cobrança (dívida, pagamento, acordo)?
     *
     * A régua é `não encerrado`, NÃO `ativo`: pela SPEC §16 a judicialização "não encerra a cobrança,
     * representa uma mudança de fase — o caso continua acompanhando saldo, pagamentos, acordos e
     * liquidações". A §17 reserva a proibição de receber obrigação nova só ao `encerrado`, e é essa a
     * régua que as 18 guardas de mutação do domínio já aplicam via `CasoCobranca::estaEncerrado()`.
     *
     * 🔑 Existe num lugar só de propósito. Os importadores tinham régua própria (`= ativo`) e, quando
     * o escritório judicializou 54 casos da TOP LIFE I em 09/2026, eles deixaram de enxergar a
     * cobrança, abriram um segundo caso por unidade e recriaram 2.609 obrigações que já existiam —
     * R$ 390.370,46 de principal contado duas vezes. Espelha `StatusAcordo::ehVigente()`.
     */
    public function ehCobravel(): bool
    {
        // `match` exaustivo e NÃO `!== Encerrado`: com o `!==`, um status novo no enum entraria como
        // cobrável por OMISSÃO — fail-open, e num lugar onde "cobrável" decide se dívida é gravada.
        // Assim o status novo quebra alto na hora de decidir, que é o comportamento que o docblock de
        // `cobraveis()` promete.
        return match ($this) {
            self::Ativo, self::Judicializado => true,
            self::Encerrado => false,
        };
    }

    /**
     * Os valores dos status cobráveis, para o `IN` das consultas.
     *
     * 🔑 É isto que faz `ehCobravel()` ser a definição ÚNICA em vez de um enfeite: as consultas do
     * `CasoCobrancaRepository` derivam a lista daqui, então um status novo no enum entra (ou não
     * entra) nas duas por decisão de um lugar só. A alternativa — repetir `!= :encerrado` em cada
     * DQL — é o par que diverge na próxima manutenção, exatamente o defeito que esta frente veio
     * corrigir.
     *
     * @return list<string>
     */
    public static function cobraveis(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->ehCobravel()),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Ativo => 'Ativo',
            self::Judicializado => 'Judicializado',
            self::Encerrado => 'Encerrado',
        };
    }

    /** Classe Bootstrap `text-bg-*` para o badge de estado (apresentação, Etapa 8). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Ativo => 'text-bg-primary',
            self::Judicializado => 'text-bg-warning',
            self::Encerrado => 'text-bg-secondary',
        };
    }
}
