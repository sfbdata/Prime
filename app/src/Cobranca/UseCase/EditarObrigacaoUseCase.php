<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ValorAbaixoDoAlocadoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Edita (corrige) uma Obrigação cadastrada errada (ajuste 5) — descrição, valor original, vencimento,
 * referência e encargos reconhecidos, com `motivo` obrigatório.
 *
 * História: o gestor cadastrou/importou uma obrigação com um dado errado e precisa corrigi-la. É uma
 * CORREÇÃO DE CADASTRO auditada, distinta de movimento operacional: o saldo continua DERIVADO
 * (CalculadoraSaldo) — mudar o valor da obrigação ajusta o saldo por derivação, nunca por coluna manual
 * (invariável 20). Guards protegem o histórico financeiro: caso encerrado (SPEC §17), obrigação ligada a
 * acordo (parcela/substituída — gerida pelo acordo), e valor exigível que cairia abaixo do já pago.
 *
 * Encargos "estilo planilha" (F6, TODAY()): editar RECALCULA os encargos na hora. Se o gestor DIGITA
 * juros/multa/correção à mão, esses três são a verdade, os honorários são recompostos pelo motor sobre a
 * base digitada e a obrigação CONGELA (o cron não a desfaz — INV-E4). Se NÃO mexe nos encargos e a
 * obrigação é AUTOMÁTICA, o recálculo é do motor para HOJE — é aqui que mudar o vencimento reflete os
 * juros na hora; ela segue recalculável. Se está CONGELADA e o gestor não mexeu à mão, o congelamento é
 * respeitado (encargos intactos). O único registro é o evento ObrigacaoEditada (antes/depois no payload).
 */
final class EditarObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
    ) {
    }

    public function executar(EditarObrigacaoInput $input, Tenant $tenant, User $usuario): Obrigacao
    {
        // Guarda multi-tenant: só uma obrigação do próprio escritório pode ser editada.
        $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant((int) $input->obrigacaoId, $tenant);

        if ($obrigacao === null) {
            throw new ObrigacaoNaoEncontradaException((int) $input->obrigacaoId);
        }

        $caso = $obrigacao->getCaso();

        // Caso encerrado não aceita novos lançamentos/movimentos (SPEC §17): fim do ciclo do Caso.
        if ($caso !== null && $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Obrigação travada por acordo VIGENTE (parcela ativa ou substituída por acordo ativo/cumprido) é
        // gerida PELO acordo — nunca por fora (invariáveis 14/15; parcelas → item 7). Acordo rompido/
        // cancelado NÃO trava: a original voltou ao saldo e a parcela virou histórico, ambas editáveis.
        if ($obrigacao->participaDeAcordoVigente()) {
            throw new ObrigacaoDeAcordoException((int) $obrigacao->getId());
        }

        // Detecta ANTES de mutar se o gestor mexeu à mão em algum componente (juros/multa/correção). É
        // a mudança manual que sinaliza "quero este valor exato" e dispara o congelamento (spec §8,
        // INV-E4). Comparação COMPONENTE A COMPONENTE: trocar R$ 10 de multa por R$ 10 de juros mantém o
        // agregado e muda dinheiro de categoria — tem de contar como mexida.
        $mexeuManual = $input->juros !== $obrigacao->getJuros()
            || $input->multa !== $obrigacao->getMulta()
            || $input->correcao !== $obrigacao->getCorrecao();

        // A config da cascata (Carteira→Caso→Obrigação) NÃO depende do vencimento/valor — pode ser
        // resolvida agora, antes de mutar. O vencimento/valor NOVOS (do input) entram só no cálculo.
        $hoje = new \DateTimeImmutable('today');
        $config = $this->resolvedor->resolver($obrigacao);

        // Encargos FINAIS em variáveis locais, SEM tocar a entidade ainda (o guard do exigível roda com
        // eles e precisa poder rejeitar sem deixar a obrigação suja em memória).
        if ($mexeuManual) {
            // Gestor digitou: os três do input são a verdade e FIXAM (congela). Os honorários não têm
            // campo — o motor os recompõe sobre a base digitada (não preserva o valor velho nem os zera).
            $dias = $this->calculadora->diasDeAtraso($input->vencimentoOriginal, $hoje);
            $jFinal = $input->juros;
            $mFinal = $input->multa;
            $cFinal = $input->correcao;
            $hFinal = $this->calculadora->honorarios((int) $input->valorOriginal, $jFinal, $mFinal, $cFinal, $config, $dias);
            $vaiMaterializar = true;
            $vaiCongelar = true;
        } elseif (!$obrigacao->encargosCongelados()) {
            // Automática e sem mexida manual: recalcula "estilo planilha" para HOJE — é aqui que mudar o
            // vencimento reflete os juros na hora. Segue recalculável (não congela): o cron a faz crescer.
            $novos = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
            $jFinal = $novos['juros'];
            $mFinal = $novos['multa'];
            $cFinal = $novos['correcao'];
            $hFinal = $novos['honorarios'];
            $vaiMaterializar = true;
            $vaiCongelar = false;
        } else {
            // Travada e sem mexida manual: respeita o congelamento (INV-E4). Mantém os encargos atuais e
            // NÃO recalcula — corrigir a descrição de uma obrigação travada não ressuscita o recálculo.
            $jFinal = $obrigacao->getJuros();
            $mFinal = $obrigacao->getMulta();
            $cFinal = $obrigacao->getCorrecao();
            $hFinal = $obrigacao->getHonorarios();
            $vaiMaterializar = false;
            $vaiCongelar = false;
        }

        // O novo valor exigível não pode cair abaixo do que já foi pago/alocado nesta obrigação.
        //
        // Encargos separados (F4): a soma dos TRÊS FINAIS com o valor original é exatamente o
        // `valorExigivel()` do estado NOVO (INV-E1), ao centavo. Honorários ficam de fora de propósito
        // (INV-E2) — não são dívida do credor. O guard roda ANTES de mutar a entidade: mutar e só depois
        // lançar deixaria a obrigação suja em memória, à mercê de um flush alheio na mesma request.
        $novoExigivel = (int) $input->valorOriginal + $jFinal + $mFinal + $cFinal;
        $totalAlocado = $this->alocacaoRepository->totalAlocadoEmObrigacoes([(int) $obrigacao->getId()], $tenant);
        if ($novoExigivel < $totalAlocado) {
            throw new ValorAbaixoDoAlocadoException((int) $obrigacao->getId(), $novoExigivel, $totalAlocado);
        }

        // Snapshot ANTES para o histórico/auditoria (o depois é o input já normalizado + os materializados).
        $antes = $this->snapshot($obrigacao);

        $descricao = trim((string) $input->descricao);
        $referencia = $this->normalizar($input->referenciaExterna);
        $obrigacao->setDescricao($descricao);
        $obrigacao->setValorOriginal((int) $input->valorOriginal);
        $obrigacao->setVencimentoOriginal($input->vencimentoOriginal);
        $obrigacao->setReferenciaExterna($referencia);

        // F4: `definirEncargos()` grava cada componente no seu campo (nunca a ponte deprecada
        // `setEncargosReconhecidos()`, que achatava o agregado em `juros` e zerava multa/correção).
        if ($vaiMaterializar) {
            $obrigacao->definirEncargos($jFinal, $mFinal, $cFinal, $hFinal, $hoje);
        }

        // Editar encargos à mão é decisão de gente sobre dinheiro: a partir daqui o cron da F3
        // (`app:cobranca:atualizar-encargos`) não recalcula mais esta obrigação (INV-E4). Sem isto o
        // robô desfaria a correção manual na madrugada seguinte.
        if ($vaiCongelar) {
            $obrigacao->congelarEncargos($hoje);
        }

        $motivo = trim((string) $input->motivo);

        // A obrigação é managed: o flush do evento commita, na mesma transação, a alteração + o evento.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ObrigacaoEditada,
            $usuario,
            sprintf('Obrigação corrigida: %s', $motivo),
            [
                'obrigacaoId' => $obrigacao->getId(),
                'antes' => $antes,
                'depois' => $this->snapshot($obrigacao),
                'motivo' => $motivo,
            ],
            flush: true,
        );

        return $obrigacao;
    }

    /**
     * @return array{descricao: string, valorOriginal: int, vencimentoOriginal: string, encargosReconhecidos: int, juros: int, multa: int, correcao: int, referenciaExterna: ?string, encargosCongeladosEm: ?string}
     */
    private function snapshot(Obrigacao $obrigacao): array
    {
        return [
            'descricao' => $obrigacao->getDescricao(),
            'valorOriginal' => $obrigacao->getValorOriginal(),
            'vencimentoOriginal' => $obrigacao->getVencimentoOriginal()->format('Y-m-d'),
            // O agregado continua no snapshot (derivado, INV-E1) para não quebrar a leitura do
            // histórico ANTIGO, que só conhece esta chave; os três componentes entram ao lado —
            // sem eles o histórico não explica QUAL encargo o gestor mexeu.
            'encargosReconhecidos' => $obrigacao->getEncargosReconhecidos(),
            'juros' => $obrigacao->getJuros(),
            'multa' => $obrigacao->getMulta(),
            'correcao' => $obrigacao->getCorrecao(),
            'referenciaExterna' => $obrigacao->getReferenciaExterna(),
            // O histórico tem de registrar o congelamento: é ele que explica por que aquela
            // obrigação parou de crescer a partir desta edição (antes null → depois preenchido).
            'encargosCongeladosEm' => $obrigacao->getEncargosCongeladosEm()?->format('Y-m-d H:i:s'),
        ];
    }

    private function normalizar(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
