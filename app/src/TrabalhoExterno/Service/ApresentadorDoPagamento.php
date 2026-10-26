<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\Perfil;

/**
 * A aba Pagamento (`dz.pagamento`, desenho 25/10 `pg`, l.1417 a 1427), montada no servidor: o valor, a forma, o código
 * PIX e o QR saem SÓ da proposta aceita da demanda (o cliente nunca informa valor) e só enquanto não pago. O
 * Convidado não recebe o bloco: dinheiro não é dele (a política do documento também esconde o comprovante).
 */
final class ApresentadorDoPagamento
{
    public const FORMA_PADRAO = 'À vista';

    public function __construct(
        private readonly PoliticaDaDemanda $politica,
        private readonly PixDaDemanda $pix,
        private readonly QrCodeEmSvg $qr,
    ) {
    }

    /** O valor da proposta aceita ("1500.00"), ou null sem aceite (ou com valor zerado). */
    public static function valorDaDemanda(DemandaExterna $d): ?string
    {
        $valor = $d->propostaAceita()?->getValor();

        return $valor !== null && (float) $valor > 0 ? $valor : null;
    }

    /** "R$ 1.500,00" da proposta aceita. */
    public static function valorFormatado(string $valor): string
    {
        return PropostaDemanda::brl($valor);
    }

    /** @return array<string, mixed>|null null para o Convidado */
    public function bloco(Contexto $ctx, DemandaExterna $d): ?array
    {
        if ($ctx->perfil === Perfil::Convidado) {
            return null;
        }

        $valor = self::valorDaDemanda($d);
        $comprovante = $d->comprovante();
        $estado = $d->getPagoEm() !== null ? 'pago' : ($comprovante !== null ? 'conf' : 'aguarda');
        [$texto, $cor, $fundo, $icone] = match ($estado) {
            'pago' => ['Pagamento confirmado em ' . self::dataHora($d->getPagoEm()), '#1e7b45', '#e9f6ee', 'bi-check-circle-fill'],
            'conf' => ['Comprovante enviado em ' . self::dataHora($comprovante?->getEm()) . ' · aguardando confirmação do BlueJus', '#9a5b00', '#fff8e6', 'bi-hourglass-split'],
            default => ['Aguardando pagamento', '#0a6fa0', '#e5f1f7', 'bi-qr-code'],
        };
        $mostraQr = $valor !== null && $estado !== 'pago';
        $codigo = $mostraQr ? $this->pix->gerar($d->getCodigo(), $valor) : '';

        return [
            'temValor' => $valor !== null,
            'semValor' => $valor === null,
            'valor' => $valor !== null ? self::valorFormatado($valor) : '',
            'forma' => $valor !== null ? $this->forma($d->propostaAceita()) : '',
            'txid' => $d->getCodigo(),
            'estado' => $estado,
            'stTxt' => $texto,
            'stCor' => $cor,
            'stBg' => $fundo,
            'stIc' => $icone,
            'mostraQr' => $mostraQr,
            'cod' => $codigo,
            'qr' => $mostraQr ? $this->qr->gerar($codigo) : '',
            'podeComprovante' => $valor !== null && $estado === 'aguarda' && $this->politica->podeEnviarComprovante($ctx, $d),
            'comprovante' => $comprovante !== null ? ['id' => $comprovante->getId(), 'nome' => $comprovante->getNomeOriginal()] : null,
            'podeConfirmar' => $valor !== null && $estado !== 'pago' && $this->politica->podeConfirmarRecebimento($ctx, $d),
            'confirmacao' => $valor !== null ? sprintf('Confirmar o recebimento de %s via PIX?', self::valorFormatado($valor)) : '',
        ];
    }

    private function forma(?PropostaDemanda $proposta): string
    {
        $forma = trim((string) $proposta?->getPagamento());

        return $forma !== '' ? $forma : self::FORMA_PADRAO;
    }

    private static function dataHora(?\DateTimeImmutable $em): string
    {
        return $em?->format('d/m/Y H:i') ?? '';
    }
}
