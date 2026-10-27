<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\UseCase\CancelarCobrancaDaOrigemUseCase;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\ApresentadorDoPagamento;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;

/**
 * O Master ajusta o valor do pagamento na aba Pagamento (spec `trabalho-externo-valor-editavel.md`). Quem: só o
 * Master, fora da prévia (os demais levam 403). O valor novo vem como texto ("1.234,56" ou "1234.56") e é lido AQUI,
 * no servidor: maior que zero, até R$ 9.999.999,99, no máximo 2 casas (422). Motivo de 10 a 300 caracteres (422).
 * A demanda é travada (`FOR UPDATE`) e relida antes das conferências de estado (409): fora das situações do
 * pagamento, já paga, sem proposta aceita, com comprovante enviado ou valor igual ao efetivo. Grava o marco no
 * histórico (antigo, novo, quem, quando, motivo), a mensagem de sistema do tipo Pagamento (sem o motivo) e avisa o
 * solicitante pelo aviso de andamento. Outro escritório: 404 (no localizador).
 */
final class AjustarValorDoPagamentoUseCase
{
    public const MOTIVO_MIN = 10;
    public const MOTIVO_MAX = 300;
    public const TETO_EM_CENTAVOS = 999999999;

    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly CancelarCobrancaDaOrigemUseCase $cancelarCobranca,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, string $valor, string $motivo): DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);

        if ($ctx->perfil !== Perfil::Master || $ctx->somenteLeitura()) {
            throw new AcaoNaoPermitida('Só o Master altera o valor do pagamento.');
        }
        $novo = self::lerValor($valor);
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);
        if ($tamanho < self::MOTIVO_MIN || $tamanho > self::MOTIVO_MAX) {
            throw new DadosInvalidos(sprintf('Descreva o motivo com %d a %d caracteres.', self::MOTIVO_MIN, self::MOTIVO_MAX));
        }

        return $this->repositorio->comADemandaTravada($demanda, function () use ($ctx, $demanda, $novo, $motivo): DemandaExterna {
            $tenantId = (int) $demanda->getTenant()->getId();
            $antigo = ApresentadorDoPagamento::valorDaDemanda($demanda);
            $demanda->ajustarValorDoPagamento($ctx->autor(), $novo, $motivo);
            // A cobrança PIX aguardando é do valor antigo: sai de cena e a próxima abertura da aba gera a do valor novo.
            // Paga pelo banco (conferido com o pagamento travado): o valor não muda mais e tudo isto é desfeito.
            try {
                $this->cancelarCobranca->executar($tenantId, OrigemDoPagamento::TrabalhoExterno, (int) $demanda->getId(), sprintf(
                    'Valor alterado de %s para %s por %s.',
                    $antigo !== null ? ApresentadorDoPagamento::valorFormatado($antigo) : 'sem valor',
                    ApresentadorDoPagamento::valorFormatado($novo),
                    $ctx->autor()->nome,
                ), true);
            } catch (\DomainException) {
                throw new TransicaoInvalida('O banco já confirmou o pagamento desta demanda: o valor não pode mais ser alterado.');
            }
            $this->avisos->andamentoDaDemanda($ctx, $demanda);
            $this->repositorio->salvar($demanda);

            return $demanda;
        });
    }

    /**
     * "1.234,56", "1234,56", "1234.56" ou "R$ 1.500" viram "1234.56". Rejeita vazio, letras, mais de 2 casas, zero,
     * negativo e acima do teto. Ponto sozinho só é milhar quando seguido de grupos de 3 dígitos ("1.500").
     */
    public static function lerValor(string $texto): string
    {
        $t = trim(str_replace(["\u{00A0}", ' '], '', preg_replace('/^R\$/u', '', trim($texto)) ?? ''));
        $invalido = new DadosInvalidos('Informe um valor válido em reais, maior que zero e com no máximo 2 casas decimais.');

        if (str_contains($t, ',')) {
            if (!preg_match('/^(\d{1,3}(\.\d{3})+|\d+),(\d{1,2})$/', $t, $m)) {
                throw $invalido;
            }
            $inteiro = str_replace('.', '', $m[1]);
            $centavos = str_pad($m[3], 2, '0');
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
            $inteiro = str_replace('.', '', $t);
            $centavos = '00';
        } elseif (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $t, $m)) {
            $inteiro = $m[1];
            $centavos = str_pad($m[2] ?? '', 2, '0');
        } else {
            throw $invalido;
        }

        $inteiro = ltrim($inteiro, '0');
        if (strlen($inteiro) > 8) {
            throw $invalido;
        }
        $total = ((int) $inteiro) * 100 + (int) $centavos;
        if ($total <= 0 || $total > self::TETO_EM_CENTAVOS) {
            throw $invalido;
        }

        return sprintf('%d.%02d', intdiv($total, 100), $total % 100);
    }
}
