<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Service\PermissionChecker;

/**
 * Feature flag em dois níveis + permissão + cota, na ordem da spec (§3.2):
 *   1. plataforma — `IA_HABILITADA` (parâmetro `ia_habilitada`) E provedor configurado;
 *   2. escritório — `inteligencia_configuracao.habilitada` do tenant (sem linha = desligada);
 *   3. usuário — `modules.inteligencia.view`;
 *   4. cota — análises do dia e do mês contra os limites do escritório.
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
    ) {
    }

    /**
     * Só o 1º nível (plataforma), sem tocar o banco. Quem renderiza tela fora do módulo (a pasta)
     * pergunta isto ANTES de consultar as tabelas da IA: numa instalação sem IA elas podem nem
     * existir (deploy parcial — o entrypoint de prod faz `migrate … || true`).
     */
    public function plataformaConfigurada(): bool
    {
        return $this->iaHabilitada && $this->provedor->estaConfigurado();
    }

    /**
     * Os três primeiros níveis (plataforma → escritório → permissão), sem a cota. É a pergunta do
     * worker antes de chamar o provedor (IA-034L): desligar a IA tem de cortar também o que já está
     * na fila. A cota fica de fora porque a análise que o worker vai processar já foi contada na
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
        if (!$this->plataformaConfigurada()) {
            return [Disponibilidade::NaoConfiguradaNaPlataforma, null];
        }

        $configuracao = $this->configuracoes->findDoTenant($tenant);
        if ($configuracao === null || !$configuracao->isHabilitada()) {
            return [Disponibilidade::DesligadaNoEscritorio, null];
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

        return Disponibilidade::Disponivel;
    }
}
