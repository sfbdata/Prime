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
 * Encargos AO VIVO: editar NUNCA congela (D6). Uma obrigação Viva segue recalculando na leitura
 * (vencimento → hoje × taxa); o valor materializado na edição é apenas cache. Se o gestor digita
 * juros/multa/correção, viram o cache inicial e os honorários são recompostos sobre a base digitada,
 * mas NÃO fixam a obrigação — para mudar a taxa, edita-se a config do caso/carteira (override por
 * obrigação é follow-up, §11). Uma obrigação CONGELADA (Liquidada/Substituída) tem o snapshot
 * respeitado (não recalcula). O único registro é o evento ObrigacaoEditada (antes/depois no payload).
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

        // Detecta ANTES de mutar se o gestor mexeu à mão em algum componente (juros/multa/correção OU
        // honorário): isso decide se o cache inicial usa os valores DIGITADOS ou os RECALCULADOS do motor.
        // (No modelo ao vivo nenhuma edição congela — a leitura recalcula de qualquer modo.) Comparação
        // COMPONENTE A COMPONENTE: trocar R$ 10 de multa por R$ 10 de juros muda dinheiro de categoria.
        //
        // O honorário é `?int`: `honorarios !== null` é digitação (inclusive `0`), enquanto `null` é "não
        // informado" e NÃO conta como mexida — editar só o vencimento de uma Viva, com honorário vazio,
        // recalcula na hora pelo motor.
        $mexeuManual = $input->juros !== $obrigacao->getJuros()
            || $input->multa !== $obrigacao->getMulta()
            || $input->correcao !== $obrigacao->getCorrecao()
            || $input->honorarios !== null;

        // A config da cascata (Carteira→Caso→Obrigação) NÃO depende do vencimento/valor — pode ser
        // resolvida agora, antes de mutar. O vencimento/valor NOVOS (do input) entram só no cálculo.
        $hoje = new \DateTimeImmutable('today');
        $config = $this->resolvedor->resolver($obrigacao);

        // Encargos FINAIS em variáveis locais, SEM tocar a entidade ainda (o guard do exigível roda com
        // eles e precisa poder rejeitar sem deixar a obrigação suja em memória).
        if ($mexeuManual) {
            // Gestor digitou encargos: os valores do input viram o cache inicial (o honorário vazio é
            // recomposto pelo motor sobre a base digitada). Encargos AO VIVO (D6): NÃO congela mais — a
            // obrigação segue Viva e a leitura recalcula (vencimento → hoje × taxa); o digitado é ponto
            // de partida, não override (para fixar a taxa, edita-se a config do caso/carteira, §11).
            $dias = $this->calculadora->diasDeAtraso($input->vencimentoOriginal, $hoje);
            $jFinal = $input->juros;
            $mFinal = $input->multa;
            $cFinal = $input->correcao;
            $hFinal = $input->honorarios ?? $this->calculadora->honorarios((int) $input->valorOriginal, $jFinal, $mFinal, $cFinal, $config, $dias);
            $vaiMaterializar = true;
        } elseif (!$obrigacao->encargosCongelados()) {
            // Viva e sem mexida manual: recalcula "estilo planilha" para HOJE — mudar o vencimento reflete
            // os juros na hora. Segue Viva (a leitura recalcula ao vivo).
            $novos = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
            $jFinal = $novos['juros'];
            $mFinal = $novos['multa'];
            $cFinal = $novos['correcao'];
            $hFinal = $novos['honorarios'];
            $vaiMaterializar = true;
        } else {
            // Congelada (Liquidada/Substituída) e sem mexida manual: respeita o snapshot. Mantém os
            // encargos atuais e NÃO recalcula — corrigir a descrição de uma congelada não a ressuscita.
            $jFinal = $obrigacao->getJuros();
            $mFinal = $obrigacao->getMulta();
            $cFinal = $obrigacao->getCorrecao();
            $hFinal = $obrigacao->getHonorarios();
            $vaiMaterializar = false;
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

        // `definirEncargos()` grava cada componente no seu campo (nunca a ponte deprecada
        // `setEncargosReconhecidos()`, que achatava o agregado em `juros` e zerava multa/correção).
        // Encargos AO VIVO (D6): editar NUNCA congela — a obrigação Viva segue recalculando na leitura
        // (o valor materializado aqui é só cache). Congelada (Liquidada/Substituída) já é respeitada
        // acima (não recalcula).
        if ($vaiMaterializar) {
            $obrigacao->definirEncargos($jFinal, $mFinal, $cFinal, $hFinal, $hoje);
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
     * @return array{descricao: string, valorOriginal: int, vencimentoOriginal: string, encargosReconhecidos: int, juros: int, multa: int, correcao: int, honorarios: int, referenciaExterna: ?string, encargosCongeladosEm: ?string}
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
            // Honorário (Ajuste 2): fora do exigível (INV-E2), mas agora editável — o snapshot registra o
            // antes/depois para explicar override e congelamento na auditoria.
            'honorarios' => $obrigacao->getHonorarios(),
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
