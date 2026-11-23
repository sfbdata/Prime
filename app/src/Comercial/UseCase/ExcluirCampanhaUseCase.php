<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\PedidoDeMarcas;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\CampanhaMarcaRepository;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;
use App\Entity\Auth\User;

/**
 * "Excluir campanha" do menu de uma campanha do Relatório (pedido do Farlei M5.3, 23/11/2026; decisões do Coordenador;
 * spec `comercial-marcas.md`). Compõe dois casos de uso que já existem: se a campanha está ativa (ou `enviada` com envio
 * ainda na fila do Carteiro, como o `cancelavel` do {@see AbrirCampanhaDoRelatorioUseCase}), cancela o envio pelo
 * {@see EncerrarCampanhaUseCase} (mesma trilha `camp_fim`, motivo "Cancelada pelo Master") e a esconde do Relatório pela
 * marca `remover` ({@see CampanhaMarcaRepository}); se já foi enviada ou cancelada, só esconde. Nada é apagado do
 * banco: a trilha e a retenção de 24 meses ficam. Null se a campanha não existe.
 */
final class ExcluirCampanhaUseCase
{
    public const CANCELADA_E_ESCONDIDA = 'Envio cancelado e campanha excluída do relatório.';
    public const ESCONDIDA = 'Campanha excluída do relatório.';

    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly RelatorioDeCampanhasRepository $relatorio,
        private readonly EncerrarCampanhaUseCase $encerrar,
        private readonly MarcarCampanhasDoRelatorioUseCase $marcar,
    ) {
    }

    public function executar(int $id, User $master): ?string
    {
        $c = $this->campanhas->porId($id);
        if ($c === null) {
            return null;
        }
        $cancelou = false;
        if (AbrirCampanhaDoRelatorioUseCase::cancelavel($c['estado'], fn (): bool => $this->relatorio->temEnvioNaFila($id))) {
            try {
                $this->encerrar->executar($id, EncerrarCampanhaUseCase::MOTIVO_DO_MASTER, $master->getId(), $master->getEmail());
                $cancelou = true;
            } catch (CampanhaRecusada) {
                // Terminou de sair (ou foi cancelada) entre a leitura e o cancelamento: não há mais o que parar, só esconder.
            }
        }
        $this->marcar->executar(PedidoDeMarcas::deJson(['ids' => [$id], 'acao' => CampanhaMarcaRepository::REMOVER]), (int) $master->getId());

        return $cancelou ? self::CANCELADA_E_ESCONDIDA : self::ESCONDIDA;
    }
}
