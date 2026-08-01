<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CancelarAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AcordoComParcelaPagaException;
use App\Cobranca\Exception\AcordoComParcelasRenegociadasException;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\RestauradorObrigacoesOriginais;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cancela MANUALMENTE um Acordo (spec `cobranca-cancelar-acordo.md`): decisão do gestor de
 * descartar um acordo firmado por engano.
 *
 * Cancelar é "isto nunca deveria ter existido" — diferente de ROMPER, que é "o devedor descumpriu".
 * Para o gestor os dois são bem distintos: **o acordo cancelado SOME da tela e não abre** (404),
 * sobrando só uma linha no histórico; o rompido continua acessível em "Acordos encerrados".
 *
 * ⚠️ **Some da tela, mas NÃO é apagado do banco — e a diferença é dinheiro.** A primeira versão desta
 * mudança apagava acordo e parcelas. O importador de inadimplência procura o acordo pelo número
 * externo (`AcordoRepository::findOnePorNumeroExternoNaCarteira`, que não filtra status) e, não o
 * achando, **cria um novo já ATIVO** (`ImportarRelatorioCarteiraUseCase::resolverOuCriarAcordo`). Com
 * a linha apagada, a próxima importação ressuscitava o acordo com as parcelas enquanto as originais
 * seguiam exigíveis: **a mesma dívida contada duas vezes**.
 *
 * Manter a linha também é o que cumpre a régua do dono de que ação de dinheiro **não pode ser
 * irreversível**: apagada, nem o vínculo com as originais nem o histórico do que foi negociado teriam
 * como voltar.
 *
 * História: o acordo é resolvido por id + tenant (guarda multi-tenant) e só cancela se estiver
 * `ativo`; o motivo é OPCIONAL (diferente do rompimento). O saldo NÃO é revertido imperativamente —
 * as originais voltam e as parcelas saem por derivação do status (invariável 20). A única escrita
 * sobre as originais é o descongelamento do §D5, sem o qual elas voltam ao saldo com os juros
 * parados. Tudo numa transação só, fechada pelo flush único do evento.
 */
final class CancelarAcordoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RestauradorObrigacoesOriginais $restaurador,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(CancelarAcordoInput $input, Tenant $tenant, User $usuario): Acordo
    {
        // Guarda multi-tenant: o acordo tem de pertencer ao próprio escritório.
        $acordo = $this->acordoRepository->findOneByIdDoTenant((int) $input->acordoId, $tenant);

        if ($acordo === null) {
            throw new AcordoNaoEncontradoException((int) $input->acordoId);
        }

        // Só um acordo ativo transiciona de estado (SPEC §12).
        if (!$acordo->estaAtivo()) {
            throw new AcordoNaoAtivoException((int) $acordo->getId(), $acordo->getStatus());
        }

        // Ajuste 9 §2.1: mesmo vetor do rompimento — cancelar também deixa o acordo NÃO vigente, e com
        // parcelas renegociadas por um acordo vigente isso duplicaria a dívida no saldo. Só alcança dado
        // legado (a criação do estado está bloqueada por INV-I).
        $renegociadas = $this->obrigacaoRepository->parcelasRenegociadasPorAcordoVigente($acordo);

        if ($renegociadas !== []) {
            throw new AcordoComParcelasRenegociadasException(
                (int) $acordo->getId(),
                (int) $renegociadas[0]->getAcordoSubstituto()?->getId(),
            );
        }

        // Os dois conjuntos vêm por QUERY, nunca das coleções inversas do acordo: elas nascem vazias
        // quando o acordo foi criado na mesma unidade de trabalho (só o lado dono é escrito), e aí a
        // guarda de pagamento receberia lista vazia e deixaria passar acordo com dinheiro recebido.
        $parcelas = $this->obrigacaoRepository->parcelasDoAcordo($acordo, $tenant);
        $substituidas = $this->restaurador->substituidasDe($acordo, $tenant);

        // §D4: recusa ANTES de escrever qualquer coisa.
        $this->recusarSeAlgumaParcelaFoiPaga($acordo, $parcelas, $tenant);

        $motivo = $this->normalizarMotivo($input->motivo);
        $acordo->cancelar($motivo);

        // §D5: as originais voltam ao saldo por derivação, mas uma original CONGELADA volta com os juros
        // parados — `EncargosVivos::hidratar` pula congelada. Este é o defeito que o dono reportou.
        $descongeladas = $this->restaurador->restaurar($substituidas);

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->acordoRepository->salvar($acordo);

        // O evento é a ÚNICA coisa que o gestor vê do acordo cancelado — a tela não o mostra mais e a
        // rota dele dá 404. Por isso a linha é AUTOCONTIDA: número, quantidade de parcelas e valor
        // ficam aqui, sem depender de abrir o acordo para entender o que se cancelou.
        $this->registrarEvento->registrar(
            $acordo->getCaso(),
            TipoEventoHistorico::AcordoCancelado,
            $usuario,
            sprintf(
                'Acordo #%d de %d parcela(s) foi cancelado',
                (int) $acordo->getId(),
                count($parcelas),
            ),
            [
                'motivo' => $motivo,
                'acordo_id' => (int) $acordo->getId(),
                'parcelas' => count($parcelas),
                'valor_parcelas_centavos' => array_sum(
                    array_map(static fn (Obrigacao $p): int => $p->getValorOriginal(), $parcelas),
                ),
                'obrigacoes_descongeladas' => $descongeladas,
            ],
            flush: true,
        );

        return $acordo;
    }

    /**
     * §D4: acordo com pagamento numa parcela não cancela — desfaz-se o pagamento primeiro.
     *
     * O motivo é de dinheiro, não de integridade: cancelado, o acordo deixa de ser vigente e suas
     * parcelas saem do exigível; a `CalculadoraSaldo` só abate alocações de obrigações EXIGÍVEIS
     * (`totalAlocadoEmObrigacoes`), então o valor já recebido **para de ser descontado** e a dívida
     * original volta cheia — dinheiro recebido sumindo da conta, em silêncio.
     *
     * ⚠️ RESSALVA (spec §3.1): desfazer pagamento ainda NÃO existe. Medido em 01/08: nenhuma parcela
     * de acordo tem pagamento, no dev nem em produção — ninguém está travado hoje.
     *
     * @param Obrigacao[] $parcelas
     */
    private function recusarSeAlgumaParcelaFoiPaga(Acordo $acordo, array $parcelas, Tenant $tenant): void
    {
        $ids = [];
        foreach ($parcelas as $parcela) {
            $id = $parcela->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        if ($this->alocacaoRepository->existeAlocacaoEmObrigacoes($ids, $tenant)) {
            throw new AcordoComParcelaPagaException((int) $acordo->getId());
        }
    }

    private function normalizarMotivo(?string $motivo): ?string
    {
        if ($motivo === null) {
            return null;
        }

        $motivo = trim($motivo);

        return $motivo !== '' ? $motivo : null;
    }
}
