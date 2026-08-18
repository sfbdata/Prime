<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoJaSubstituidaException;
use App\Cobranca\Exception\ObrigacaoNaoEhDividaOriginalException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ParcelamentoInvalidoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cria um Acordo num Caso de Cobrança (SPEC §12): substitui uma ou mais obrigações do MESMO caso
 * (invariável 13) por novas obrigações (parcelas).
 *
 * História: o gestor renegocia a dívida — escolhe as obrigações que serão substituídas e define as
 * parcelas do acordo. O caso é resolvido por id + tenant (guarda multi-tenant); caso encerrado NÃO
 * aceita acordos (SPEC §17). As obrigações substituídas NUNCA são apagadas (invariável 14) — só
 * marcadas com `acordoSubstituto` —; a substituição PARCIAL é permitida. As parcelas nascem como
 * novas obrigações do caso, geradas pelo acordo (`acordoOrigem`). NENHUMA reversão de saldo é feita
 * aqui: a CalculadoraSaldo deriva o exigível a partir do estado do acordo (invariável 20). O acordo,
 * as marcações, as parcelas e o evento "acordo criado" são commitados juntos (flush único no evento).
 */
final class CriarAcordoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
    ) {
    }

    public function executar(CriarAcordoInput $input, Tenant $tenant, User $criadoPor): Acordo
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não aceita acordos (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Ajuste 7 / INV-B: total negociado = entrada + Σ parcelas. Quando o gerador inteligente
        // informa o total, o servidor revalida o fechamento (defesa além do Assert\Callback do form).
        $valorEntrada = max(0, (int) $input->valorEntrada);
        $somaParcelas = array_sum(array_map(static fn ($parcela): int => (int) $parcela->valor, $input->parcelas));
        $totalNegociado = $valorEntrada + $somaParcelas;

        if ($input->valorTotalNegociado !== null && $input->valorTotalNegociado !== $totalNegociado) {
            throw ParcelamentoInvalidoException::totalNegociadoDivergente($totalNegociado, $input->valorTotalNegociado);
        }

        // 🔒 O CAMINHO MANUAL CONTINUA EXIGINDO A DATA (decisão do dono, 17/08).
        //
        // A regra do espelho ("não inventar o que a fonte não deu") governa o que vem da CONTABILIDADE.
        // Aqui a fonte é a pessoa que está lavrando o acordo na tela, e ela sabe a data — afrouxar abriria
        // porta para dado incompleto por descuido, sem ganho nenhum de fidelidade.
        //
        // A guarda é NOVA e obrigatória: até 17/08 `setDataAcordo()` recusava `null` por tipo, e o
        // `#[Assert\NotNull]` do input era a segunda linha de defesa. Com a coluna anulável o setter
        // aceita `null` calado, e um input malformado criaria um acordo manual sem data — que só
        // explodiria adiante, na materialização, com erro sem relação com a causa.
        $dataAcordo = $input->dataAcordo;
        if ($dataAcordo === null) {
            throw new \InvalidArgumentException('Acordo lavrado na tela exige data — só o importe grava acordo sem data.');
        }

        // Status ativo é o default da entidade.
        $acordo = new Acordo();
        $acordo->setTenant($tenant);
        $acordo->setCaso($caso);
        $acordo->setDataAcordo($dataAcordo);
        $acordo->setCriadoPor($criadoPor);
        // Snapshot da negociação (descritivo; saldo segue derivado): total sempre populado (deriva se null).
        $acordo->setValorTotalNegociado($totalNegociado);
        $acordo->setValorEntrada($valorEntrada);

        // Config do caso resolvida 1× — o snapshot de cada substituída Viva usa a MESMA config da
        // hidratação ao vivo, para o valor congelado bater com o que a linha mostrava até a véspera.
        $configCaso = $this->resolvedorConfig->resolverDoCaso($caso);

        foreach ($input->obrigacoesSubstituidasIds as $obrigacaoId) {
            $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant((int) $obrigacaoId, $tenant);

            if ($obrigacao === null) {
                throw new ObrigacaoNaoEncontradaException((int) $obrigacaoId);
            }

            // Invariável 13: o acordo só substitui obrigações do MESMO caso — identidade de instância.
            if ($obrigacao->getCaso() !== $caso) {
                throw new ObrigacaoDeOutroCasoException((int) $obrigacaoId, (int) $caso->getId());
            }

            // Só bloqueia se a obrigação está substituída por um acordo VIGENTE (ativo/cumprido).
            // Se o acordo substituto foi rompido/cancelado, a obrigação voltou ao exigível e PODE ser
            // renegociada de novo (SPEC §12 — "ainda não substituída por um acordo vigente").
            $substitutoAtual = $obrigacao->getAcordoSubstituto();

            if ($substitutoAtual !== null && $substitutoAtual->getStatus()->ehVigente()) {
                throw new ObrigacaoJaSubstituidaException((int) $obrigacaoId);
            }

            // INV-I (ajuste 9): um acordo só substitui DÍVIDA ORIGINAL — parcela gerada por outro acordo
            // nunca é substituível. Aceitá-la duplicaria a dívida no saldo assim que o acordo de origem
            // fosse rompido/cancelado: a original que ele substituiu volta ao exigível E as parcelas do
            // acordo novo continuam nele. Renegociar um acordo se faz rompendo-o primeiro (a original
            // volta por derivação) e acordando sobre a original. Vale para acordo de origem em QUALQUER
            // status: se rompido/cancelado, a parcela nem é exigível.
            //
            // ⚠️ 08/2026: a IMPORTAÇÃO passou a aceitar isto, e aqui **continua recusado de propósito**
            // (decisão do dono, spec `cobranca-acordo-assume-parcelas-do-anterior.md` D2). O que autoriza
            // lá é a prova documental — a coluna "Detalhamento" da planilha declarando
            // `Acordo 163 - Parcela 4/12`. Na tela não existe prova nenhuma, só a intenção de quem
            // clicou: a assimetria é o desenho, não um esquecimento de alinhar os dois caminhos.
            if ($obrigacao->getAcordoOrigem() !== null) {
                throw new ObrigacaoNaoEhDividaOriginalException((int) $obrigacaoId);
            }

            // Encargos AO VIVO (spec §4/§5): ao ser substituída, o relógio da obrigação PARA na data do
            // acordo — materializa o snapshot dos encargos calculados em `dataAcordo`. Só as VIVAS: uma já
            // congelada (Liquidada/legado) mantém o snapshot que já tinha. A substituída sai do exigível
            // (`doCasoExigiveis` a exclui) e não é hidratada, então o snapshot é o que a tela exibe; se o
            // acordo for rompido, ela volta ao exigível e recomeça a crescer ao vivo (sem precisar
            // descongelar, pois não foi congelada — só materializada).
            //
            // Por OBRIGAÇÃO, aplica-se o overlay do 3º nível da cascata (taxa por-obrigação) sobre a
            // base do caso ANTES de calcular — mesmo padrão do `EncargosVivos`/hidratação ao vivo: senão
            // o snapshot congelado divergiria da taxa que a tela mostrava até a véspera.
            if (!$obrigacao->encargosCongelados()) {
                $config = $this->resolvedorConfig->aplicarObrigacao($configCaso, $obrigacao);
                $e = $this->calculadora->calcular(
                    $obrigacao->getValorOriginal(),
                    $obrigacao->getVencimentoOriginal(),
                    $config,
                    $dataAcordo,
                );
                $obrigacao->definirEncargos($e['juros'], $e['multa'], $e['correcao'], $e['honorarios'], $dataAcordo);
            }

            // Marca (nunca apaga — invariável 14); já gerenciada, salvar sem flush por clareza.
            $obrigacao->setAcordoSubstituto($acordo);
            $this->obrigacaoRepository->salvar($obrigacao);
        }

        // Entrada (Ajuste 7): quando há, vira a 1ª obrigação do acordo — parcela como as demais, para
        // entrar no saldo derivado e ser quitável pelo fluxo de pagamento. Sem entrada, nada é criado.
        if ($valorEntrada > 0) {
            $obrigacaoEntrada = new Obrigacao();
            $obrigacaoEntrada->setTenant($tenant);
            $obrigacaoEntrada->setCaso($caso);
            $obrigacaoEntrada->setDescricao('Entrada');
            $obrigacaoEntrada->setValorOriginal($valorEntrada);
            $obrigacaoEntrada->setVencimentoOriginal($input->dataEntrada ?? $dataAcordo);
            $obrigacaoEntrada->setAcordoOrigem($acordo);
            $obrigacaoEntrada->setCriadoPor($criadoPor);

            $this->obrigacaoRepository->salvar($obrigacaoEntrada);
        }

        foreach ($input->parcelas as $parcela) {
            $novaObrigacao = new Obrigacao();
            $novaObrigacao->setTenant($tenant);
            $novaObrigacao->setCaso($caso);
            $novaObrigacao->setDescricao(trim((string) $parcela->descricao));
            $novaObrigacao->setValorOriginal((int) $parcela->valor);
            $novaObrigacao->setVencimentoOriginal($parcela->vencimento);
            $novaObrigacao->setAcordoOrigem($acordo);
            $novaObrigacao->setCriadoPor($criadoPor);

            // Persiste sem flush; o registro do evento fecha a transação.
            $this->obrigacaoRepository->salvar($novaObrigacao);
        }

        // Persiste sem flush; o registro do evento fecha a transação (persiste tudo de uma vez).
        $this->acordoRepository->salvar($acordo);

        $substituidas = count($input->obrigacoesSubstituidasIds);
        $parcelas = count($input->parcelas);
        $temEntrada = $valorEntrada > 0;

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::AcordoCriado,
            $criadoPor,
            sprintf(
                'Acordo criado: %d obrigação(ões) substituída(s) por %d parcela(s)%s',
                $substituidas,
                $parcelas,
                $temEntrada ? ' + entrada' : '',
            ),
            [
                'substituidas' => $substituidas,
                'parcelas' => $parcelas,
                'entrada' => $valorEntrada,
                'total_negociado' => $totalNegociado,
            ],
            flush: true,
        );

        return $acordo;
    }
}
