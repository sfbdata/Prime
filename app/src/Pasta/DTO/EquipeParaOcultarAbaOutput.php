<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;

/**
 * A lista do modal "Ocultar aba" (desenho L.262-279, `abaVisVals` L.4420-4427): cada pessoa que
 * pode ter a aba escondida, com "função · setor" e se a regra atual já a esconde; e o total da linha
 * "Toda a equipe (N)".
 */
final readonly class EquipeParaOcultarAbaOutput
{
    /**
     * @param list<array{id: int, nome: string, funcao: string, oculta: bool}> $pessoas
     */
    public function __construct(
        public AbaDaPasta $aba,
        public array $pessoas,
    ) {
    }

    /** @param list<UserTenant> $vinculos */
    public static function montar(AbaDaPasta $aba, array $vinculos, ?AbaOcultaDaPasta $regra): self
    {
        $pessoas = [];
        foreach ($vinculos as $vinculo) {
            $id = (int) $vinculo->getUser()->getId();
            $pessoas[] = [
                'id'     => $id,
                'nome'   => (string) ($vinculo->getUser()->getFullName() ?: $vinculo->getUser()->getEmail()),
                'funcao' => self::funcao($vinculo),
                'oculta' => $regra !== null && $regra->alcanca($id),
            ];
        }

        return new self($aba, $pessoas);
    }

    /** "função · setor", só com o que existe ([EXP] L.4425: `[u.funcao, u.setor].filter(Boolean).join(' · ')`). */
    private static function funcao(UserTenant $vinculo): string
    {
        $partes = array_filter(
            [$vinculo->getCargo()?->getNome(), $vinculo->getLotacao()?->getNome()],
            static fn (?string $p): bool => $p !== null && trim($p) !== '',
        );

        return implode(' · ', $partes);
    }

    /** @return array{aba: string, rotulo: string, total: int, pessoas: list<array{id: int, nome: string, funcao: string, oculta: bool}>} */
    public function paraJson(): array
    {
        return [
            'aba'     => $this->aba->value,
            'rotulo'  => $this->aba->rotulo(),
            'total'   => \count($this->pessoas),
            'pessoas' => $this->pessoas,
        ];
    }
}
