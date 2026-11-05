<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Repository\PastaRepository;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Auditoria 1.17: o sino e o Ver todas refazem, na leitura, a pergunta que a Timeline da pasta faz. O aviso de
 * menção ou de resposta guarda a prévia do texto e só conferiu a visibilidade quando nasceu; se depois a pessoa
 * perde a leitura da pasta (permissão de módulo ou acesso item a item, sem cadeado) ou a aba Financeiro passa a
 * oculta para ela, o aviso não pode continuar mostrando a prévia.
 *
 * Olha só as pastas que aparecem nos links dos avisos da pessoa no escritório, em lote: uma consulta para os ids,
 * uma para as pastas, uma para os cadeados, uma para o vínculo, uma para os acessos item a item
 * ({@see PermissionChecker::idsPermitidosComVinculo()}) e uma para as abas ocultas. Primário e Master: nada (veem tudo).
 * O sino pergunta várias vezes por página (lista, selo e as duas abas): a resposta fica guardada até o fim da requisição.
 */
final class LeituraDasPastasDosAvisos implements ResetInterface
{
    /** @var array<string, array{semLeitura: list<int>, semFinanceiro: list<int>}> */
    private array $guardado = [];

    public function __construct(
        private readonly NotificacaoRepository $notificacoes,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $vinculos,
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
    ) {
    }

    /** @return list<int> pastas dos links de aviso que a pessoa não pode mais ver */
    public function semLeitura(User $pessoa, Tenant $escritorio): array
    {
        return $this->calcular($pessoa, $escritorio)['semLeitura'];
    }

    /** @return list<int> pastas dos links de aviso em que a aba Financeiro está oculta para a pessoa */
    public function semFinanceiro(User $pessoa, Tenant $escritorio): array
    {
        return $this->calcular($pessoa, $escritorio)['semFinanceiro'];
    }

    public function reset(): void
    {
        $this->guardado = [];
    }

    /** @return array{semLeitura: list<int>, semFinanceiro: list<int>} */
    private function calcular(User $pessoa, Tenant $escritorio): array
    {
        $chave = $pessoa->getId() . ':' . $escritorio->getId();
        if (isset($this->guardado[$chave])) {
            return $this->guardado[$chave];
        }

        return $this->guardado[$chave] = $this->permissionChecker->isAdministradorDoEscritorio($pessoa, $escritorio)
            ? ['semLeitura' => [], 'semFinanceiro' => []]
            : $this->paraQuemNaoAdministra($pessoa, $escritorio);
    }

    /** @return array{semLeitura: list<int>, semFinanceiro: list<int>} */
    private function paraQuemNaoAdministra(User $pessoa, Tenant $escritorio): array
    {
        $ids = $this->notificacoes->idsDasPastasNosLinksDoUsuario($pessoa, $escritorio);
        if ($ids === []) {
            return ['semLeitura' => [], 'semFinanceiro' => []];
        }

        // Pasta apagada ou de outro escritório não volta: o aviso dela segue a regra antiga.
        $pastas = $this->pastas->findBy(['id' => $ids, 'tenant' => $escritorio]);
        // Carrega os cadeados das candidatas numa consulta: o checker os lê da memória.
        $this->acessoAPasta->niveisEmLote($pessoa, $pastas);
        $existentes = array_map(static fn ($p): int => (int) $p->getId(), $pastas);
        $vinculo    = $this->vinculos->findAtivoPorUserETenant($pessoa, $escritorio);
        $permitidas = array_flip($this->permissionChecker->idsPermitidosComVinculo($pessoa, $vinculo, AccessRequest::RESOURCE_PASTA, $existentes, AccessRequest::ACTION_VIEW));

        $semFinanceiro = [];
        foreach ($this->visibilidadeDasAbas->abasOcultasPorPasta($pessoa, $escritorio) as $pastaId => $abas) {
            if (isset($permitidas[$pastaId]) && in_array(AbaDaPasta::Financeiro, $abas, true)) {
                $semFinanceiro[] = $pastaId;
            }
        }

        return [
            'semLeitura'    => array_values(array_filter($existentes, static fn (int $id): bool => !isset($permitidas[$id]))),
            'semFinanceiro' => $semFinanceiro,
        ];
    }
}
