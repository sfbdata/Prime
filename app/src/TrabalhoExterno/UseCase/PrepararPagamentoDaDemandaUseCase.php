<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Pagamento\DTO\CobrancaPixOutput;
use App\Pagamento\DTO\EmitirCobrancaPixInput;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\UseCase\CancelarCobrancaDaOrigemUseCase;
use App\Pagamento\UseCase\ConsultarCobrancaDaOrigemUseCase;
use App\Pagamento\UseCase\EmitirCobrancaPixUseCase;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Service\ApresentadorDoPagamento;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

/**
 * História: a pessoa abre a aba Pagamento. Se a integração com o banco está ligada para o Trabalho Externo, garantimos
 * uma cobrança PIX dinâmica viva para o valor de agora (spec `gateway-m1-trabalho-externo.md` §4.1). Ação explícita,
 * nunca dentro de uma leitura: o bloco da aba só lê o que este caso de uso preparou.
 *
 * - não faz nada para o Convidado, na prévia, sem valor, já pago ou fora das situações do pagamento;
 * - reaproveita a cobrança viva do mesmo valor; sem QR no banco ainda (timeout antes), repete a MESMA referência;
 * - vencida ou de valor antigo vira a tentativa seguinte (`<código>-<n>`), nunca reabre a antiga;
 * - o cliente da cobrança é o escritório dono da demanda (nome e CNPJ do cadastro dele); sem CNPJ válido não cria nada.
 * Devolve o aviso para a tela ("Confirmação automática indisponível...") ou `null` quando está tudo certo ou não se aplica.
 */
final class PrepararPagamentoDaDemandaUseCase
{
    public const VALIDADE_EM_HORAS = 24;
    public const AVISO_INDISPONIVEL = 'Confirmação automática indisponível agora. Pague pelo código abaixo e envie o comprovante.';

    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly EmitirCobrancaPixUseCase $emitir,
        private readonly ConsultarCobrancaDaOrigemUseCase $consultar,
        private readonly CancelarCobrancaDaOrigemUseCase $cancelar,
    ) {
    }

    /** `$gerarNovo`: o botão "gerar novo QR" (a abertura da aba não reabre a cobrança vencida sozinha). */
    public function executar(Contexto $ctx, string $codigo, bool $gerarNovo = false): ?string
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $valor = ApresentadorDoPagamento::valorDaDemanda($demanda);
        if ($valor === null || !$this->precisaDeCobranca($ctx, $demanda)) {
            return null;
        }

        $tenantId = (int) $demanda->getTenant()->getId();
        $origemId = (int) $demanda->getId();
        $centavos = self::emCentavos($valor);
        $agora = new \DateTimeImmutable();
        $atual = $this->consultar->executar($tenantId, OrigemDoPagamento::TrabalhoExterno, $origemId);
        if (in_array($atual?->status, [StatusDoPagamento::Paga, StatusDoPagamento::Divergente], true)) {
            // O banco já recebeu algo: nunca se emite QR novo por cima (a tela mostra o estado; o Master decide).
            return null;
        }
        $mesmoValor = $atual !== null && $atual->valorCentavos === $centavos;
        if ($mesmoValor && ($atual->viva($agora) || (!$gerarNovo && $atual->brCode !== null && $atual->expiraEm !== null && $atual->expiraEm <= $agora))) {
            return null;
        }
        $reaproveita = $mesmoValor && $atual->brCode === null && $atual->status === StatusDoPagamento::Aguardando && ($atual->expiraEm === null || $atual->expiraEm > $agora);
        if ($atual !== null && !$reaproveita
            && !$this->cancelar->executar($tenantId, OrigemDoPagamento::TrabalhoExterno, $origemId, 'Nova cobrança gerada para o valor atual.', false, $atual->pagamentoId)
            && $this->mudouDesdeALeitura($tenantId, $origemId, $atual)) {
            return null;
        }

        return $this->emitirTentativa($demanda, $centavos, ($reaproveita ? $atual->tentativas : ($atual->tentativas ?? 0) + 1), $agora);
    }

    /**
     * O cancelamento não cancelou nada: ou a cobrança lida já não estava aguardando (vencida, cancelada, expirada: segue
     * e emite a próxima) ou o mundo mudou depois da leitura (outra aba criou uma mais nova, o banco confirmou ou divergiu):
     * aí não se emite QR novo e a tela relê o estado.
     */
    private function mudouDesdeALeitura(int $tenantId, int $origemId, CobrancaPixOutput $lida): bool
    {
        $agora = $this->consultar->executar($tenantId, OrigemDoPagamento::TrabalhoExterno, $origemId);

        return $agora === null || $agora->pagamentoId !== $lida->pagamentoId
            || in_array($agora->status, [StatusDoPagamento::Paga, StatusDoPagamento::Divergente], true);
    }

    /** Convidado, prévia, já pago, fora das situações do pagamento ou integração desligada: nada a preparar. */
    private function precisaDeCobranca(Contexto $ctx, DemandaExterna $demanda): bool
    {
        return $this->politica->podeVerAbaPagamento($ctx, $demanda) && !$ctx->somenteLeitura() && $demanda->getPagoEm() === null
            && in_array($demanda->getSituacao(), AcaoDemanda::RegistrarPagamento->origens(), true)
            && $this->emitir->disponivel('te');
    }

    private function emitirTentativa(DemandaExterna $demanda, int $centavos, int $tentativa, \DateTimeImmutable $agora): ?string
    {
        $tenant = $demanda->getTenant();
        try {
            $emitida = $this->emitir->executar(new EmitirCobrancaPixInput(
                (int) $tenant->getId(),
                OrigemDoPagamento::TrabalhoExterno,
                (int) $demanda->getId(),
                $demanda->getCodigo() . '-' . $tentativa,
                $centavos,
                $agora->modify('+' . self::VALIDADE_EM_HORAS . ' hours'),
                'te',
                (string) $tenant->getName(),
                (string) preg_replace('/\D/', '', (string) $tenant->getCnpj()),
            ));
        } catch (\DomainException $e) {
            return $e->getMessage();
        }

        return $emitida === null ? self::AVISO_INDISPONIVEL : null;
    }

    /** "1500.00" (valor da proposta) em centavos, arredondado como o ajuste de valor. */
    public static function emCentavos(string $valor): int
    {
        return (int) round((float) $valor * 100);
    }
}
