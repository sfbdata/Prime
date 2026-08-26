<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Lança uma Obrigação (valor devido) dentro de um Caso de Cobrança (SPEC §10).
 *
 * História: o gestor registra uma pendência (aluguel, mensalidade, parcela, taxa...) num caso do
 * próprio escritório — o caso é resolvido por id + tenant (guarda multi-tenant, invariável 24). Caso
 * encerrado NÃO recebe novas obrigações (SPEC §17): uma nova inadimplência gera um novo caso. O valor
 * e o vencimento entram como ORIGINAIS e são preservados (invariável 20).
 *
 * Taxa por-obrigação (spec taxa-por-obrigacao): o gestor não digita mais um VALOR de encargo — ele
 * define uma TAXA própria desta obrigação (override por %/R$, `EntradaTaxaEncargos`), traduzida em bp
 * pelo `ConversorTaxaEncargo` e gravada nas quatro colunas de override (`null` = herda a cascata
 * Carteira→Caso→Obrigação). O cache inicial (`juros/multa/correcao/honorarios`) é só a MATERIALIZAÇÃO
 * do motor com a config já resolvida (base do caso + override desta obrigação) para HOJE — nunca a
 * fonte da verdade. Ao vivo (D6): registrar NUNCA congela — a obrigação nasce Viva e a leitura
 * recalcula (vencimento → hoje × taxa) via hidratação; a taxa gravada é o que fixa o comportamento, não
 * o cache. A obrigação e o evento "obrigação criada" são commitados juntos (flush único no evento).
 */
final class RegistrarObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
        private readonly ConversorTaxaEncargo $conversor,
    ) {
    }

    public function executar(RegistrarObrigacaoInput $input, Tenant $tenant, User $criadoPor): Obrigacao
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não recebe novas obrigações (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Valor/vencimento originais preservados (invariável 20); os encargos são materializados logo
        // abaixo (o caso já está setado, então a cascata de config já resolve).
        $obrigacao = new Obrigacao();
        $obrigacao->setTenant($tenant);
        $obrigacao->setCaso($caso);
        $obrigacao->setDescricao(trim((string) $input->descricao));
        $obrigacao->setValorOriginal((int) $input->valorOriginal);
        $obrigacao->setVencimentoOriginal($input->vencimentoOriginal);
        $obrigacao->setReferenciaExterna($this->normalizar($input->referenciaExterna));
        $obrigacao->setCompetencia($input->competencia);
        $obrigacao->setCriadoPor($criadoPor);

        $hoje = new \DateTimeImmutable('today');
        $baseCaso = $this->resolvedor->resolverDoCaso($caso);

        // Grava os overrides de taxa desta obrigação (null = herda o caso). D6: nunca congela.
        $ov = $this->conversor->overrides(
            $input->entradaTaxas(), $baseCaso, (int) $input->valorOriginal, $input->vencimentoOriginal, $hoje);
        $obrigacao
            ->setTaxaJurosMensalBp($ov['taxaJurosMensalBp'])
            ->setTaxaMultaBp($ov['taxaMultaBp'])
            ->setTaxaCorrecaoBp($ov['taxaCorrecaoBp'])
            ->setTaxaHonorariosBp($ov['taxaHonorariosBp']);

        // Cache inicial materializado pelo motor JÁ com o override (a hidratação recalcula na leitura).
        $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);
        $novos = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
        $obrigacao->definirEncargos($novos['juros'], $novos['multa'], $novos['correcao'], $novos['honorarios'], $hoje);

        // Persiste sem flush; o registro do evento fecha a transação (persiste os dois de uma vez).
        $this->obrigacaoRepository->salvar($obrigacao);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ObrigacaoCriada,
            $criadoPor,
            sprintf('Obrigação criada: %s', $obrigacao->getDescricao()),
            [
                'valorOriginal' => $obrigacao->getValorOriginal(),
                'vencimento' => $input->vencimentoOriginal->format('Y-m-d'),
                // Os encargos MATERIALIZADOS na criação entram no histórico (derivam dos getters, que já
                // refletem o cálculo do dia, com o override desta obrigação já aplicado).
                'juros' => $obrigacao->getJuros(),
                'multa' => $obrigacao->getMulta(),
                'correcao' => $obrigacao->getCorrecao(),
                // Honorário materializado (Ajuste 2): ENTRA no exigível desde a spec
                // `cobranca-honorario-no-total.md` (INV-E2 revogada), e é dinheiro editável — o
                // histórico registra com o que a obrigação nasceu para explicar a taxa aplicada depois.
                'honorarios' => $obrigacao->getHonorarios(),
            ],
            flush: true,
        );

        return $obrigacao;
    }

    private function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);

        return $valor !== '' ? $valor : null;
    }
}
