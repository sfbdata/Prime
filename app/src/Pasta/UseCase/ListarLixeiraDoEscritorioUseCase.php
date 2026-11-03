<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
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
 *    nem nas datas, nem em quem excluiu, nem na POSIÇÃO da pasta na lista); a pasta em que só ele
 *    está na lixeira não aparece;
 *  - ordem: da exclusão visível mais recente para a mais antiga (empate: id maior primeiro). A
 *    página seguinte continua DEPOIS da chave da última pasta (`proximo` = "data|id"): resolver uma
 *    pasta já mostrada não faz a próxima página pular ninguém;
 *  - volume: as contas são agregações no banco (GROUP BY pasta); a permissão é conferida em blocos
 *    de {@see PASTAS_POR_PEDIDO} pastas até juntar uma página visível, com um número FIXO de consultas
 *    por bloco (regras de aba do escritório,
 *    pastas, cadeados, vínculo, acessos item a item e nomes; uma de cada), não uma por pasta.
 */
final class ListarLixeiraDoEscritorioUseCase
{
    /** Pastas com algo visível na lixeira conferidas (permissão) por pedido. */
    public const PASTAS_POR_PEDIDO = 50;

    private const CURSOR = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)\|([1-9]\d{0,17})$/';

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

    /** `$apos`: o `proximo` da página anterior ("data|id"); vazio ou inválido = do começo. */
    public function executar(User $quem, Tenant $tenant, ?string $apos = null): LixeiraDoEscritorioOutput
    {
        $candidatas = self::depoisDe($this->candidatasEmOrdem($quem, $tenant), self::chaveDoCursor($apos));

        // A permissão é conferida em blocos de PASTAS_POR_PEDIDO (consultas fixas por bloco) até
        // juntar uma página de pastas VISÍVEIS. O cursor é a chave da última pasta visível: ele
        // nunca revela id nem data de pasta que quem lê não pode editar.
        $visiveis   = [];
        $conferidas = 0;
        while (count($visiveis) <= self::PASTAS_POR_PEDIDO && $conferidas < count($candidatas)) {
            $bloco       = array_slice($candidatas, $conferidas, self::PASTAS_POR_PEDIDO);
            $conferidas += count($bloco);
            array_push($visiveis, ...$this->editaveis($bloco, $quem, $tenant));
        }
        $haMais   = count($visiveis) > self::PASTAS_POR_PEDIDO || $conferidas < count($candidatas);
        $visiveis = array_slice($visiveis, 0, self::PASTAS_POR_PEDIDO);
        $ultima   = $visiveis === [] ? null : $visiveis[count($visiveis) - 1];
        $nomes    = $this->nomesDosAutores($visiveis);

        return new LixeiraDoEscritorioOutput(
            array_map(static fn (array $v): GrupoDaLixeiraDoEscritorio => self::grupo($v['pasta'], $v['contas'], $nomes), $visiveis),
            $haMais && $ultima !== null ? $ultima['contas']['maisRecente'] . '|' . $ultima['pasta']->getId() : null,
        );
    }

    /**
     * Toda pasta do escritório com algo VISÍVEL na lixeira para quem lê (aba Documentos visível,
     * contrato oculto fora), com as contas dele, da exclusão visível mais recente para a mais antiga.
     *
     * @return list<array{pastaId: int, contas: array{documentos: int, subpastas: int, maisAntiga: string, maisRecente: string, autores: list<int>, semAutor: bool}}>
     */
    private function candidatasEmOrdem(User $quem, Tenant $tenant): array
    {
        $resumos = $this->lixeira->comLixeiraVisivel(fn (): array => ResumoDaLixeiraDaPasta::agrupar(
            $this->documentos->resumoDaLixeiraDoEscritorio($tenant),
            $this->secoes->resumoDaLixeiraDoEscritorio($tenant),
        ));
        $ocultasPorPasta = $this->abas->abasOcultasPorPasta($quem, $tenant);

        $candidatas = [];
        foreach ($resumos as $resumo) {
            $ocultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $ocultasPorPasta[$resumo->pastaId] ?? []);
            // Aba Documentos oculta: as rotas da lixeira recusariam; a pasta não entra.
            $contas = \in_array(AbaDaPasta::Documentos->value, $ocultas, true) ? null : $resumo->visivelPara($ocultas);
            if ($contas !== null) {
                $candidatas[] = ['pastaId' => $resumo->pastaId, 'contas' => $contas];
            }
        }
        usort($candidatas, static fn (array $a, array $b): int => [$b['contas']['maisRecente'], $b['pastaId']] <=> [$a['contas']['maisRecente'], $a['pastaId']]);

        return $candidatas;
    }

    /** @return array{0: string, 1: int}|null a chave (data, id) do cursor, ou NULL para começar do início */
    private static function chaveDoCursor(?string $apos): ?array
    {
        if ($apos === null || preg_match(self::CURSOR, $apos, $m) !== 1) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    /**
     * As candidatas que vêm DEPOIS da chave na ordem (mais antigas que ela, ou de mesma data e id menor).
     *
     * @template T of array{pastaId: int, contas: array{maisRecente: string}}
     *
     * @param list<T>                   $candidatas
     * @param array{0: string, 1: int}|null $chave
     *
     * @return list<T>
     */
    private static function depoisDe(array $candidatas, ?array $chave): array
    {
        if ($chave === null) {
            return $candidatas;
        }

        return array_values(array_filter($candidatas, static fn (array $c): bool => [$c['contas']['maisRecente'], $c['pastaId']] < $chave));
    }

    /**
     * As pastas da página em que quem lê pode EDITAR (a permissão das rotas da lixeira da pasta), na
     * ordem da página. Uma consulta para as pastas, uma para os cadeados, uma para o vínculo e uma
     * para os acessos item a item ({@see PermissionChecker::idsPermitidosComVinculo()}).
     *
     * @param list<array{pastaId: int, contas: array{documentos: int, subpastas: int, maisAntiga: string, maisRecente: string, autores: list<int>, semAutor: bool}}> $pagina
     *
     * @return list<array{pasta: Pasta, contas: array{documentos: int, subpastas: int, maisAntiga: string, maisRecente: string, autores: list<int>, semAutor: bool}}>
     */
    private function editaveis(array $pagina, User $quem, Tenant $tenant): array
    {
        $pastas = $this->pastasDoEscritorio(array_column($pagina, 'pastaId'), $tenant);
        if ($pastas === []) {
            return [];
        }
        // Carrega os cadeados da página numa consulta: o checker os lê da memória.
        $this->acessoAPasta->niveisEmLote($quem, $pastas);
        $vinculo    = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);
        $permitidas = array_flip($this->permissoes->idsPermitidosComVinculo($quem, $vinculo, AccessRequest::RESOURCE_PASTA, array_keys($pastas), AccessRequest::ACTION_EDIT));

        $visiveis = [];
        foreach ($pagina as $candidata) {
            if (isset($permitidas[$candidata['pastaId']], $pastas[$candidata['pastaId']])) {
                $visiveis[] = ['pasta' => $pastas[$candidata['pastaId']], 'contas' => $candidata['contas']];
            }
        }

        return $visiveis;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Pasta> por id; só pasta deste escritório
     */
    private function pastasDoEscritorio(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        $porId = [];
        foreach ($this->pastas->findBy(['id' => $ids, 'tenant' => $tenant]) as $pasta) {
            if ($pasta->getTenant() === $tenant) {
                $porId[(int) $pasta->getId()] = $pasta;
            }
        }

        return $porId;
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
