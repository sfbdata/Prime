<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Entity\Presenca;
use App\Central\Exception\AusenciaRecusadaException;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * O primário escolhe depois de quanto tempo sem atividade a equipe aparece "ausente" (desenho 03 L.306 e L.685; spec
 * `presenca-e-ponto-no-cabecalho.md` §4). Só o primário do escritório (vínculo ativo, conta ativa, perfil de sistema) e
 * só os valores do desenho. Auditado pela entidade.
 * A mudança vale para quem carregar a página depois (o limite chega ao navegador no `data-inativo-apos-min`).
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

    /** @throws AusenciaRecusadaException */
    public function executar(User $quem, Tenant $tenant, int $minutos): int
    {
        $this->exigirPrimario($quem, $tenant);
        if (!in_array($minutos, Presenca::OPCOES_DE_AUSENCIA, true)) {
            throw AusenciaRecusadaException::foraDaLista();
        }
        $configuracao = $this->configuracoes->doEscritorio($tenant) ?? new ConfiguracaoDePresenca($tenant);
        $configuracao->alterar($minutos, $quem, $this->clock->now());
        try {
            $this->configuracoes->salvar($configuracao);
        } catch (UniqueConstraintViolationException) {
            // Dois primários gravando a PRIMEIRA escolha ao mesmo tempo: a UNIQUE(tenant_id) segura; o outro venceu.
            throw AusenciaRecusadaException::concorrente();
        }

        return $minutos;
    }

    private function exigirPrimario(User $quem, Tenant $tenant): void
    {
        $vinculo = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);
        if ($vinculo === null || !$this->regraDosPrimarios->ehPrimario($vinculo)) {
            throw AusenciaRecusadaException::semPermissao();
        }
    }
}
