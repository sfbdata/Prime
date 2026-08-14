<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava o valor da causa de uma pasta a partir do que o humano digitou.
 *
 * A conversão é feita só com string — dinheiro não passa por float em ponto
 * nenhum do caminho. Entrada em pt-BR ("12.860,00", "R$ 12.860,00", "12860"),
 * saída no formato que o Doctrine grava em decimal(15,2) ("12860.00").
 *
 * Campo em branco limpa o cadastro (nulo), que é diferente de gravar R$ 0,00:
 * nulo fica de fora da média por CPF, zero entra nela.
 */
final class AtualizarValorCausaUseCase
{
    /** decimal(15,2) comporta 13 dígitos antes da vírgula. */
    private const MAX_DIGITOS_INTEIROS = 13;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, ?string $entrada): void
    {
        $pasta->setValorCausa($this->normalizar($entrada));
        $this->em->flush();
    }

    /**
     * @throws \InvalidArgumentException quando o texto digitado não é um valor em reais
     */
    private function normalizar(?string $entrada): ?string
    {
        $texto = str_replace(
            ['R$', 'r$', ' ', "\u{00A0}", "\u{202F}"],
            '',
            trim((string) $entrada)
        );

        if ($texto === '') {
            return null;
        }

        if (str_starts_with($texto, '-')) {
            throw new \InvalidArgumentException('O valor da causa não pode ser negativo.');
        }

        if (preg_match('/[^0-9.,]/', $texto) === 1) {
            throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
        }

        [$inteiro, $decimal] = $this->separarParteInteiraEDecimal($texto);

        if ($inteiro === '' && $decimal === '') {
            throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
        }

        if (strlen($decimal) > 2) {
            throw new \InvalidArgumentException('Use no máximo duas casas decimais, como 12.860,00.');
        }

        $inteiro = ltrim($inteiro, '0');
        if ($inteiro === '') {
            $inteiro = '0';
        }

        if (strlen($inteiro) > self::MAX_DIGITOS_INTEIROS) {
            throw new \InvalidArgumentException('Valor acima do limite aceito para o valor da causa.');
        }

        return $inteiro . '.' . str_pad($decimal, 2, '0', STR_PAD_RIGHT);
    }

    /**
     * Decide quem é separador de milhar e quem é separador decimal.
     *
     * Com vírgula presente, ela manda: o ponto é milhar. Sem vírgula, o ponto só
     * é milhar quando forma grupos exatos de três dígitos ("12.860", "1.234.567");
     * caso contrário é decimal ("12860.50"), que é como sai do teclado numérico.
     *
     * @return array{string, string}
     */
    private function separarParteInteiraEDecimal(string $texto): array
    {
        if (str_contains($texto, ',')) {
            if (substr_count($texto, ',') > 1) {
                throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
            }

            [$inteiro, $decimal] = explode(',', $texto);

            if (str_contains($decimal, '.')) {
                throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
            }

            return [str_replace('.', '', $inteiro), $decimal];
        }

        // Grupo de milhar nunca começa em zero: "0.500" não é meio milhar, é uma
        // tentativa de decimal com três casas — que o passo seguinte recusa, em vez
        // de virar R$ 500,00 em silêncio.
        if (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $texto) === 1) {
            return [str_replace('.', '', $texto), ''];
        }

        $pontos = substr_count($texto, '.');

        if ($pontos === 0) {
            return [$texto, ''];
        }

        if ($pontos === 1) {
            [$inteiro, $decimal] = explode('.', $texto);

            return [$inteiro, $decimal];
        }

        throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
    }
}
