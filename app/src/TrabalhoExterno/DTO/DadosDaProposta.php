<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use App\TrabalhoExterno\Exception\DadosInvalidos;

/**
 * O formulário "Proposta comercial" do desenho. Valores em reais chegam como o usuário digita
 * ("1.500,00", "1500", "1500.5") e saem normalizados para decimal com ponto.
 */
final readonly class DadosDaProposta
{
    public function __construct(
        public string $valor,
        public ?string $despesas,
        public ?string $prazoEstimado,
        public ?\DateTimeImmutable $validade,
        public ?string $pagamento,
        public ?string $escopo,
        public ?string $naoInclui,
    ) {
    }

    /** @param array<string, mixed> $dados */
    public static function deArray(array $dados): self
    {
        $texto = static function (string $k, int $max) use ($dados): ?string {
            $v = trim((string) ($dados[$k] ?? ''));

            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        $valor = self::reais($texto('valor', 30));
        if ($valor === null) {
            throw new DadosInvalidos('Informe o valor do serviço.');
        }

        $validade = null;
        if (($v = $texto('validade', 10)) !== null) {
            $validade = \DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null;
        }

        return new self(
            $valor,
            self::reais($texto('despesas', 30)),
            $texto('prazoEstimado', 120),
            $validade,
            $texto('pagamento', 255),
            $texto('escopo', 10000),
            $texto('naoInclui', 10000),
        );
    }

    /**
     * "1.500,00" → "1500.00". Mesma leitura do `brl()` do desenho (ponto é milhar, vírgula é
     * decimal); sem vírgula, um único ponto seguido de 1 ou 2 dígitos é lido como decimal.
     */
    public static function reais(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        $t = preg_replace('/[^\d,.]/', '', $texto) ?? '';
        if (str_contains($t, ',')) {
            $t = str_replace(['.', ','], ['', '.'], $t);
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $t) !== 1) {
            $t = str_replace('.', '', $t);
        }
        if ($t === '' || !is_numeric($t) || (float) $t < 0 || (float) $t >= 1e10) {
            throw new DadosInvalidos('Valor em reais inválido.');
        }

        return number_format((float) $t, 2, '.', '');
    }
}
