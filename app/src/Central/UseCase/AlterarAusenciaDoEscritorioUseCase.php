<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Entity\Presenca;
use App\Central\Exception\AusenciaRecusada;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Psr\Clock\ClockInterface;

/**
 * O primário escolhe depois de quanto tempo sem atividade a equipe aparece "ausente" (desenho 03 L.306 e L.685; spec
 * `presenca-e-ponto-no-cabecalho.md` §4). Só o primário do escritório (vínculo ativo, conta ativa, perfil de sistema) e
 * só os valores do desenho. Auditado pela entidade.
 */
final class AlterarAusenciaDoEscritorioUseCase
{
    public function __construct(
        private readonly ConfiguracaoDePresencaRepository $configuracoes,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(User $quem, Tenant $tenant, int $minutos): int
    {
        $this->exigirPrimario($quem, $tenant);
        if (!in_array($minutos, Presenca::OPCOES_DE_AUSENCIA, true)) {
            throw new AusenciaRecusada('Escolha um dos tempos da lista.', semPermissao: false);
        }
        $configuracao = $this->configuracoes->doEscritorio($tenant) ?? new ConfiguracaoDePresenca($tenant);
        $configuracao->alterar($minutos, $quem, $this->clock->now());
        $this->configuracoes->salvar($configuracao);

        return $minutos;
    }

    private function exigirPrimario(User $quem, Tenant $tenant): void
    {
        $vinculo = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);
        if ($vinculo === null || !$this->regraDosPrimarios->ehPrimario($vinculo)) {
            throw new AusenciaRecusada('Só o usuário primário do escritório escolhe este tempo.', semPermissao: true);
        }
    }
}
