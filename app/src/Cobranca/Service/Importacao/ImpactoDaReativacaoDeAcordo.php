<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Mede — e NUNCA corrige — o efeito no dinheiro de reativar um acordo por importação (spec
 * `cobranca-importar-acordos-situacao.md` §5.1; decisão D6 da spec de receitas: *"o importe é a
 * verdade absoluta"*).
 *
 * Vive aqui, e não dentro de um importador, porque **os dois importadores tomam a MESMA decisão de
 * dinheiro**: o de Receitas reativa pela coluna J, o de Acordos detalhados reativa pela linha
 * `Situação:`. O `ImportarAcordosDetalhadosUseCase` já registra, no próprio código (`:98-104`), o preço
 * de manter duas implementações de uma decisão só — *"ali as duas cópias já divergiram uma vez"*. Este
 * método carrega quatro lições cicatrizadas nos comentários abaixo; copiá-las é garantir que uma das
 * cópias perca uma.
 */
final class ImpactoDaReativacaoDeAcordo
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
    ) {
    }

    /**
     * Fotografa, ANTES de qualquer escrita, o dinheiro que a reativação tiraria do exigível — um acordo
     * por número, mesmo que ele apareça em 40 linhas do arquivo.
     *
     * Roda no início da prévia E no início da confirmação, sobre o banco intocado. É o que impede que o
     * número da confirmação inclua alocações criadas pela própria execução, divergindo da prévia
     * (`feedback_previa_precisa_de_estado`).
     *
     * Devolve entrada para TODO número pedido — `[[], []]` quando o acordo não existe ou já é vigente —
     * para que o chamador não precise distinguir "não medido" de "medido em zero".
     *
     * @param list<int> $numerosExternos
     *
     * @return array<int, array{0: list<string>, 1: list<string>}> número do acordo => [dinheiro parado, impacto]
     */
    public function mapearPorNumeroExterno(Carteira $carteira, array $numerosExternos, Tenant $tenant): array
    {
        $mapa = [];
        foreach ($numerosExternos as $numero) {
            if (array_key_exists($numero, $mapa)) {
                continue;
            }

            $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($numero, $carteira, $tenant);
            $mapa[$numero] = $acordo !== null && !$acordo->getStatus()->ehVigente()
                ? $this->medir($acordo, $tenant)
                : [[], []];
        }

        return $mapa;
    }

    /**
     * ⚠️ O efeito colateral de D6 que a spec-cancelar §3.2 manda vigiar, medido e REPORTADO — nunca
     * corrigido em silêncio.
     *
     * Reativar o acordo devolve as obrigações originais ao estado "substituída", e elas saem do
     * exigível. `CalculadoraSaldo` só abate alocação de obrigação exigível — então o dinheiro que
     * alguém tenha recebido numa original enquanto o acordo estava rompido **para de abater o saldo**,
     * e o devedor volta a ser cobrado por algo que já pagou. É o pior erro possível neste domínio.
     *
     * Decidir para onde esse dinheiro vai não é do importador. Ele mede e põe na frente de quem
     * confirma.
     *
     * ⚠️ Devolve DOIS canais separados, e a separação é o ponto. Uma versão anterior misturava os dois
     * numa lista só, e o texto do aviso — "o devedor passa a ser cobrado por algo que já pagou" —
     * disparava também quando NINGUÉM tinha pagado nada. Alarme falso em aviso de dinheiro é pior do
     * que aviso nenhum: ensina o operador a ignorar.
     *
     * ⚠️ Os valores vêm do SNAPSHOT gravado, não do recálculo ao vivo. Uma original que voltou ao
     * exigível por rompimento antigo cresceu desde então, e o aviso subestima o quanto ela pesa. É
     * ordem de grandeza para segurar a mão de quem confirma, não número de fechamento.
     *
     * @return array{0: list<string>, 1: list<string>} [dinheiro já pago que para de abater, impacto no saldo]
     */
    public function medir(Acordo $acordo, Tenant $tenant): array
    {
        $substituidas = $this->obrigacaoRepository->substituidasPorAcordo($acordo, $tenant);
        if ($substituidas === []) {
            return [[], []];
        }

        $ids = [];
        foreach ($substituidas as $obrigacao) {
            $id = $obrigacao->getId();
            if ($id !== null) {
                $ids[$id] = $obrigacao;
            }
        }

        // ⚠️ Havia aqui um `totalAlocadoEmObrigacoes` no conjunto inteiro, com return antecipado quando
        // dava zero. Foi REMOVIDO: era redundante com a checagem por obrigação abaixo e punha duas
        // defesas em SÉRIE, o que torna o teste do caso negativo improvável — relaxar só uma das duas
        // deixava a suíte verde, que é como um assert vacuoso nasce. Uma defesa, uma prova.
        //
        // QUERY, e não a coleção inversa: `Acordo::$obrigacoesSubstituidas` nasce vazia na mesma
        // unidade de trabalho, e este é caminho de dinheiro.
        $dinheiroParado = [];
        $saldoQueSaiLiquido = 0;
        foreach ($ids as $id => $obrigacao) {
            // ⚠️ A régua é EXISTIR alocação, não ser positiva. `AlocacaoPagamentoRepository:70-71`
            // registra que `total > 0` deixaria passar uma alocação de valor ZERO — que é uma linha de
            // pagamento real, com histórico —, e a etapa 2 cria 10 delas. É a mesma régua que o
            // `CancelarAcordoUseCase` usa para responder à mesma pergunta.
            $temAlocacao = $this->alocacaoRepository->existeAlocacaoEmObrigacoes([$id], $tenant);
            $alocado = $temAlocacao ? $this->alocacaoRepository->totalAlocadoEmObrigacoes([$id], $tenant) : 0;

            // O EFEITO no saldo é o exigível MENOS o que já foi alocado. Somar o exigível bruto (como
            // uma versão anterior fazia) imprimia R$ 500,00 onde o saldo se move R$ 350,00: um número
            // certo respondendo a outra pergunta.
            //
            // ⚠️ O piso em zero é convenção deste aviso, e NÃO espelha a `CalculadoraSaldo`, que soma
            // sem piso por obrigação (`CalculadoraSaldo:175`; o `max(0)` de lá é do vencido). Quando o
            // alocado passa do exigível — caso que a §9.3 da spec-mãe declara esperado — o saldo se
            // move para o outro lado e este aviso mostra 0. É aviso de ordem de grandeza, não extrato.
            $saldoQueSaiLiquido += max(0, $obrigacao->valorExigivel() - $alocado);

            if (!$temAlocacao) {
                continue;
            }

            $dinheiroParado[] = sprintf(
                'Acordo %d: a obrigação "%s" (NN %s) tem R$ %s alocados e sai do exigível com a reativação',
                (int) $acordo->getNumeroExterno(),
                $obrigacao->getDescricao(),
                $obrigacao->getReferenciaExterna() ?? '—',
                number_format($alocado / 100, 2, ',', '.'),
            );
        }

        $impacto = [];
        if ($saldoQueSaiLiquido > 0) {
            $impacto[] = sprintf(
                'Acordo %d: %d obrigação(ões) originais saem do exigível — o saldo devedor se move em R$ %s',
                (int) $acordo->getNumeroExterno(),
                count($ids),
                number_format($saldoQueSaiLiquido / 100, 2, ',', '.'),
            );
        }

        return [$dinheiroParado, $impacto];
    }
}
