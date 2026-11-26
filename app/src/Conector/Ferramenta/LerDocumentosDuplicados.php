<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\SegredoNasListas;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * `duplicados` da `ler_documentos_e_modelos` (pedido do dono de 26/11/2026, M9 item 3): o relatório, SÓ DE LEITURA, dos
 * documentos repetidos dentro de cada pasta. Nada é excluído nem marcado: a decisão sobre o que limpar é do escritório,
 * com o relatório em mãos.
 *
 * Dois recortes:
 *  - sem `pasta_id`, o escritório inteiro: os totais e as pastas com repetidos (id, número, grupos e cópias a mais),
 *    da que tem mais cópias para a que tem menos, {@see PASTAS_POR_PAGINA} por página;
 *  - com `pasta_id`, os grupos daquela pasta, cada um com os documentos (id, nome, categoria, subpasta, data de envio
 *    e tamanho), {@see GRUPOS_POR_PAGINA} por página.
 *
 * Os critérios ({@see PastaDocumentoRepository::linhasDeDuplicadosDoEscritorio()}) nunca se misturam: `conteudo` é o
 * mesmo hash (arquivo idêntico); `provavel` é o mesmo nome e tamanho entre documentos ainda sem hash, indício e não
 * prova. O `aviso` diz quantos documentos ainda não têm hash.
 *
 * Acesso, como as outras leituras de várias pastas do conector ({@see LerClientesFerramenta::pastasDoRecorte()}): pasta
 * em segredo de justiça sai pela {@see SegredoNasListas} e é contada; pasta que a guarda da linha recusa (cadeado, a
 * pessoa não vê, aba Documentos oculta) sai e é contada como sem acesso; o contrato de honorários sai onde a aba
 * Financeiro está oculta ({@see VisibilidadeDasAbasDaPasta::documentoVisivel()}). No recorte de uma pasta, a pasta já
 * chega liberada pela ferramenta (guarda e {@see \App\Central\Service\PastaDoContextoDaIa}). Nenhum valor de documento,
 * só nomes pela {@see ProtecaoDoTextoDoChat}; o hash nunca sai.
 */
final class LerDocumentosDuplicados
{
    public const ACAO = 'duplicados';
    public const PASTAS_POR_PAGINA = 100;
    public const GRUPOS_POR_PAGINA = 30;
    private const TAMANHO_DO_NOME = 150;

    public const AVISO_SEM_HASH = '%d documento(s) ainda sem o hash do conteúdo calculado: para eles a comparação é por nome e tamanho (critério provavel), que é indício e não prova.';

    public const CRITERIOS = [
        'conteudo' => 'Arquivo idêntico: o mesmo hash do conteúdo.',
        'provavel' => 'Provável cópia: documentos ainda sem hash com o mesmo nome original e o mesmo tamanho.',
    ];

    public function __construct(
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaRepository $pastas,
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly SegredoNasListas $segredo,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    /**
     * O escritório inteiro. A guarda sem pasta (chave e módulo) já passou na ferramenta.
     *
     * @throws ErroDaFerramenta
     */
    public function doEscritorio(ChamadaDoConector $chamada, int $pagina): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $linhas = $this->documentos->linhasDeDuplicadosDoEscritorio($escritorio);
        $semHash = $this->documentos->semSha256PorPasta($escritorio);

        $ids = array_values(array_unique(array_merge(array_column($linhas, 'pasta_id'), array_keys($semHash))));
        $recorte = $this->pastasDoRecorte($ids, $chamada);
        $ocultasPorPasta = $this->abasOcultasPorPasta($chamada);

        /** @var array<int, list<array{pasta_id: int, criterio: string, chave: string, categoria: string, total: int, ids: list<int>}>> $porPasta */
        $porPasta = [];
        foreach ($linhas as $linha) {
            if (isset($recorte['pastas'][$linha['pasta_id']])) {
                $porPasta[$linha['pasta_id']][] = $linha;
            }
        }

        $itens = [];
        $totais = ['conteudo' => ['grupos' => 0, 'copias_a_mais' => 0, 'pastas' => 0], 'provavel' => ['grupos' => 0, 'copias_a_mais' => 0, 'pastas' => 0]];
        foreach ($porPasta as $pastaId => $daPasta) {
            $grupos = self::grupos($daPasta, $ocultasPorPasta[$pastaId] ?? []);
            $resumo = self::resumo($grupos);
            if ($resumo['conteudo']['grupos'] + $resumo['provavel']['grupos'] === 0) {
                continue;
            }
            foreach (['conteudo', 'provavel'] as $criterio) {
                $totais[$criterio]['grupos'] += $resumo[$criterio]['grupos'];
                $totais[$criterio]['copias_a_mais'] += $resumo[$criterio]['copias_a_mais'];
                $totais[$criterio]['pastas'] += $resumo[$criterio]['grupos'] > 0 ? 1 : 0;
            }
            $itens[] = [
                'pasta_id' => $pastaId,
                'numero' => $this->numero($escritorio, $recorte['pastas'][$pastaId]),
                'conteudo' => $resumo['conteudo'],
                'provavel' => $resumo['provavel'],
            ];
        }
        usort($itens, static fn (array $a, array $b): int => [self::copias($b), $a['pasta_id']] <=> [self::copias($a), $b['pasta_id']]);

        $semHashVisivel = 0;
        foreach ($semHash as $pastaId => $n) {
            $semHashVisivel += isset($recorte['pastas'][$pastaId]) ? $n : 0;
        }

        $daPagina = \array_slice($itens, ($pagina - 1) * self::PASTAS_POR_PAGINA, self::PASTAS_POR_PAGINA);
        $proxima = \count($itens) > $pagina * self::PASTAS_POR_PAGINA ? $pagina + 1 : null;

        return new RespostaDaFerramenta(
            [
                'recorte' => 'escritorio',
                'criterios' => self::CRITERIOS,
                'totais' => $totais + [
                    'pastas_com_repetidos' => \count($itens),
                    'documentos_sem_hash' => $semHashVisivel,
                    'pastas_omitidas_em_segredo' => $recorte['em_segredo'],
                    'pastas_omitidas_sem_acesso' => $recorte['sem_acesso'],
                ],
                'pastas' => $daPagina,
                'aviso' => self::aviso($semHashVisivel),
            ],
            $proxima,
            contagens: ['pastas' => \count($daPagina), 'em_segredo' => $recorte['em_segredo'], 'sem_acesso' => $recorte['sem_acesso']],
            ids: ['pasta' => array_column($daPagina, 'pasta_id')],
        );
    }

    /**
     * Uma pasta, já liberada pela ferramenta (guarda com a pasta, segredo e abas ocultas).
     *
     * @param list<string> $abasOcultas
     */
    public function daPasta(Pasta $pasta, array $abasOcultas, ChamadaDoConector $chamada, int $pagina): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $pastaId = (int) $pasta->getId();
        $grupos = self::grupos($this->documentos->linhasDeDuplicadosDoEscritorio($escritorio, $pastaId), $abasOcultas);
        $semHash = $this->documentos->semSha256PorPasta($escritorio, $pastaId)[$pastaId] ?? 0;

        $daPagina = \array_slice($grupos, ($pagina - 1) * self::GRUPOS_POR_PAGINA, self::GRUPOS_POR_PAGINA);
        $proxima = \count($grupos) > $pagina * self::GRUPOS_POR_PAGINA ? $pagina + 1 : null;

        $idsDaPagina = array_merge([], ...array_column($daPagina, 'ids'));
        $porId = [];
        if ($idsDaPagina !== []) {
            foreach ($this->documentos->findBy(['id' => $idsDaPagina, 'tenant' => $escritorio, 'pasta' => $pasta]) as $documento) {
                $porId[(int) $documento->getId()] = $documento;
            }
        }

        $itens = [];
        foreach ($daPagina as $grupo) {
            $documentos = [];
            foreach ($grupo['ids'] as $id) {
                if (isset($porId[$id])) {
                    $documentos[] = $this->documento($escritorio, $porId[$id]);
                }
            }
            $itens[] = ['criterio' => $grupo['criterio'], 'copias_a_mais' => \count($grupo['ids']) - 1, 'documentos' => $documentos];
        }

        return new RespostaDaFerramenta(
            [
                'recorte' => 'pasta',
                'pasta_id' => $pastaId,
                'numero' => $this->numero($escritorio, $pasta),
                'criterios' => self::CRITERIOS,
                'totais' => self::resumo($grupos) + ['documentos_sem_hash' => $semHash],
                'grupos' => $itens,
                'aviso' => self::aviso($semHash),
            ],
            $proxima,
            contagens: ['grupos' => \count($itens)],
            ids: ['pasta' => [$pastaId], 'documento' => array_keys($porId)],
        );
    }

    /**
     * As pastas fora do segredo que a guarda da linha deixa passar, e quantas saíram de cada jeito.
     *
     * @param list<int> $ids
     *
     * @return array{pastas: array<int, Pasta>, em_segredo: int, sem_acesso: int}
     *
     * @throws ErroDaFerramenta
     */
    private function pastasDoRecorte(array $ids, ChamadaDoConector $chamada): array
    {
        if ($ids === []) {
            return ['pastas' => [], 'em_segredo' => 0, 'sem_acesso' => 0];
        }
        $escritorio = $chamada->contexto->escritorio;
        $candidatas = array_values(array_filter(
            $this->pastas->findBy(['id' => $ids, 'tenant' => $escritorio]),
            static fn (Pasta $p): bool => !$p->estaExcluida(),
        ));
        $foraDoSegredo = $this->segredo->tirarDe($candidatas, static fn (Pasta $p): int => (int) $p->getId(), $escritorio);

        $pastas = [];
        $semAcesso = 0;
        foreach ($foraDoSegredo['itens'] as $pasta) {
            try {
                $this->guarda->exigir($chamada, LerDocumentosEModelosFerramenta::NOME, self::ACAO, $pasta);
            } catch (ErroDaFerramenta $e) {
                if ($e->motivo !== ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO) {
                    throw $e;
                }
                ++$semAcesso;
                continue;
            }
            $pastas[(int) $pasta->getId()] = $pasta;
        }

        return ['pastas' => $pastas, 'em_segredo' => $foraDoSegredo['em_segredo'], 'sem_acesso' => $semAcesso];
    }

    /** @return array<int, list<string>> id da pasta => abas ocultas para a pessoa */
    private function abasOcultasPorPasta(ChamadaDoConector $chamada): array
    {
        $porPasta = [];
        foreach ($this->abas->abasOcultasPorPasta($chamada->contexto->pessoa, $chamada->contexto->escritorio) as $pastaId => $abas) {
            $porPasta[$pastaId] = array_map(static fn (AbaDaPasta $a): string => $a->value, $abas);
        }

        return $porPasta;
    }

    /**
     * Os grupos de uma pasta com o que a pessoa vê: as categorias que as abas ocultas escondem saem, e o grupo que
     * fica com um documento só deixa de ser grupo. Do grupo de envio mais antigo ao mais novo.
     *
     * @param list<array{pasta_id: int, criterio: string, chave: string, categoria: string, total: int, ids: list<int>}> $linhas
     * @param list<string> $abasOcultas
     *
     * @return list<array{criterio: string, ids: list<int>}>
     */
    private static function grupos(array $linhas, array $abasOcultas): array
    {
        $grupos = [];
        foreach ($linhas as $linha) {
            if (!VisibilidadeDasAbasDaPasta::documentoVisivel($linha['categoria'], $abasOcultas)) {
                continue;
            }
            $chave = $linha['criterio'] . ':' . $linha['chave'];
            $grupos[$chave] ??= ['criterio' => $linha['criterio'], 'ids' => []];
            $grupos[$chave]['ids'] = array_merge($grupos[$chave]['ids'], $linha['ids']);
        }

        $ficam = [];
        foreach ($grupos as $grupo) {
            if (\count($grupo['ids']) > 1) {
                sort($grupo['ids']);
                $ficam[] = $grupo;
            }
        }
        usort($ficam, static fn (array $a, array $b): int => $a['ids'][0] <=> $b['ids'][0]);

        return $ficam;
    }

    /**
     * @param list<array{criterio: string, ids: list<int>}> $grupos
     *
     * @return array{conteudo: array{grupos: int, copias_a_mais: int}, provavel: array{grupos: int, copias_a_mais: int}}
     */
    private static function resumo(array $grupos): array
    {
        $resumo = ['conteudo' => ['grupos' => 0, 'copias_a_mais' => 0], 'provavel' => ['grupos' => 0, 'copias_a_mais' => 0]];
        foreach ($grupos as $grupo) {
            ++$resumo[$grupo['criterio']]['grupos'];
            $resumo[$grupo['criterio']]['copias_a_mais'] += \count($grupo['ids']) - 1;
        }

        return $resumo;
    }

    /** @param array{conteudo: array{copias_a_mais: int}, provavel: array{copias_a_mais: int}} $item */
    private static function copias(array $item): int
    {
        return $item['conteudo']['copias_a_mais'] + $item['provavel']['copias_a_mais'];
    }

    private static function aviso(int $semHash): ?string
    {
        return $semHash > 0 ? sprintf(self::AVISO_SEM_HASH, $semHash) : null;
    }

    private function numero(Tenant $escritorio, Pasta $pasta): string
    {
        return $this->protecao->rotulo($escritorio, (string) $pasta->getNup(), 'sem número');
    }

    /** @return array<string, mixed> */
    private function documento(Tenant $escritorio, PastaDocumento $documento): array
    {
        $nome = $this->protecao->rotulo($escritorio, $documento->getTitulo(), 'sem nome');

        return [
            'id' => (int) $documento->getId(),
            'nome' => mb_strlen($nome) > self::TAMANHO_DO_NOME ? mb_substr($nome, 0, self::TAMANHO_DO_NOME) . '…' : $nome,
            'categoria' => $documento->getCategoria(),
            'subpasta_id' => $documento->getSecao()?->getId(),
            'enviado_em' => $documento->getCarregadoEm()->format('Y-m-d'),
            'tamanho_bytes' => $documento->getTamanhoBytes(),
        ];
    }
}
