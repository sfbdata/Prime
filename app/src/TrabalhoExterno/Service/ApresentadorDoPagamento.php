<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Pagamento\DTO\CobrancaPixOutput;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\UseCase\ConsultarCobrancaDaOrigemUseCase;
use App\Pagamento\UseCase\EmitirCobrancaPixUseCase;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\UseCase\PrepararPagamentoDaDemandaUseCase;

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
        private readonly ConsultarCobrancaDaOrigemUseCase $cobrancas,
        private readonly EmitirCobrancaPixUseCase $emitir,
    ) {
    }

    /** O valor a cobrar ("1500.00"): o ajustado pelo Master ou o da proposta aceita; null sem aceite (ou com valor zerado). */
    public static function valorDaDemanda(DemandaExterna $d): ?string
    {
        return $d->valorEfetivoDoPagamento();
    }

    /** "R$ 1.500,00" do valor a cobrar. */
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
        [$estado, $texto, $cor, $fundo, $icone] = $this->faixaDeEstado($d);
        $dinamica = $this->cobrancaDinamica($d, $valor);
        $mostraQr = $valor !== null && $estado !== 'pago' && !$dinamica['vencida'];
        $codigo = match (true) {
            !$mostraQr => '',
            $dinamica['viva'] !== null => (string) $dinamica['viva']->brCode,
            default => $this->pix->gerar($d->getCodigo(), (string) $valor),
        };

        return [
            'temValor' => $valor !== null,
            'semValor' => $valor === null,
            'valor' => $valor !== null ? self::valorFormatado($valor) : '',
            'valorAjustado' => $d->getValorPagamentoAjustado() !== null,
            'valorNumero' => $valor ?? '',
            'podeEditarValor' => $this->politica->podeAjustarValorDoPagamento($ctx, $d),
            'forma' => $valor !== null ? $this->forma($d->propostaAceita()) : '',
            'txid' => $d->getCodigo(),
            'estado' => $estado,
            'stTxt' => $texto, 'stCor' => $cor, 'stBg' => $fundo, 'stIc' => $icone,
            'mostraQr' => $mostraQr,
            'cod' => $codigo,
            'qr' => $mostraQr ? $this->qr->gerar($codigo) : '',
            'podeComprovante' => $valor !== null && $estado === 'aguarda' && $this->politica->podeEnviarComprovante($ctx, $d),
            'comprovante' => $comprovante !== null ? ['id' => $comprovante->getId(), 'nome' => $comprovante->getNomeOriginal()] : null,
            'podeConfirmar' => $valor !== null && $estado !== 'pago' && $this->politica->podeConfirmarRecebimento($ctx, $d),
            'podeRecusar' => $valor !== null && $this->politica->podeRecusarComprovante($ctx, $d),
            'confirmacao' => $valor !== null ? sprintf('Confirmar o recebimento de %s via PIX?', self::valorFormatado($valor)) : '',
        ] + $this->camposDoPixDinamico($ctx, $dinamica);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} estado, texto, cor, fundo e ícone da faixa */
    private function faixaDeEstado(DemandaExterna $d): array
    {
        $comprovante = $d->comprovante();
        $estado = $d->getPagoEm() !== null ? 'pago' : ($comprovante !== null ? 'conf' : 'aguarda');

        return [$estado, ...match ($estado) {
            'pago' => ['Pagamento confirmado em ' . self::dataHora($d->getPagoEm()), '#1e7b45', '#e9f6ee', 'bi-check-circle-fill'],
            'conf' => ['Comprovante enviado em ' . self::dataHora($comprovante?->getEm()) . ' · aguardando confirmação do BlueJus', '#9a5b00', '#fff8e6', 'bi-hourglass-split'],
            default => ['Aguardando pagamento', '#0a6fa0', '#e5f1f7', 'bi-qr-code'],
        }];
    }

    /**
     * PIX dinâmico (gateway M1): `dinamico` = o código é a cobrança do banco; `preparar` = a tela pede a cobrança ao
     * abrir a aba; `vencida` = botão "gerar novo QR"; `venceEm` = validade; `confirmaComMotivo` = o manual pede motivo.
     *
     * @param array{viva: ?CobrancaPixOutput, vencida: bool, preparar: bool} $dinamica
     *
     * @return array<string, mixed>
     */
    private function camposDoPixDinamico(Contexto $ctx, array $dinamica): array
    {
        return [
            'dinamico' => $dinamica['viva'] !== null,
            'preparar' => $dinamica['preparar'] && !$ctx->somenteLeitura(),
            'vencida' => $dinamica['vencida'],
            'venceEm' => $dinamica['viva']?->expiraEm !== null ? self::dataHora($dinamica['viva']->expiraEm) : '',
            'confirmaComMotivo' => $dinamica['viva'] !== null,
        ];
    }

    /**
     * A cobrança dinâmica da demanda, só quando a integração está ligada para o Trabalho Externo e a demanda não foi
     * paga. `viva`: aguardando, com QR e do valor de agora. `vencida`: a última passou da validade (botão para a seguinte).
     * `preparar`: nada vivo nem vencido, então a aba pede a cobrança.
     *
     * @return array{viva: ?CobrancaPixOutput, vencida: bool, preparar: bool}
     */
    private function cobrancaDinamica(DemandaExterna $d, ?string $valor): array
    {
        $nada = ['viva' => null, 'vencida' => false, 'preparar' => false];
        if ($valor === null || $d->getPagoEm() !== null || !$this->emitir->disponivel('te')) {
            return $nada;
        }
        $agora = new \DateTimeImmutable();
        $cobranca = $this->cobrancas->executar((int) $d->getTenant()->getId(), OrigemDoPagamento::TrabalhoExterno, (int) $d->getId());
        $mesmoValor = $cobranca !== null && $cobranca->valorCentavos === PrepararPagamentoDaDemandaUseCase::emCentavos($valor);
        if ($cobranca !== null && $mesmoValor && $cobranca->viva($agora)) {
            return ['viva' => $cobranca, 'vencida' => false, 'preparar' => false];
        }
        if ($cobranca !== null && $mesmoValor && $cobranca->brCode !== null && $cobranca->expiraEm !== null && $cobranca->expiraEm <= $agora) {
            return ['viva' => null, 'vencida' => true, 'preparar' => false];
        }

        return ['viva' => null, 'vencida' => false, 'preparar' => true];
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
