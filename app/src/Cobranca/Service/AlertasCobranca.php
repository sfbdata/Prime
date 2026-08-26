<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\AlertaCobranca;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Deriva os alertas operacionais de um Caso de Cobrança (SPEC §14, invariável 28: o sistema alerta,
 * o humano decide). Serviço READ-ONLY: nunca persiste, nunca muda estado — apenas lê fatos do caso
 * (obrigações exigíveis, próxima ação, saldo derivado, revisão pendente) e devolve uma lista de
 * `AlertaCobranca` recalculada a cada chamada.
 *
 * Decisões documentadas:
 * - Caso ENCERRADO não gera alertas operacionais (retorna lista vazia): encerrado é estado final,
 *   sem ação pendente que faça sentido cobrar.
 * - Obrigações vencidas são AGREGADAS num único alerta com a contagem na descrição (evita poluir a
 *   tela com um alerta por parcela); o mesmo vale para parcelas de acordo vencidas.
 * - Obrigação JÁ PAGA não gera alerta (07/08). Exigível não quer dizer em aberto: o conjunto de
 *   `doCasoExigiveis` inclui a obrigação quitada e a parcela de acordo CUMPRIDO. Sem esta exclusão o
 *   card contava dívida que a aba "Dívida em aberto" logo abaixo escondia na seção "Já pago" — a mesma
 *   página afirmando duas coisas contrárias. Medido na base com dado de produção: 45 casos com contagem
 *   inflada, 37 deles com o alerta 100% falso (nada em aberto).
 */
final class AlertasCobranca
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
    ) {
    }

    /**
     * Alertas derivados do caso na data de referência `$hoje` (injetável para testes determinísticos;
     * default = agora). Cada condição verdadeira vira um `AlertaCobranca`.
     *
     * @return AlertaCobranca[]
     */
    public function alertasDoCaso(CasoCobranca $caso, ?\DateTimeImmutable $hoje = null): array
    {
        // Caso encerrado é estado final: não faz sentido alertar sobre ações operacionais.
        if ($caso->estaEncerrado()) {
            return [];
        }

        $hoje ??= new \DateTimeImmutable();

        // Carga por-caso (usada pelo detalhe do caso; mantém o N+1 aceitável de 1 caso só).
        $exigiveis = $this->obrigacaoRepository->doCasoExigiveis($caso);
        $acaoAtiva = $this->proximaAcaoRepository->findAtivaDoCaso($caso);
        // ORDEM IMPORTA: `saldoExigivel` hidrata os encargos VIVOS nas MESMAS entidades (identity map do
        // Doctrine) que `$exigiveis` guarda. Só depois dele `valorExigivel()` responde o valor de hoje —
        // e é contra esse valor que `vencidaEmAberto` decide se a obrigação está paga.
        $saldoExigivel = $this->calculadoraSaldo->saldoExigivel($caso);

        return $this->montarAlertas(
            $exigiveis,
            $this->alocadoDoCaso($caso),
            $acaoAtiva,
            $saldoExigivel,
            $hoje,
        );
    }

    /**
     * Como `alertasDoCaso`, mas reaproveitando o `saldoExigivel`, a ação ativa e o mapa de alocação que o
     * chamador JÁ computou (dedupe — evita recalcular o saldo, re-buscar a ação e repetir a soma de
     * alocação dentro deste serviço). Usado pelo Detalhe do Caso (`MontarDetalheCasoUseCase`), que precisa
     * dos três para o cabeçalho, a próxima ação e a aba Dívida. Mesma regra de `alertasDoCaso` (via
     * `montarAlertas`); caso encerrado → `[]`. `$hoje` injetável.
     *
     * ⚠️ CONTRATO DO CHAMADOR (o único método aqui que NÃO garante isto sozinho): as obrigações do caso
     * já devem estar HIDRATADAS por `EncargosVivos` quando esta chamada acontece. `alertasDoCaso` e
     * `alertasDosCasos` hidratam por conta própria (rodam o `CalculadoraSaldo` antes de comparar); este
     * recebe o saldo PRONTO e nunca chama a calculadora, então herda a hidratação de quem o chama —
     * `MontarDetalheCasoUseCase` hidrata na primeira linha, antes de qualquer agregação. Se um chamador
     * futuro não hidratar, `valorExigivel()` devolve o snapshot persistido (MENOR que o vivo) e uma
     * obrigação parcialmente paga passa por paga: o alerta legítimo some, em silêncio, num caminho de
     * dinheiro. Ver a pré-condição em `vencidaEmAberto`.
     *
     * @param array<int, int> $alocadoPorObrigacao obrigacaoId => Σ alocado (centavos)
     *
     * @return AlertaCobranca[]
     */
    public function alertasComContexto(
        CasoCobranca $caso,
        int $saldoExigivel,
        ?ProximaAcao $acaoAtiva,
        array $alocadoPorObrigacao,
        ?\DateTimeImmutable $hoje = null,
    ): array {
        if ($caso->estaEncerrado()) {
            return [];
        }

        $hoje ??= new \DateTimeImmutable();

        return $this->montarAlertas(
            $this->obrigacaoRepository->doCasoExigiveis($caso),
            $alocadoPorObrigacao,
            $acaoAtiva,
            $saldoExigivel,
            $hoje,
        );
    }

    /**
     * Versão em LOTE de `alertasDoCaso` para a visão tenant-wide (Central de Alertas, Etapa 9) — evita o
     * N+1 de ~6 queries por caso. Carrega de UMA vez, para todos os casos: obrigações exigíveis, ações
     * pendentes, revisões pendentes e os saldos (via `CalculadoraSaldo::saldosDosCasos`), e deriva os
     * MESMOS alertas por caso via `montarAlertas` — mesma regra de `alertasDoCaso`, que fica intacto.
     * Caso encerrado → `[]` (idêntico). Tenant SEMPRE explícito nas cargas; `$casos` vazio → `[]`.
     * `$hoje` injetável (default = agora).
     *
     * @param CasoCobranca[] $casos
     *
     * @return array<int, AlertaCobranca[]> casoId => alertas
     */
    public function alertasDosCasos(array $casos, Tenant $tenant, ?\DateTimeImmutable $hoje = null): array
    {
        if ($casos === []) {
            return [];
        }

        $hoje ??= new \DateTimeImmutable();

        $casoIds = [];
        foreach ($casos as $caso) {
            $id = $caso->getId();
            if ($id !== null) {
                $casoIds[] = $id;
            }
        }

        $exigiveisPorCaso = [];
        foreach ($this->obrigacaoRepository->exigiveisDosCasos($casoIds, $tenant) as $obrigacao) {
            $casoId = $obrigacao->getCaso()?->getId();
            if ($casoId !== null) {
                $exigiveisPorCaso[$casoId][] = $obrigacao;
            }
        }

        $acoesPorCaso = $this->proximaAcaoRepository->ativasDosCasos($casoIds, $tenant);
        // ORDEM IMPORTA (mesma razão de `alertasDoCaso`): `saldosDosCasos` hidrata os encargos vivos nas
        // MESMAS entidades que `$exigiveisPorCaso` guarda, antes de qualquer comparação de valor.
        $saldosPorCaso = $this->calculadoraSaldo->saldosDosCasos($casos, $tenant, $hoje);
        // UMA query para o lote todo, e não uma por caso: seria o N+1 que esta versão em lote existe para
        // evitar. Sem filtro de carteira no request (o caminho comum — o card do painel linka para a
        // Central SEM carteira), `$casoIds` é o tenant inteiro: 248 casos no dado de produção.
        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos($casoIds, $tenant);

        $resultado = [];
        foreach ($casos as $caso) {
            $casoId = $caso->getId() ?? 0;

            // Encerrado é estado final: sem alertas operacionais (mesma decisão de `alertasDoCaso`).
            if ($caso->estaEncerrado()) {
                $resultado[$casoId] = [];

                continue;
            }

            $resultado[$casoId] = $this->montarAlertas(
                $exigiveisPorCaso[$casoId] ?? [],
                $alocadoPorObrigacao,
                $acoesPorCaso[$casoId] ?? null,
                $saldosPorCaso[$casoId]['exigivel'] ?? 0,
                $hoje,
            );
        }

        return $resultado;
    }

    /**
     * A régua ÚNICA de "vencida e ainda em aberto" — o predicado que decide se uma obrigação exigível
     * merece alerta. Público de propósito: o Dashboard (`MontarDashboardCobrancaUseCase`) agrega em lote
     * e não pode chamar `alertasDosCasos`, mas TEM de contar pelo mesmo critério; antes ele repetia a
     * regra à mão e a cópia ficou para trás quando esta mudou, fazendo o card do painel discordar da
     * lista que ele abre.
     *
     * Duas condições, nesta ordem:
     * 1. venceu (`vencimentoOriginal <= hoje`);
     * 2. o Σ alocado NÃO cobre o `valorExigivel()`. Exigível não quer dizer em aberto: o conjunto de
     *    `doCasoExigiveis` inclui a obrigação quitada e a parcela de acordo CUMPRIDO.
     *
     * A régua do item 2 é a MESMA da aba "Dívida em aberto" (`ObrigacaoOutput::quitada()`: alocado >=
     * valorAtual, sendo `valorAtual` o `valorExigivel()` — que INCLUI honorários desde a spec
     * `cobranca-honorario-no-total.md`, INV-E2 revogada), de
     * propósito: com duas réguas a página volta a ter duas definições de "paga" discordando entre si,
     * que é exatamente o defeito que isto corrige.
     *
     * ⚠️ DUAS PRÉ-CONDIÇÕES, e as duas falham do MESMO jeito — calando um alerta legítimo em silêncio,
     * sem erro nem rastro, num caminho de dinheiro:
     * 1. **Hidratação.** A obrigação já deve ter passado por `EncargosVivos` (o chamador roda
     *    `CalculadoraSaldo::saldoExigivel`/`saldosDosCasos`, ou hidrata ele mesmo, ANTES). Sem isso
     *    `valorExigivel()` devolve o snapshot persistido, MENOR que o vivo, e uma obrigação
     *    parcialmente paga passa por paga.
     * 2. **Tenant.** `$alocadoPorObrigacao` tem de vir de carga TENANT-SCOPED
     *    (`AlocacaoPagamentoRepository::somasPorObrigacaoDosCasos`, que filtra alocação E obrigação).
     *    As chaves são PK GLOBAIS de obrigação: um mapa montado sem o filtro faria alocação de outro
     *    escritório quitar dívida deste.
     *
     * @param array<int, int> $alocadoPorObrigacao obrigacaoId => Σ alocado (centavos)
     */
    public function vencidaEmAberto(
        Obrigacao $obrigacao,
        array $alocadoPorObrigacao,
        \DateTimeImmutable $hoje,
    ): bool {
        if ($obrigacao->getVencimentoOriginal() > $hoje) {
            return false;
        }

        $id = $obrigacao->getId();

        return $id === null || ($alocadoPorObrigacao[$id] ?? 0) < $obrigacao->valorExigivel();
    }

    /**
     * Σ alocado por obrigação DESTE caso (mapa `obrigacaoId => centavos`). Caso ainda sem id ou sem tenant
     * não tem alocação para buscar — devolve `[]` sem query, como fazem `somasPorObrigacaoDosCasos` e o
     * guard de tenant do `CalculadoraSaldo::saldoExigivel`.
     *
     * @return array<int, int>
     */
    private function alocadoDoCaso(CasoCobranca $caso): array
    {
        $casoId = $caso->getId();
        $tenant = $caso->getTenant();

        if ($casoId === null || $tenant === null) {
            return [];
        }

        return $this->alocacaoRepository->somasPorObrigacaoDosCasos([$casoId], $tenant);
    }

    /**
     * Regra ÚNICA de derivação dos alertas operacionais, a partir de fatos JÁ RESOLVIDOS do caso (SPEC §14).
     * Fonte compartilhada por `alertasDoCaso` (per-caso) e `alertasDosCasos` (lote) — nenhuma regra duplicada.
     *
     * @param Obrigacao[]     $exigiveis           obrigações EXIGÍVEIS do caso
     * @param array<int, int> $alocadoPorObrigacao obrigacaoId => Σ alocado (centavos); pode conter
     *                                             obrigações de OUTROS casos do lote (a carga é por lote,
     *                                             não por caso); as chaves são PK de obrigação, globais
     *
     * @return AlertaCobranca[]
     */
    private function montarAlertas(
        array $exigiveis,
        array $alocadoPorObrigacao,
        ?ProximaAcao $acaoAtiva,
        int $saldoExigivel,
        \DateTimeImmutable $hoje,
    ): array {
        $alertas = [];
        $vencidas = 0;
        $parcelasVencidas = 0;

        foreach ($exigiveis as $obrigacao) {
            // Vencida E ainda em aberto — regra em `vencidaEmAberto`, compartilhada com o Dashboard.
            // Alertar sobre dinheiro que já entrou manda o gestor procurar o que não existe.
            if (!$this->vencidaEmAberto($obrigacao, $alocadoPorObrigacao, $hoje)) {
                continue;
            }

            ++$vencidas;

            // Parcela de acordo vencida: obrigação vencida cujo acordo de origem existe (SPEC §12).
            if ($obrigacao->getAcordoOrigem() !== null) {
                ++$parcelasVencidas;
            }
        }

        if ($vencidas > 0) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::ObrigacaoVencida,
                sprintf('%d obrigação(ões) exigível(is) vencida(s) a verificar.', $vencidas),
            );
        }

        if ($parcelasVencidas > 0) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::ParcelaAcordoVencida,
                sprintf('%d parcela(s) de acordo vencida(s).', $parcelasVencidas),
            );
        }

        if ($acaoAtiva !== null && $acaoAtiva->estaAtrasada($hoje)) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::AcaoAtrasada,
                'Próxima ação com prazo vencido.',
            );
        }

        // Saldo exigível zero (e caso não encerrado): pronto para o gestor decidir encerrar (SPEC §14).
        if ($saldoExigivel === 0) {
            $alertas[] = new AlertaCobranca(
                TipoAlerta::ProntoParaEncerrar,
                'Saldo exigível zerado: pronto para encerrar.',
            );
        }

        return $alertas;
    }
}
