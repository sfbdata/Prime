<?php

declare(strict_types=1);

namespace App\Profile\DTO;

use App\Profile\Enum\VinculoColaborador;

/**
 * Quanto do cadastro está preenchido (desenho "07 - Meu Perfil", VS 18.10.26, `req`/`pct`/`falta`).
 * Itens: foto, telefone, CPF, nascimento, frase, OAB e, conforme o vínculo, CTPS (CLT) ou início do
 * contrato (demais). Nome e e-mail sempre contam como preenchidos: (ok + 2) / (itens + 2).
 */
final readonly class CompletudeDoCadastro
{
    /** @param list<string> $faltando rótulos curtos, na ordem do desenho */
    public function __construct(
        public int $percentual,
        public array $faltando,
    ) {
    }

    public static function de(PerfilOutput $perfil): self
    {
        $itens    = self::itens($perfil);
        $faltando = array_keys(array_filter($itens, static fn (bool $ok): bool => !$ok));
        $ok       = count($itens) - count($faltando);

        return new self((int) round(($ok + 2) / (count($itens) + 2) * 100), $faltando);
    }

    public function completo(): bool
    {
        return $this->percentual >= 100;
    }

    /** @return array<string, bool> rótulo do chip => preenchido */
    private static function itens(PerfilOutput $p): array
    {
        $itens = [
            'foto'       => self::tem($p->fotoUrl),
            'telefone'   => self::tem($p->telefone),
            'CPF'        => self::tem($p->cpf),
            'nascimento' => $p->dataNascimento !== null,
            'frase'      => self::tem($p->status),
            'OAB'        => self::tem($p->oabNumero),
        ];
        if ($p->vinculo === VinculoColaborador::Clt) {
            $itens['CTPS'] = self::tem($p->ctps);
        } else {
            $itens['início do contrato'] = $p->contratoInicio !== null;
        }

        return $itens;
    }

    private static function tem(?string $valor): bool
    {
        return $valor !== null && trim($valor) !== '';
    }
}
