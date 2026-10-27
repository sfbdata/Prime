<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Contribua\DTO\RetratoDoEspaco;

/**
 * O espaço de uma conta como a lista de "Contas e escritórios" e o ranking de "Armazenamento" o mostram
 * (spec perfis-1.2-planos-e-limites P5). Sai do `RetratoDoEspaco` da Contribua, a leitura pública do
 * domínio: o painel nunca lê as tabelas de plano e espaço direto.
 *
 * `limiteGb`, `percentual` e `limiteUsuarios` são nulos quando a conta está sem limite (ou, nos usuários,
 * quando o plano não tem limite). `faixa` é o valor do enum da Contribua (livre, atencao, alerta,
 * critico, bloqueio); o painel traduz para o rótulo e a cor da função `faixa()` do desenho.
 */
final readonly class EspacoDaContaOutput
{
    public function __construct(
        public string $planoCodigo,
        public string $planoNome,
        public ?int $limiteGb,
        public ?int $percentual,
        public string $faixa,
        public bool $semLimite,
        public ?int $limiteUsuarios,
        public string $origemDoLimite,
        public string $origemDoLimiteDeUsuarios,
        /** Bônus vigente (já somado ao `limiteGb`): GB, origem e validade (dd/mm/aaaa); nulos sem bônus. */
        public ?int $bonusGb = null,
        public ?string $bonusOrigem = null,
        public ?string $bonusAte = null,
    ) {
    }

    public static function doRetrato(RetratoDoEspaco $retrato): self
    {
        return new self(
            $retrato->planoCodigo,
            $retrato->planoNome,
            $retrato->bytesLimite === null ? null : intdiv($retrato->bytesLimite, RetratoDoEspaco::BYTES_POR_GB),
            $retrato->percentual,
            $retrato->faixa->value,
            $retrato->semLimite(),
            $retrato->limiteUsuarios,
            $retrato->origemDoLimite->value,
            $retrato->origemDoLimiteDeUsuarios->value,
            $retrato->bytesBonus > 0 ? intdiv($retrato->bytesBonus, RetratoDoEspaco::BYTES_POR_GB) : null,
            $retrato->bytesBonus > 0 ? $retrato->bonusOrigem : null,
            $retrato->bytesBonus > 0 ? $retrato->bonusAte?->format('d/m/Y') : null,
        );
    }

    /**
     * @return array{planoCodigo: string, planoNome: string, limiteGb: ?int, percentual: ?int, faixa: string, semLimite: bool, limiteUsuarios: ?int, origemDoLimite: string, origemDoLimiteDeUsuarios: string, bonusGb: ?int, bonusOrigem: ?string, bonusAte: ?string}
     */
    public function paraArray(): array
    {
        return get_object_vars($this);
    }
}
