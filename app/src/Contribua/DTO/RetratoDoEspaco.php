<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;

/**
 * O espaço de um escritório num instante: o que a tela, o aviso e o bloqueio leem.
 *
 * `bytesLimite` e `percentual` são nulos quando o escritório está sem limite. O percentual é
 * arredondado como no desenho (`Math.round(uso / lim * 100)`) e pode passar de 100.
 */
final readonly class RetratoDoEspaco
{
    public const BYTES_POR_GB = 1024 ** 3;

    public function __construct(
        public int $tenantId,
        public int $bytesUsados,
        public ?int $bytesLimite,
        public ?int $percentual,
        public FaixaDoEspaco $faixa,
        public OrigemDoLimite $origemDoLimite,
        public string $planoCodigo,
        public string $planoNome,
        public ?int $limiteUsuarios,
        public OrigemDoLimite $origemDoLimiteDeUsuarios,
        public bool $bloqueioAtivo,
    ) {
    }

    /** Cabe mais `$bytes`? Sem limite, ou com o bloqueio desligado, sempre cabe. */
    public function cabe(int $bytes): bool
    {
        if (!$this->bloqueioAtivo || $this->bytesLimite === null) {
            return true;
        }

        return $this->bytesUsados + $bytes <= $this->bytesLimite;
    }

    public function semLimite(): bool
    {
        return $this->bytesLimite === null;
    }

    /** "9,3 GB", como no desenho (`fmt`: uma casa, vírgula, sem ",0"). */
    public static function formatarGb(int $bytes): string
    {
        $gb = round($bytes / self::BYTES_POR_GB, 1);

        $texto = fmod($gb, 1.0) === 0.0 ? (string) (int) $gb : (string) $gb;

        return str_replace('.', ',', $texto) . ' GB';
    }
}
