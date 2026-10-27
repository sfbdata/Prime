<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\DTO\AcervoPorJurisdicaoOutput;
use App\Djen\DTO\LinhaDoProcessoDaOab;
use App\Djen\DTO\UltimaPublicacaoDoProcesso;
use App\Djen\Enum\SituacaoDoPrazoDoProcesso;
use App\Djen\Repository\ProcessosDaOabRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Repository\UltimaPublicacaoDoProcessoRepository;
use App\Djen\Service\PrazoDaPublicacaoCalculador;
use App\Entity\Tenant\Tenant;

/**
 * Sub-aba "Acervo por jurisdição" (desenho 11): os processos acompanhados agrupados pela situação do
 * prazo da publicação mais recente e, dentro de cada grupo, por órgão. Só leitura.
 */
final class AcervoPorJurisdicaoUseCase
{
    public const SEM_ORGAO = 'Órgão não informado';

    public function __construct(
        private readonly ProcessosDaOabRepository $processosDaOab,
        private readonly UltimaPublicacaoDoProcessoRepository $ultimas,
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PrazoDaPublicacaoCalculador $calculador,
    ) {
    }

    /**
     * @param list<int> $processosOcultos
     */
    public function executar(Tenant $tenant, \DateTimeImmutable $hoje, string $grupo, string $orgao, string $busca, array $processosOcultos = []): AcervoPorJurisdicaoOutput
    {
        $linhas = $this->processosDaOab->acompanhados($tenant, $processosOcultos);
        $ultimas = $this->ultimas->dosNumeros($tenant, array_map(static fn (LinhaDoProcessoDaOab $l): string => $l->numero, $linhas));
        $doTeor = $this->publicacoes->prazosDoPushPorPublicacao($tenant, array_map(static fn (UltimaPublicacaoDoProcesso $u): int => $u->id, array_values($ultimas)));

        /** @var array<string, array{total: int, orgaos: array<string, int>}> $grupos */
        $grupos = [];
        foreach (SituacaoDoPrazoDoProcesso::grupos() as $chave) {
            $grupos[$chave] = ['total' => 0, 'orgaos' => []];
        }
        $todosOrgaos = [];
        /** @var list<array{0: LinhaDoProcessoDaOab, 1: SituacaoDoPrazoDoProcesso, 2: string}> $classificadas */
        $classificadas = [];
        foreach ($linhas as $linha) {
            $ultima = $ultimas[$linha->numero] ?? null;
            $situacao = $this->situacao($ultima, $ultima !== null ? ($doTeor[$ultima->id] ?? null) : null, $tenant->getEstado(), $hoje);
            if ($situacao === null) {
                continue;
            }
            $nomeOrgao = $this->orgaoDe($linha, $ultima);
            $todosOrgaos[$nomeOrgao] = true;
            $classificadas[] = [$linha, $situacao, $nomeOrgao];
            $grupos = $this->somarNosGrupos($grupos, $situacao, $nomeOrgao);
        }
        foreach ($grupos as &$g) {
            uksort($g['orgaos'], static fn (string $a, string $b): int => strcoll($a, $b));
        }
        unset($g);

        $mostrarLista = $grupo !== '' || $orgao !== '' || $busca !== '';

        return new AcervoPorJurisdicaoOutput($grupos, \count($todosOrgaos), $mostrarLista ? $this->recortar($classificadas, $grupo, $orgao, $busca) : [], $mostrarLista);
    }

    /**
     * @param array<string, array{total: int, orgaos: array<string, int>}> $grupos
     *
     * @return array<string, array{total: int, orgaos: array<string, int>}>
     */
    private function somarNosGrupos(array $grupos, SituacaoDoPrazoDoProcesso $situacao, string $nomeOrgao): array
    {
        foreach (SituacaoDoPrazoDoProcesso::grupos() as $chave) {
            if ($situacao->pertenceAoGrupo($chave)) {
                ++$grupos[$chave]['total'];
                $grupos[$chave]['orgaos'][$nomeOrgao] = ($grupos[$chave]['orgaos'][$nomeOrgao] ?? 0) + 1;
            }
        }

        return $grupos;
    }

    /**
     * Processos do grupo, órgão e número pedidos, do movimento mais recente ao mais antigo.
     *
     * @param list<array{0: LinhaDoProcessoDaOab, 1: SituacaoDoPrazoDoProcesso, 2: string}> $classificadas
     *
     * @return list<LinhaDoProcessoDaOab>
     */
    private function recortar(array $classificadas, string $grupo, string $orgao, string $busca): array
    {
        $filtradas = [];
        foreach ($classificadas as [$linha, $situacao, $nomeOrgao]) {
            if (($grupo === '' || $situacao->pertenceAoGrupo($grupo)) && ($orgao === '' || $nomeOrgao === $orgao) && ($busca === '' || str_contains($linha->numero, $busca))) {
                $filtradas[] = $linha;
            }
        }
        usort($filtradas, static fn (LinhaDoProcessoDaOab $a, LinhaDoProcessoDaOab $b): int => ($b->ultimoMovimento?->getTimestamp() ?? 0) <=> ($a->ultimoMovimento?->getTimestamp() ?? 0));

        return $filtradas;
    }

    /**
     * Nulo: prazo cumprido há mais de 10 dias, que o desenho não lista em grupo nenhum.
     *
     * @param array{data: \DateTimeImmutable, cumprido: bool, algumCumprido: bool}|null $doTeor
     */
    private function situacao(?UltimaPublicacaoDoProcesso $ultima, ?array $doTeor, ?string $uf, \DateTimeImmutable $hoje): ?SituacaoDoPrazoDoProcesso
    {
        if ($ultima === null) {
            return SituacaoDoPrazoDoProcesso::SemPrazo;
        }
        $cumprido = $ultima->prazoCumpridoEm !== null || ($doTeor['cumprido'] ?? false);
        if ($cumprido) {
            // O prazo do teor (pasta) não guarda aqui a data do cumprimento: conta como respondido.
            $recente = $ultima->prazoCumpridoEm === null || $ultima->prazoCumpridoEm >= $hoje->setTime(0, 0)->modify('-' . SituacaoDoPrazoDoProcesso::DIAS_DE_RESPONDIDO . ' days');

            return $recente ? SituacaoDoPrazoDoProcesso::Respondido : null;
        }
        $prazo = $this->calculador->calcular($ultima->disponibilizacao, $ultima->prazoDias, $doTeor['data'] ?? null, false, $uf, $hoje);
        if ($prazo === null) {
            return SituacaoDoPrazoDoProcesso::SemPrazo;
        }
        if ($prazo->vencido()) {
            return SituacaoDoPrazoDoProcesso::PrazoFindou;
        }

        return $ultima->lida ? SituacaoDoPrazoDoProcesso::CienciaDada : SituacaoDoPrazoDoProcesso::PendenteDeCiencia;
    }

    private function orgaoDe(LinhaDoProcessoDaOab $linha, ?UltimaPublicacaoDoProcesso $ultima): string
    {
        foreach ([$linha->orgao, $ultima !== null ? ($ultima->nomeOrgao ?? '') : ''] as $nome) {
            if (trim($nome) !== '') {
                return trim($nome);
            }
        }

        return self::SEM_ORGAO;
    }
}
