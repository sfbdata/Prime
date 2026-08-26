<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ConfigEncargos;
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
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Edita (corrige) uma Obrigação cadastrada errada (ajuste 5) — descrição, valor original, vencimento,
 * referência e a taxa de encargos, com `motivo` obrigatório.
 *
 * História: o gestor cadastrou/importou uma obrigação com um dado errado e precisa corrigi-la. É uma
 * CORREÇÃO DE CADASTRO auditada, distinta de movimento operacional: o saldo continua DERIVADO
 * (CalculadoraSaldo) — mudar o valor da obrigação ajusta o saldo por derivação, nunca por coluna manual
 * (invariável 20). Guards protegem o histórico financeiro: caso encerrado (SPEC §17), obrigação ligada a
 * acordo (parcela/substituída — gerida pelo acordo), e valor exigível que cairia abaixo do já pago.
 *
 * Taxa por-obrigação (spec taxa-por-obrigacao): o gestor não digita mais um VALOR de encargo — ele
 * ajusta a TAXA própria desta obrigação (override por %/R$, `EntradaTaxaEncargos`), traduzida em bp pelo
 * `ConversorTaxaEncargo` e gravada nas quatro colunas de override ANTES do recálculo (`null` = herda a
 * cascata Carteira→Caso→Obrigação). Ao vivo (D6): editar NUNCA congela — uma obrigação Viva segue
 * recalculando na leitura (vencimento → hoje × taxa); o valor materializado aqui é só cache. Uma
 * obrigação já CONGELADA (Liquidada-coberta/Substituída) respeita o snapshot — o override é gravado (a
 * taxa própria fica registrada), mas o cache NÃO é recalculado por cima (INV-V2).
 *
 * Reconciliação da LIQUIDADA (reajuste retroativo, spec §12/§6.3): editar uma obrigação PAGA que eleve o
 * exigível vivo acima do já pago a REABRE (volta a Viva, o pagamento fica intacto, o saldo sobe pela
 * diferença). Se continuar coberta, permanece Liquidada e o snapshot é respeitado (não recalcula por
 * cima — INV-V2). O único registro é o evento ObrigacaoEditada (antes/depois no payload, agora também
 * com as quatro taxas de override).
 */
final class EditarObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
        private readonly ConversorTaxaEncargo $conversor,
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

        // Snapshot ANTES para o histórico/auditoria — capturado aqui, ANTES de qualquer mutação (inclusive
        // da taxa, gravada logo abaixo): senão o "antes" mostraria a taxa NOVA em vez da antiga.
        $antes = $this->snapshot($obrigacao);

        // "Hoje" da cascata + o caso-base (sem o override desta obrigação ainda) — resolvidos antes de
        // mutar. `totalAlocado` (o já pago nesta obrigação) rege tanto a reabertura quanto o guard abaixo.
        $hoje = new \DateTimeImmutable('today');
        $baseCaso = $caso === null ? ConfigEncargos::neutra() : $this->resolvedor->resolverDoCaso($caso);
        $totalAlocado = $this->alocacaoRepository->totalAlocadoEmObrigacoes([(int) $obrigacao->getId()], $tenant);

        // Grava os overrides de taxa ANTES de calcular (o cálculo do dia usa a config já com a taxa
        // nova) — `null` = herda o caso. Ao vivo (D6): a gravação da taxa nunca congela por si só.
        $ov = $this->conversor->overrides(
            $input->entradaTaxas(), $baseCaso, (int) $input->valorOriginal, $input->vencimentoOriginal, $hoje);
        $obrigacao
            ->setTaxaJurosMensalBp($ov['taxaJurosMensalBp'])
            ->setTaxaMultaBp($ov['taxaMultaBp'])
            ->setTaxaCorrecaoBp($ov['taxaCorrecaoBp'])
            ->setTaxaHonorariosBp($ov['taxaHonorariosBp']);
        $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);

        // Reajuste de dívida LIQUIDADA (reconciliação, spec §12/§6.3): se a edição eleva o exigível VIVO
        // acima do que já foi pago, a obrigação REABRE (volta a Viva) — fluxo "reajuste retroativo do valor
        // devido": o pagamento fica intacto e o saldo sobe pela diferença. Se continuar coberta, permanece
        // Liquidada e o snapshot é RESPEITADO (o ramo "congelada" abaixo não materializa por cima — INV-V2).
        if ($obrigacao->estaLiquidada()) {
            $vivo = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
            // ⛔ CÓPIA Nº1 DA REGRA DO EXIGÍVEL, ESCRITA À MÃO — e ela decide se uma dívida LIQUIDADA
            // REABRE. Está ERRADA desde a spec `cobranca-honorario-no-total.md`: falta o honorário, que
            // entra no `valorExigivel()` desde que a INV-E2 foi revogada. Deveria ser
            // `Obrigacao::exigivelDe(...)`, como `EncargosVivos` e `ReconciliadorLiquidacao` já fazem.
            //
            // 🔴 NÃO "arrume" só este comentário: o defeito é o CÓDIGO. Ele está registrado como achado
            // aberto (handoff §7.2, no master) e tem fatia própria. Este comentário existe porque, das
            // quatro cópias, esta era a ÚNICA sem pista nenhuma — e é a de maior consequência.
            $exigivelSeViva = (int) $input->valorOriginal + $vivo['juros'] + $vivo['multa'] + $vivo['correcao'];
            if ($exigivelSeViva > $totalAlocado) {
                $obrigacao->reabrir(); // volta a Viva → cai no ramo de recálculo ao vivo abaixo
            }
        }

        // Ramo por ESTADO (congelada verificada PRIMEIRO): uma Liquidada-ainda-coberta / Substituída
        // respeita o snapshot MESMO que a obrigação tenha ganhado uma taxa nova — nunca materializa por
        // cima (protege o snapshot da liquidação, INV-V2). Sem essa trava, senão Viva: o cache é sempre
        // recalculado pelo motor com a config já com o override (nunca "digitado" — D6, nunca congela).
        // Encargos FINAIS em locais, sem tocar o cache ainda (o guard roda com eles e precisa poder
        // rejeitar sem deixar o cache sujo em memória).
        if ($obrigacao->encargosCongelados()) {
            $jFinal = $obrigacao->getJuros();
            $mFinal = $obrigacao->getMulta();
            $cFinal = $obrigacao->getCorrecao();
            $hFinal = $obrigacao->getHonorarios();
            $vaiMaterializar = false;
        } else {
            $novos = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
            $jFinal = $novos['juros'];
            $mFinal = $novos['multa'];
            $cFinal = $novos['correcao'];
            $hFinal = $novos['honorarios'];
            $vaiMaterializar = true;
        }

        // ⛔ CÓPIA Nº2 DA REGRA DO EXIGÍVEL, ESCRITA À MÃO — o guard `ValorAbaixoDoAlocado`, logo abaixo.
        // O novo valor exigível não pode cair abaixo do que já foi pago/alocado nesta obrigação (INV-E1;
        // honorários fora, INV-E2). ⚠️ A frase descreve o código com honestidade e por isso FICA: o
        // defeito é o código, que ainda soma sem honorário depois de a INV-E2 ter sido revogada
        // (handoff §7.2, no master). O guard roda ANTES de mutar os campos de cadastro (descrição/valor/
        // vencimento/referência/cache) — só a taxa (acima) já foi gravada, pois o próprio cálculo do
        // exigível depende dela.
        $novoExigivel = (int) $input->valorOriginal + $jFinal + $mFinal + $cFinal;
        if ($novoExigivel < $totalAlocado) {
            throw new ValorAbaixoDoAlocadoException((int) $obrigacao->getId(), $novoExigivel, $totalAlocado);
        }

        $descricao = trim((string) $input->descricao);
        $referencia = $this->normalizar($input->referenciaExterna);
        $obrigacao->setDescricao($descricao);
        $obrigacao->setValorOriginal((int) $input->valorOriginal);
        $obrigacao->setVencimentoOriginal($input->vencimentoOriginal);
        $obrigacao->setReferenciaExterna($referencia);

        // `definirEncargos()` grava cada componente no seu campo (nunca a ponte deprecada
        // `setEncargosReconhecidos()`, que achatava o agregado em `juros` e zerava multa/correção).
        // Encargos AO VIVO (D6): editar NUNCA congela — a obrigação Viva segue recalculando na leitura
        // (o valor materializado aqui é só cache, já calculado com a taxa/override acima). Congelada
        // (Liquidada/Substituída) já é respeitada acima (não recalcula).
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
     * @return array{descricao: string, valorOriginal: int, vencimentoOriginal: string, encargosReconhecidos: int, juros: int, multa: int, correcao: int, honorarios: int, referenciaExterna: ?string, encargosCongeladosEm: ?string, taxaJurosMensalBp: ?int, taxaMultaBp: ?int, taxaCorrecaoBp: ?int, taxaHonorariosBp: ?int}
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
            // Honorário: entra no exigível desde `cobranca-honorario-no-total.md` (INV-E2 revogada). Aqui
            // o código está CERTO — é só o snapshot do antes/depois para a auditoria —, era o comentário
            // que estava errado. Não confundir com as cópias nº1 e nº2 acima, onde o defeito é o código.
            'honorarios' => $obrigacao->getHonorarios(),
            'referenciaExterna' => $obrigacao->getReferenciaExterna(),
            // Congelamento no snapshot só para leitura do histórico: numa Viva editada é null antes e
            // depois (editar não congela, ao vivo); numa Liquidada/Substituída explica a data de corte.
            'encargosCongeladosEm' => $obrigacao->getEncargosCongeladosEm()?->format('Y-m-d H:i:s'),
            // Taxa por-obrigação (spec taxa-por-obrigacao): as quatro colunas de override, `null` =
            // herda a cascata. Sem elas o histórico não explica POR QUE o cache mudou de valor.
            'taxaJurosMensalBp' => $obrigacao->getTaxaJurosMensalBp(),
            'taxaMultaBp' => $obrigacao->getTaxaMultaBp(),
            'taxaCorrecaoBp' => $obrigacao->getTaxaCorrecaoBp(),
            'taxaHonorariosBp' => $obrigacao->getTaxaHonorariosBp(),
        ];
    }

    private function normalizar(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
