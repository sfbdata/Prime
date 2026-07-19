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
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Lança uma Obrigação (valor devido) dentro de um Caso de Cobrança (SPEC §10).
 *
 * História: o gestor registra uma pendência (aluguel, mensalidade, parcela, taxa...) num caso do
 * próprio escritório — o caso é resolvido por id + tenant (guarda multi-tenant, invariável 24). Caso
 * encerrado NÃO recebe novas obrigações (SPEC §17): uma nova inadimplência gera um novo caso. O valor
 * e o vencimento entram como ORIGINAIS e são preservados (invariável 20). No caso comum os encargos
 * nascem ZERADOS e passam a ser calculados pelo cron; quem lança uma dívida que já vem com juros/multa/
 * correção de fora (outro sistema, boleto já calculado) pode informá-los, e aí a obrigação nasce
 * CONGELADA — número digitado por gente não é sobrescrito por robô (F4/INV-E4). A obrigação e o evento
 * "obrigação criada" são commitados juntos (flush único no registro do evento).
 */
final class RegistrarObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
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

        // Valor/vencimento originais preservados (invariável 20); encargos nascem zerados por default.
        $obrigacao = new Obrigacao();
        $obrigacao->setTenant($tenant);
        $obrigacao->setCaso($caso);
        $obrigacao->setDescricao(trim((string) $input->descricao));
        $obrigacao->setValorOriginal((int) $input->valorOriginal);
        $obrigacao->setVencimentoOriginal($input->vencimentoOriginal);
        $obrigacao->setReferenciaExterna($this->normalizar($input->referenciaExterna));
        $obrigacao->setCriadoPor($criadoPor);

        // Encargos informados no lançamento (F4): materializa o que o gestor trouxe, e NÃO congela.
        //
        // Congelar aqui — como uma versão anterior fazia, por analogia com a edição — apagava dinheiro
        // do escritório em silêncio: os honorários entram 0 (não são digitados neste form, são
        // calculados pelo motor), e a obrigação congelada sai do cron PARA SEMPRE, sem UI de
        // descongelar. Numa carteira com honorários de 20%, lançar uma dívida de R$ 1.000,00 com juros
        // já calculados fora deixaria os ~R$ 210,00 de honorário sem nunca serem materializados.
        //
        // A spec §8 condiciona o congelamento a EDITAR valores à mão; criar não é editar. Deixando
        // recalculável, o cron completa os honorários na primeira rodada — e o que foi digitado está
        // protegido de encolher pelo freio de redução do próprio cron
        // (`ReducaoDeEncargosBloqueadaException`), que só grava para cima. Quem quiser de fato travar
        // a obrigação edita depois: a edição congela.
        if ($input->juros > 0 || $input->multa > 0 || $input->correcao > 0) {
            $obrigacao->definirEncargos($input->juros, $input->multa, $input->correcao, 0, new \DateTimeImmutable());
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
                // Encargos digitados no lançamento entram no histórico: é dinheiro que nasceu com a
                // obrigação sem passar por nenhum cálculo, e o congelamento que ele dispara precisa
                // ficar explicável depois.
                'juros' => $obrigacao->getJuros(),
                'multa' => $obrigacao->getMulta(),
                'correcao' => $obrigacao->getCorrecao(),
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
