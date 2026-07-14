<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada da criação de um Acordo num Caso de Cobrança (SPEC §12). O caso é resolvido por
 * id + tenant no CriarAcordoUseCase (guarda multi-tenant). O acordo substitui uma ou mais
 * obrigações do MESMO caso (invariável 13) — nunca apagadas, só marcadas (invariável 14) — e
 * gera novas obrigações (parcelas). Ao menos uma obrigação substituída e ao menos uma parcela.
 *
 * Ajuste 7: o gerador inteligente envia também o snapshot da negociação — `valorTotalNegociado`
 * (opcional; null = derivado de entrada + parcelas) e `valorEntrada` (a entrada vira a 1ª obrigação
 * do acordo). Quando `valorTotalNegociado` é informado, a revalidação de servidor exige
 * `valorEntrada + Σ parcelas.valor == valorTotalNegociado` (INV-B).
 */
final class CriarAcordoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotNull(message: 'Informe a data do acordo.')]
    public ?\DateTimeImmutable $dataAcordo = null;

    /** Total negociado em CENTAVOS (snapshot). Null = derivar de entrada + parcelas (modal antigo). */
    #[Assert\Positive(message: 'O total negociado deve ser positivo.')]
    public ?int $valorTotalNegociado = null;

    /** Entrada do acordo em CENTAVOS; null/0 = sem entrada. Vira a 1ª obrigação do acordo. */
    #[Assert\PositiveOrZero(message: 'A entrada não pode ser negativa.')]
    public ?int $valorEntrada = 0;

    /** Vencimento da entrada; null = usa a data do acordo (só aplicável quando há entrada). */
    public ?\DateTimeImmutable $dataEntrada = null;

    /**
     * Ids das obrigações substituídas pelo acordo (invariável 13; substituição parcial permitida).
     *
     * @var int[]
     */
    #[Assert\Count(min: 1, minMessage: 'Informe ao menos uma obrigação a substituir.')]
    #[Assert\All([new Assert\Positive(message: 'Obrigação inválida.')])]
    public array $obrigacoesSubstituidasIds = [];

    /**
     * Parcelas geradas pelo acordo (novas obrigações do caso).
     *
     * @var ParcelaAcordoInput[]
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Informe ao menos uma parcela do acordo.')]
    public array $parcelas = [];

    /**
     * INV-B: quando o total negociado é informado (gerador inteligente), a entrada mais a soma das
     * parcelas tem de fechar exatamente com ele — revalidação de servidor contra adulteração/bug de JS.
     */
    #[Assert\Callback]
    public function validarFechamentoDoTotal(ExecutionContextInterface $context): void
    {
        if ($this->valorTotalNegociado === null) {
            return;
        }

        $somaParcelas = 0;

        foreach ($this->parcelas as $parcela) {
            $somaParcelas += (int) $parcela->valor;
        }

        if (($this->valorEntrada ?? 0) + $somaParcelas !== $this->valorTotalNegociado) {
            $context->buildViolation('A entrada mais as parcelas devem somar exatamente o total negociado.')
                ->atPath('valorTotalNegociado')
                ->addViolation();
        }
    }
}
