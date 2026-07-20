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
 * Encargos "estilo planilha" (F6, TODAY()): a obrigação já NASCE com os encargos calculados para HOJE
 * pela cascata Carteira→Caso→Obrigação, em vez de zero esperando o cron. Se o gestor DIGITA juros/multa/
 * correção (dívida vinda de outro sistema, boleto já calculado), esses três são a verdade e a obrigação
 * nasce CONGELADA — o motor apenas COMPLETA os honorários sobre a base digitada (não há campo p/ eles),
 * e a partir daí número digitado por gente não é sobrescrito por robô (INV-E4). Sem digitação, a
 * obrigação é AUTOMÁTICA: nasce com os juros do dia e segue recalculável pelo cron. A obrigação e o
 * evento "obrigação criada" são commitados juntos (flush único no registro do evento).
 */
final class RegistrarObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
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
        $obrigacao->setCriadoPor($criadoPor);

        // Encargos "estilo planilha" (F6): a obrigação nasce com os encargos do DIA. A cascata
        // Carteira→Caso→Obrigação define as taxas (o caso já está setado); a referência é HOJE.
        $hoje = new \DateTimeImmutable('today');
        $config = $this->resolvedor->resolver($obrigacao);

        // Encargos AO VIVO (D6): a obrigação nasce VIVA e NUNCA congela ao registrar — o encargo é sempre
        // recalculado (vencimento → hoje × taxa) na leitura, via hidratação. Um valor de encargo digitado
        // no cadastro é apenas um ponto de partida (cache), não um override que fixe a obrigação: no modelo
        // ao vivo, para mudar a taxa edita-se a config do caso/carteira (override por-obrigação é
        // follow-up, §11). O honorário, quando não informado (`null`), é completado pelo motor sobre a
        // base digitada; quando digitado, é usado como está no cache inicial.
        $digitou = $input->juros > 0 || $input->multa > 0 || $input->correcao > 0 || $input->honorarios !== null;

        if ($digitou) {
            $dias = $this->calculadora->diasDeAtraso($input->vencimentoOriginal, $hoje);
            $honorarios = $input->honorarios ?? $this->calculadora->honorarios(
                (int) $input->valorOriginal,
                $input->juros,
                $input->multa,
                $input->correcao,
                $config,
                $dias,
            );
            $obrigacao->definirEncargos($input->juros, $input->multa, $input->correcao, $honorarios, $hoje);
        } else {
            $novos = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
            $obrigacao->definirEncargos($novos['juros'], $novos['multa'], $novos['correcao'], $novos['honorarios'], $hoje);
        }

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
                // refletem o cálculo do dia). Quando o gestor digitou, é dinheiro que nasceu com a
                // obrigação e o congelamento que ele dispara precisa ficar explicável depois.
                'juros' => $obrigacao->getJuros(),
                'multa' => $obrigacao->getMulta(),
                'correcao' => $obrigacao->getCorrecao(),
                // Honorário materializado (Ajuste 2): fica FORA do exigível, mas é dinheiro editável — o
                // histórico registra com o que a obrigação nasceu para explicar override/congelamento depois.
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
