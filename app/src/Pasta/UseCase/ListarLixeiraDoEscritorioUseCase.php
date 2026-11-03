<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\GrupoDaLixeiraDoEscritorio;
use App\Pasta\DTO\LixeiraDoEscritorioOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Shared\Doctrine\Filter\AcessoALixeira;

/**
 * A lixeira do ESCRITÓRIO, agrupada pela pasta de onde cada item saiu (pedido do Samuel,
 * 03/11/2026, tela /expediente). Só leitura.
 *
 * Quem dispara: quem usa o Expediente e quer achar, num lugar só, o que foi excluído nas pastas do
 * escritório, para restaurar ou excluir de vez sem abrir pasta por pasta. As ações em si são as
 * rotas que já existem por pasta (`pasta_documentos_restaurar`, `pasta_documentos_excluir_de_vez`);
 * aqui só se monta a lista.
 *
 * Regras:
 *  - só o escritório `$tenant`; a lixeira é lida pelo `AcessoALixeira` (a agregação, e só ela,
 *    roda com o filtro desligado) e as consultas levam o `excluido_em IS NOT NULL` e o tenant;
 *  - só aparece a pasta em que quem lê pode restaurar: EDITAR a pasta pelo `PermissionChecker`
 *    (a mesma permissão das rotas da lixeira da pasta) e a aba Documentos visível para ele (a
 *    `BarreiraDeAbaOcultaListener` recusaria as rotas `pasta_documento*`);
 *  - o contrato de honorários com o Financeiro oculto para quem lê não conta (nem na quantidade,
 *    nem nas datas, nem em quem excluiu); a pasta em que só ele está na lixeira não aparece;
 *  - volume: a contagem é uma agregação no banco (GROUP BY pasta); a permissão só é conferida em
 *    {@see PASTAS_POR_PEDIDO} pastas por pedido, da exclusão mais recente para a mais antiga. A
 *    página seguinte continua de `proximo` (posição na lista ordenada).
 */
final class ListarLixeiraDoEscritorioUseCase
{
    /** Pastas com algo na lixeira conferidas (permissão e abas) por pedido. */
    public const PASTAS_POR_PEDIDO = 50;

    public function __construct(
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaSecaoRepository $secoes,
        private readonly PastaRepository $pastas,
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly PermissionChecker $permissoes,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly AcessoALixeira $lixeira,
    ) {
    }

    public function executar(User $quem, Tenant $tenant, int $apos = 0): LixeiraDoEscritorioOutput
    {
        $apos    = max(0, $apos);
        $resumos = $this->resumosDoEscritorio($tenant);
        $fatia   = array_slice($resumos, $apos, self::PASTAS_POR_PEDIDO);
        $fim     = $apos + count($fatia);

        $visiveis = $this->visiveisPara($fatia, $quem, $tenant);
        $nomes    = $this->nomesDosAutores($visiveis);

        return new LixeiraDoEscritorioOutput(
            array_map(static fn (array $v): GrupoDaLixeiraDoEscritorio => self::grupo($v['pasta'], $v['contas'], $nomes), $visiveis),
            $fim < count($resumos) ? $fim : null,
        );
    }

    /** @return list<ResumoDaLixeiraDaPasta> da exclusão mais recente para a mais antiga */
    private function resumosDoEscritorio(Tenant $tenant): array
    {
        return $this->lixeira->comLixeiraVisivel(fn (): array => ResumoDaLixeiraDaPasta::agrupar(
            $this->documentos->resumoDaLixeiraDoEscritorio($tenant),
            $this->secoes->resumoDaLixeiraDoEscritorio($tenant),
        ));
    }

    /**
     * As pastas da fatia que quem lê pode restaurar, com as contas que ele vê, na ordem da fatia.
     *
     * @param list<ResumoDaLixeiraDaPasta> $fatia
     *
     * @return list<array{pasta: Pasta, contas: array{documentos: int, subpastas: int, maisAntiga: string, maisRecente: string, autores: list<int>, semAutor: bool}}>
     */
    private function visiveisPara(array $fatia, User $quem, Tenant $tenant): array
    {
        $pastas = $this->pastasDoEscritorio($fatia, $tenant);
        // Carrega os cadeados da fatia numa consulta: o `canAccessResource` de cada pasta os lê da memória.
        $this->acessoAPasta->niveisEmLote($quem, $pastas);
        $vinculo = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);

        $visiveis = [];
        foreach ($fatia as $resumo) {
            $pasta = $pastas[$resumo->pastaId] ?? null;
            if ($pasta === null || !$this->podeRestaurarNa($pasta, $quem, $tenant, $vinculo)) {
                continue;
            }
            $contas = $this->contasVisiveis($resumo, $pasta, $quem);
            if ($contas !== null) {
                $visiveis[] = ['pasta' => $pasta, 'contas' => $contas];
            }
        }

        return $visiveis;
    }

    /**
     * @param list<ResumoDaLixeiraDaPasta> $fatia
     *
     * @return array<int, Pasta> por id; só pasta deste escritório
     */
    private function pastasDoEscritorio(array $fatia, Tenant $tenant): array
    {
        if ($fatia === []) {
            return [];
        }

        $porId = [];
        foreach ($this->pastas->findBy(['id' => array_map(static fn (ResumoDaLixeiraDaPasta $r): int => $r->pastaId, $fatia), 'tenant' => $tenant]) as $pasta) {
            if ($pasta->getTenant() === $tenant) {
                $porId[(int) $pasta->getId()] = $pasta;
            }
        }

        return $porId;
    }

    /** A permissão das rotas da lixeira da pasta: EDITAR a pasta (S-10: restaura quem pode excluir). */
    private function podeRestaurarNa(Pasta $pasta, User $quem, Tenant $tenant, ?UserTenant $vinculo): bool
    {
        $id = (int) $pasta->getId();
        if ($vinculo !== null) {
            return $this->permissoes->canAccessResourceComVinculo($quem, $vinculo, AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_EDIT);
        }

        // Sem vínculo ativo só o Master passa (o próprio checker decide).
        return $this->permissoes->canAccessResource($quem, $tenant, AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_EDIT);
    }

    /**
     * Aba Documentos oculta: a pasta não entra (as rotas da lixeira recusariam). Senão, as contas
     * sem o contrato oculto.
     *
     * @return array{documentos: int, subpastas: int, maisAntiga: string, maisRecente: string, autores: list<int>, semAutor: bool}|null
     */
    private function contasVisiveis(ResumoDaLixeiraDaPasta $resumo, Pasta $pasta, User $quem): ?array
    {
        $ocultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->abas->abasOcultasPara($pasta, $quem));
        if (\in_array(AbaDaPasta::Documentos->value, $ocultas, true)) {
            return null;
        }

        return $resumo->visivelPara($ocultas);
    }

    /**
     * Os nomes de quem excluiu, numa consulta para a página inteira.
     *
     * @param list<array{pasta: Pasta, contas: array{autores: list<int>}}> $visiveis
     *
     * @return array<int, string>
     */
    private function nomesDosAutores(array $visiveis): array
    {
        $ids = array_values(array_unique(array_merge([], ...array_map(static fn (array $v): array => $v['contas']['autores'], $visiveis))));
        if ($ids === []) {
            return [];
        }

        $nomes = [];
        foreach ($this->usuarios->findBy(['id' => $ids]) as $usuario) {
            $nomes[(int) $usuario->getId()] = (string) $usuario->getFullName();
        }

        return $nomes;
    }

    /**
     * @param array{documentos: int, subpastas: int, maisAntiga: string, maisRecente: string, autores: list<int>, semAutor: bool} $contas
     * @param array<int, string>                                                                                                  $nomes
     */
    private static function grupo(Pasta $pasta, array $contas, array $nomes): GrupoDaLixeiraDoEscritorio
    {
        $quem = array_values(array_unique(array_filter(array_map(static fn (int $id): string => $nomes[$id] ?? '', $contas['autores']), static fn (string $n): bool => $n !== '')));
        sort($quem, SORT_STRING | SORT_FLAG_CASE);

        return new GrupoDaLixeiraDoEscritorio(
            pastaId: (int) $pasta->getId(),
            nup: (string) $pasta->getNup(),
            nome: $pasta->getNomeCliente() ?? $pasta->getNomeAcao(),
            pastaExcluida: $pasta->estaExcluida(),
            documentos: $contas['documentos'],
            subpastas: $contas['subpastas'],
            maisRecente: new \DateTimeImmutable($contas['maisRecente']),
            maisAntiga: new \DateTimeImmutable($contas['maisAntiga']),
            excluidoPor: $quem,
            autorSemRegistro: $contas['semAutor'] || \count($quem) < \count($contas['autores']),
        );
    }
}
