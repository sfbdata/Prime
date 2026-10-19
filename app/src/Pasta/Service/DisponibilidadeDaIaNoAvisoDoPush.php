<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;

/**
 * Se a chave "Análise da BlueJus IA" do "Avisar quando chegar" pode valer neste escritório (spec
 * push-avisar-quando-chegar §2): os dois primeiros níveis da {@see DisponibilidadeDeInteligencia},
 * plataforma e escritório, na mesma ordem e com o mesmo motivo (`Disponibilidade::mensagem()`).
 *
 * Sem o usuário de propósito: a chave é da PASTA, e quem a usa na captação é o responsável. A
 * permissão do módulo e a cota de quem pede ficam com o `SolicitarResumoDoPushUseCase`, na hora.
 * A plataforma é perguntada ANTES de tocar a tabela da IA (numa instalação sem IA ela pode não existir).
 */
final class DisponibilidadeDaIaNoAvisoDoPush
{
    public function __construct(
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
    ) {
    }

    public function doEscritorio(Tenant $tenant): Disponibilidade
    {
        if (!$this->disponibilidade->plataformaConfigurada()) {
            return Disponibilidade::NaoConfiguradaNaPlataforma;
        }

        $configuracao = $this->configuracoes->findDoTenant($tenant);
        if ($configuracao === null || !$configuracao->isHabilitada()) {
            return Disponibilidade::DesligadaNoEscritorio;
        }

        return Disponibilidade::Disponivel;
    }
}
