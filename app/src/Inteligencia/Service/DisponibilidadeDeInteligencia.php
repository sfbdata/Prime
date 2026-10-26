<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Service\PermissionChecker;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Feature flag em dois níveis + permissão + cota, na ordem da spec (§3.2, com a ordem nova de ia-anthropic-ligar §10.1):
 *   1. plataforma: `IA_HABILITADA` (parâmetro `ia_habilitada`, a trava de instalação) E provedor configurado E a
 *      chave geral do Master (`inteligencia_plataforma.ligada_para_todos`);
 *   2. escritório: liberado pelo Master (`liberada_pelo_master`; sem linha = não liberado) e depois
 *      `inteligencia_configuracao.habilitada` com o aceite do admin;
 *   3. usuário — `modules.inteligencia.view`;
 *   4. cota — análises do dia e do mês contra os limites do escritório;
 *   5. orçamento: o gasto estimado do mês em US$ ({@see CustoDaIa}) contra `orcamento_mensal_centavos`. Só trava nos
 *      modos `incluso` e `adicional`; em `limite_gratis` o orçamento é a parte grátis e NÃO bloqueia (o painel do
 *      Master mostra "acima do grátis"). Padrão adotado em 24/10/2026, o dono pode rever. Orçamento nulo não trava.
 *
 * O primeiro motivo encontrado é o devolvido: a UI diz exatamente por que o botão não funciona.
 */
final class DisponibilidadeDeInteligencia
{
    public const MODULO = 'inteligencia';

    public function __construct(
        private readonly ProvedorDeLinguagem $provedor,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly PermissionChecker $permissionChecker,
        private readonly bool $iaHabilitada,
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
        private readonly CustoDaIa $custo,
    ) {
    }

    /**
     * Só o 1º nível (plataforma). Quem renderiza tela fora do módulo (a pasta) pergunta isto ANTES de
     * consultar as tabelas da IA: numa instalação sem IA elas podem nem existir (deploy parcial, o
     * entrypoint de prod faz `migrate … || true`). Por isso a trava do ambiente e o provedor vêm
     * antes do banco, e a leitura da chave geral nunca lança (falha = desligada).
     */
    public function plataformaConfigurada(): bool
    {
        return $this->iaHabilitada && $this->provedor->estaConfigurado() && $this->chaveGeralLigada();
    }

    private function chaveGeralLigada(): bool
    {
        try {
            return $this->plataforma->obter()->isLigadaParaTodos();
        } catch (DbalException) {
            return false;
        }
    }

    /**
     * Só os dois primeiros níveis (plataforma → escritório), sem usuário nem cota: a pergunta de quem
     * configura algo que vale para o ESCRITÓRIO ou para a pasta, não para quem está na tela (a chave
     * "Análise da BlueJus IA" do "Avisar quando chegar" do Push). A permissão e a cota continuam com
     * quem pede a análise, na hora do pedido.
     */
    public function paraEscritorio(Tenant $tenant): Disponibilidade
    {
        return $this->plataformaEEscritorio($tenant)[0];
    }

    /**
     * @return array{0: Disponibilidade, 1: ?ConfiguracaoDeInteligencia} a configuração só vem com os
     *                                                                   dois níveis liberados
     */
    private function plataformaEEscritorio(Tenant $tenant): array
    {
        if (!$this->plataformaConfigurada()) {
            return [Disponibilidade::NaoConfiguradaNaPlataforma, null];
        }

        $configuracao = $this->configuracoes->findDoTenant($tenant);
        if ($configuracao === null || !$configuracao->isLiberadaPeloMaster()) {
            return [Disponibilidade::NaoLiberadaPeloMaster, null];
        }

        if (!$configuracao->isHabilitada()) {
            return [Disponibilidade::DesligadaNoEscritorio, null];
        }

        return [Disponibilidade::Disponivel, $configuracao];
    }

    /**
     * Os três primeiros níveis (plataforma → escritório → permissão), sem a cota. É a pergunta do
     * worker antes de chamar o provedor (IA-034L): desligar a IA tem de cortar também o que já está
     * na fila. A cota (e o orçamento) fica de fora porque a análise que o worker vai processar já foi contada na
     * solicitação — reconferir aqui a recusaria por ela mesma no limite exato.
     */
    public function paraProcessamento(User $user, Tenant $tenant): Disponibilidade
    {
        return $this->semCota($user, $tenant)[0];
    }

    /**
     * @return array{0: Disponibilidade, 1: ?ConfiguracaoDeInteligencia} a configuração só vem
     *                                                                   quando os três níveis passaram
     */
    private function semCota(User $user, Tenant $tenant): array
    {
        [$doEscritorio, $configuracao] = $this->plataformaEEscritorio($tenant);
        if ($configuracao === null) {
            return [$doEscritorio, null];
        }

        if (!$this->permissionChecker->canAccessModule($user, $tenant, self::MODULO)) {
            return [Disponibilidade::SemPermissao, null];
        }

        return [Disponibilidade::Disponivel, $configuracao];
    }

    public function para(User $user, Tenant $tenant): Disponibilidade
    {
        [$antesDaCota, $configuracao] = $this->semCota($user, $tenant);
        if ($configuracao === null) {
            return $antesDaCota;
        }

        $hoje = new \DateTimeImmutable('today');
        if ($this->analises->contarDesde($tenant, $hoje) >= $configuracao->getLimiteDiario()) {
            return Disponibilidade::LimiteAtingido;
        }

        $inicioDoMes = new \DateTimeImmutable('first day of this month midnight');
        if ($this->analises->contarDesde($tenant, $inicioDoMes) >= $configuracao->getLimiteMensal()) {
            return Disponibilidade::LimiteMensalAtingido;
        }

        if ($this->orcamentoAtingido($configuracao, $tenant, $inicioDoMes)) {
            return Disponibilidade::OrcamentoAtingido;
        }

        return Disponibilidade::Disponivel;
    }

    /** Sem orçamento ou em modo que não trava, nem soma o gasto. */
    private function orcamentoAtingido(ConfiguracaoDeInteligencia $configuracao, Tenant $tenant, \DateTimeImmutable $inicioDoMes): bool
    {
        $modo = $configuracao->getModoDeCobranca();
        $orcamento = $configuracao->getOrcamentoMensalCentavos();
        if ($orcamento === null || !$modo->travaNoOrcamento()) {
            return false;
        }
        $gasto = $this->custo->centavosDoConsumo($this->analises->somarTokensPorModeloDesde($tenant, $inicioDoMes));

        return $modo->orcamentoAtingido($orcamento, $gasto);
    }
}
