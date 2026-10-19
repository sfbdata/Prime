<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Master\Entity\RecursoEstado;

/**
 * Pedido de ligar ou desligar uma chave do catálogo (POST /master/recursos/{chave}, JSON).
 *
 * `doJson()` normaliza o corpo como o desenho faz na tela: motivo vazio vira "sem motivo", o
 * motivo longo é cortado no tamanho da coluna, e a data que não é AAAA-MM-DD válida, ou que já passou,
 * vira "sem data". Data no passado faria o desligar resultar em "Ativo" sem explicação (R4); hoje vale.
 */
final readonly class AlternarRecursoInput
{
    public function __construct(
        /** Quem liga ou desliga: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $chave,
        /** Estado desejado: true religa, false desliga. */
        public bool $ativo,
        public ?string $motivo = null,
        public ?\DateTimeImmutable $ate = null,
    ) {
    }

    /**
     * @param array<mixed> $corpo
     */
    public static function doJson(?User $ator, string $chave, array $corpo, \DateTimeImmutable $hoje): ?self
    {
        if (!is_bool($corpo['ativo'] ?? null)) {
            return null;
        }

        return new self($ator, $chave, $corpo['ativo'], self::motivo($corpo['motivo'] ?? null), self::data($corpo['ate'] ?? null, $hoje));
    }

    private static function motivo(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : mb_substr($texto, 0, RecursoEstado::MOTIVO_MAXIMO);
    }

    private static function data(mixed $valor, \DateTimeImmutable $hoje): ?\DateTimeImmutable
    {
        if (!is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $valor < $hoje->format('Y-m-d') ? null : $data;
    }
}
