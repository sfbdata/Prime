<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
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

    public function para(User $user, Tenant $tenant): Disponibilidade
    {
        if (!$this->plataformaConfigurada()) {
            return Disponibilidade::NaoConfiguradaNaPlataforma;
        }

        $configuracao = $this->configuracoes->findDoTenant($tenant);
        if ($configuracao === null || !$configuracao->isHabilitada()) {
            return Disponibilidade::DesligadaNoEscritorio;
        }

        if (!$this->permissionChecker->canAccessModule($user, $tenant, self::MODULO)) {
            return Disponibilidade::SemPermissao;
        }

        $hoje = new \DateTimeImmutable('today');
        if ($this->analises->contarDesde($tenant, $hoje) >= $configuracao->getLimiteDiario()) {
            return Disponibilidade::LimiteAtingido;
        }

        $inicioDoMes = new \DateTimeImmutable('first day of this month midnight');
        if ($this->analises->contarDesde($tenant, $inicioDoMes) >= $configuracao->getLimiteMensal()) {
            return Disponibilidade::LimiteAtingido;
        }

        return Disponibilidade::Disponivel;
    }
}
