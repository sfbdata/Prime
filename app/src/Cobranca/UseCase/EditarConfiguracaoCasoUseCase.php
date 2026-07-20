<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarConfiguracaoCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Edita os HONORÁRIOS de um Caso de Cobrança âncora (Ajuste 2, Fatia A) e recalcula na hora os
 * honorários das obrigações automáticas e vivas do caso.
 *
 * História (D-A2-1/D-A2-2): a tela do objeto mostra o caso âncora; editar "os honorários do objeto" é
 * editar a config de honorários do caso — forma, percentual, base e carência (nenhuma coluna nova). O
 * caso é resolvido por id + tenant (guarda multi-tenant): inexistente/de outro escritório é erro de
 * entrada (CasoNaoEncontradoException), tratado no controller.
 *
 * Recálculo imediato do HONORÁRIO (D-A2-3, é dinheiro): mudar o percentual aqui atualiza já o honorário
 * materializado. Para cada obrigação AUTOMÁTICA (não congelada) e VIVA do caso
 * (`ObrigacaoRepository::paraRecalculoDeEncargosDoCaso`), recalcula SOMENTE o HONORÁRIO para HOJE, sobre
 * a base ATUAL (`valorOriginal + juros + multa + correção`). Congeladas (Liquidada/Substituída) ficam
 * intactas. No modelo "ao vivo" o honorário também é recomputado na leitura (hidratação); este recálculo
 * apenas mantém a coluna coerente de imediato para quem lê o cache antes da próxima hidratação.
 *
 * ⚠️ O EXIGÍVEL (juros/multa/correção, INV-E1 — alimenta saldo/FIFO/acordo) fica INTACTO de propósito.
 * Editar a config de HONORÁRIOS não pode mexer no exigível. A versão inicial recompunha os QUATRO
 * encargos com `calcular()`, e uma auditoria adversarial pegou a bomba: juros/multa/correção descem da
 * CARTEIRA (o caso só snapshota a taxa de honorário), então se a taxa da carteira tivesse sido baixada,
 * este laço reduziria o exigível de TODAS as automáticas do caso num POST, sem guard de alocado. Recalcular
 * só o honorário fecha isso POR CONSTRUÇÃO. O honorário fica fora do exigível (INV-E2), logo pode subir OU
 * descer livremente aqui — que era a intenção de D-A2-3.
 *
 * Persistência em flush ÚNICO: o evento de auditoria entra sem flush e o `salvar($caso, true)` fecha a
 * transação (o caso, as obrigações managed do laço e o evento numa unidade só).
 */
final class EditarConfiguracaoCasoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly ResolvedorConfigEncargos $resolvedor,
        private readonly CalculadoraEncargos $calculadora,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(EditarConfiguracaoCasoInput $input, Tenant $tenant, User $usuario): CasoCobranca
    {
        // Guarda multi-tenant: só um caso do próprio escritório pode ser configurado.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Snapshot ANTES, para o evento de auditoria documentar o antes/depois.
        $formaAntes = $caso->getFormaHonorarios();
        $percentualAntes = $caso->getPercentualHonorarios();
        $baseAntes = $caso->getBaseHonorarios();
        $carenciaAntes = $caso->getCarenciaHonorariosDias();

        // Aplica a nova config de honorários do caso (só honorários — D-A2-2).
        $caso->setFormaHonorarios($input->formaHonorarios);
        $caso->setPercentualHonorarios($input->percentualHonorarios);
        $caso->setBaseHonorarios($input->baseHonorarios);
        $caso->setCarenciaHonorariosDias($input->carenciaHonorariosDias);

        // Recálculo imediato (D-A2-3): a config já reflete o caso novo (o resolvedor lê os getters).
        $hoje = new \DateTimeImmutable('today');
        $recalculadas = 0;

        foreach ($this->obrigacaoRepository->paraRecalculoDeEncargosDoCaso($caso) as $obrigacao) {
            // Belt-and-suspenders do INV-E4: o predicado já exclui congeladas, mas se uma escapar o
            // laço a pula — número travado por gente nunca é sobrescrito por este recálculo.
            if ($obrigacao->encargosCongelados()) {
                continue;
            }

            $config = $this->resolvedor->resolver($obrigacao);
            $dias = $this->calculadora->diasDeAtraso($obrigacao->getVencimentoOriginal(), $hoje);

            // SÓ O HONORÁRIO é recalculado, sobre o exigível ATUAL (juros/multa/correção já
            // materializados). Nada de `calcular()` aqui: recompor os quatro reduziria o exigível se a
            // taxa da carteira caísse, sem freio — a bomba da F2 (ver docblock). `honorarios()` é
            // aritmética pura (não estoura como o regime composto), então não há caso a pular.
            $novoHonorario = $this->calculadora->honorarios(
                $obrigacao->getValorOriginal(),
                $obrigacao->getJuros(),
                $obrigacao->getMulta(),
                $obrigacao->getCorrecao(),
                $config,
                $dias,
            );

            // Grava mantendo juros/multa/correção INTACTOS (o exigível não se move); só o honorário muda.
            // Não congela: a obrigação segue automática (INV-E4 — o cron continua a atualizando).
            $obrigacao->definirEncargos(
                $obrigacao->getJuros(),
                $obrigacao->getMulta(),
                $obrigacao->getCorrecao(),
                $novoHonorario,
                $hoje,
            );
            ++$recalculadas;
        }

        // Auditoria: UM evento no histórico do caso (não um por obrigação — seria ruído). Sem flush
        // aqui; o salvar do caso fecha a transação. Mudança de config FINANCEIRA precisa de autor: o
        // evento registra QUEM alterou os honorários (achado da revisão da Fatia A).
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ValorAtualizadoReconhecido,
            $usuario,
            sprintf(
                'Honorários do caso atualizados; %d obrigação(ões) automática(s) recalculada(s).',
                $recalculadas,
            ),
            [
                'formaAntes' => $formaAntes->value,
                'formaDepois' => $caso->getFormaHonorarios()->value,
                'percentualAntes' => $percentualAntes,
                'percentualDepois' => $caso->getPercentualHonorarios(),
                'baseAntes' => $baseAntes?->value,
                'baseDepois' => $caso->getBaseHonorarios()?->value,
                'carenciaAntes' => $carenciaAntes,
                'carenciaDepois' => $caso->getCarenciaHonorariosDias(),
                // Só o honorário destas foi recalculado; o exigível (juros/multa/correção) não se moveu.
                'obrigacoesComHonorarioRecalculado' => $recalculadas,
            ],
        );

        // Flush único: caso + obrigações managed + evento numa transação só.
        $this->casoRepository->salvar($caso, true);

        return $caso;
    }
}
