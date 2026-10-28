<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PastaParaAnaliseDeParada;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Repository\PastaAndamentoRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Repository\MovimentacaoProcessoRepository;
use App\Twig\NumeroProcessoExtension;

/**
 * Junta, em lote e sem N+1, o que o {@see AnalisadorDePastasParadas} lê de cada pasta ativa (spec
 * docs/specs/pastas-paradas.md, "Cálculo"): as movimentações e o Push dos processos vinculados, o
 * último registro de pessoa, o andamento gravado, os documentos e os pagamentos.
 *
 * Uma consulta por fonte para cada bloco de pastas, sempre com o tenant explícito (o comando roda sem
 * TenantFilter). As pastas vêm da mesma consulta do relatório de pastas sem processo
 * (`PastaRepository::ativasParaAnaliseSemProcesso`), que já traz o responsável e os processos.
 */
final class ColetorDePastasParadas
{
    /** Tamanho do bloco do `IN (:ids)`, como no {@see ColetorDePastasSemProcesso}. */
    private const BLOCO = 500;

    private const CONTRATO_ASSINADO = 'REGULAR';

    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly PastaAndamentoRepository $andamentoRepository,
        private readonly MovimentacaoProcessoRepository $movimentacaoRepository,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly PastaMensagemRepository $mensagemRepository,
        private readonly PastaDocumentoRepository $documentoRepository,
        private readonly PastaPagamentoRepository $pagamentoRepository,
        private readonly ResolvedorIdentificadorDaPasta $identificador,
    ) {
    }

    /**
     * As pastas ativas do escritório (ou só as de `$ids`), prontas para a análise.
     *
     * @param list<int>|null $ids nulo = todas
     *
     * @return list<PastaParaAnaliseDeParada>
     */
    public function coletar(Tenant $tenant, ?array $ids, \DateTimeImmutable $hoje): array
    {
        $resultado = [];
        foreach (array_chunk($this->pastaRepository->ativasParaAnaliseSemProcesso($tenant, $ids), self::BLOCO) as $bloco) {
            array_push($resultado, ...$this->montarBloco($bloco, $tenant, $hoje));
        }

        return $resultado;
    }

    /**
     * @param list<Pasta> $pastas
     *
     * @return list<PastaParaAnaliseDeParada>
     */
    private function montarBloco(array $pastas, Tenant $tenant, \DateTimeImmutable $hoje): array
    {
        $ids          = array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas);
        $movimentadas = $this->movimentacoesPorPasta($pastas, $tenant);
        $andamentos   = $this->andamentoRepository->dasPastas($ids, $tenant);
        $documentos   = $this->documentoRepository->documentosParaPastasParadas($ids, $tenant);
        $pagamentos   = $this->pagamentoRepository->resumoPorPasta($ids, $tenant, $hoje);

        return array_map(
            fn (Pasta $p): PastaParaAnaliseDeParada => self::montarPasta(
                $p,
                (string) $this->identificador->para($p),
                $movimentadas[(int) $p->getId()] ?? [],
                $andamentos[(int) $p->getId()][AndamentoDaPasta::via($p)] ?? null,
                $documentos[(int) $p->getId()] ?? [],
                $pagamentos[(int) $p->getId()] ?? ['total' => 0, 'vencidos' => 0],
            ),
            $pastas,
        );
    }

    /**
     * As datas candidatas de cada pasta que não vêm do andamento: a última movimentação de cada
     * processo vinculado, a última publicação pelo número de cada um e o último registro de pessoa.
     *
     * @param list<Pasta> $pastas
     *
     * @return array<int, list<\DateTimeImmutable>> pastaId => datas
     */
    private function movimentacoesPorPasta(array $pastas, Tenant $tenant): array
    {
        $vinculos = self::vinculosDoMesmoEscritorio($pastas, $tenant);
        $processoIds = array_values(array_unique(array_map(static fn (PastaProcesso $v): int => (int) $v->getProcesso()->getId(), $vinculos)));
        $numeros     = array_map(static fn (PastaProcesso $v): string => $v->getProcesso()->getNumeroProcesso(), $vinculos);

        $porProcesso = $this->movimentacaoRepository->ultimasPorProcesso($processoIds, $tenant);
        $porNumero   = $this->publicacaoRepository->ultimasDisponibilizacoesPorNumero($tenant, $numeros);
        $registros   = $this->mensagemRepository->ultimoRegistroPorPasta(array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas), $tenant);

        $datas = [];
        foreach ($registros as $pastaId => $quando) {
            $datas[$pastaId][] = $quando;
        }
        foreach ($vinculos as $v) {
            $pastaId = (int) $v->getPasta()->getId();
            $digitos = (string) preg_replace('/\D/', '', $v->getProcesso()->getNumeroProcesso());
            foreach ([$porProcesso[(int) $v->getProcesso()->getId()] ?? null, $porNumero[$digitos] ?? null] as $quando) {
                if ($quando !== null) {
                    $datas[$pastaId][] = $quando;
                }
            }
        }

        return $datas;
    }

    /**
     * Os vínculos pasta-processo do bloco cujo processo é do mesmo escritório.
     *
     * @param list<Pasta> $pastas
     *
     * @return list<PastaProcesso>
     */
    private static function vinculosDoMesmoEscritorio(array $pastas, Tenant $tenant): array
    {
        $vinculos = [];
        foreach ($pastas as $pasta) {
            foreach ($pasta->getPastaProcessos() as $vinculo) {
                if ($vinculo->getProcesso()->getTenant()?->getId() === $tenant->getId()) {
                    $vinculos[] = $vinculo;
                }
            }
        }

        return $vinculos;
    }

    /**
     * @param list<\DateTimeImmutable>                                                $movimentacoes
     * @param list<array{titulo: string, nomeOriginal: string, categoria: string}> $documentos
     * @param array{total: int, vencidos: int}                                        $pagamentos
     */
    private static function montarPasta(Pasta $p, string $cliente, array $movimentacoes, ?PastaAndamento $andamento, array $documentos, array $pagamentos): PastaParaAnaliseDeParada
    {
        $processo    = $p->getProcessoPrincipal();
        $comNumero   = $processo !== null && $processo->getNumeroProcesso() !== '';
        $responsavel = $p->getResponsavel();
        $fase        = self::fase($andamento, AndamentoDaPasta::via($p));

        return new PastaParaAnaliseDeParada(
            id: (int) $p->getId(),
            numero: ColetorDePastasSemProcesso::numeroDaPasta($p),
            cliente: $cliente,
            responsavel: $responsavel !== null ? (string) ($responsavel->getFullName() ?? $responsavel->getEmail()) : 'Sem responsável',
            responsavelId: $responsavel?->getId(),
            abertura: $p->getDataAbertura(),
            processoNumero: $comNumero ? (new NumeroProcessoExtension())->numeroCnj($processo->getNumeroProcesso()) : null,
            processoTribunal: $comNumero && $processo->getSiglaTribunal() !== '' ? $processo->getSiglaTribunal() : null,
            processoSituacao: $comNumero ? $processo->getSituacaoProcesso() : '',
            movimentacoes: $movimentacoes,
            faseAtual: $fase['nome'],
            faseDesde: $fase['desde'],
            andamentoEncerrado: $fase['encerrado'],
            proBono: $p->isProBono(),
            contratoAssinado: $p->getSituacaoContrato() === self::CONTRATO_ASSINADO,
            totalDocumentos: \count($documentos),
            temProcuracao: self::temDocumento($documentos, 'procuracao', PastaDocumento::CATEGORIA_PROCURACAO),
            temContratoDeHonorarios: self::temDocumento($documentos, 'honorarios', PastaDocumento::CATEGORIA_CONTRATO),
            pagamentos: $pagamentos,
        );
    }

    /**
     * A fase atual do andamento gravado, quando a pasta entrou nela e se é a última da via
     * (`AndamentoDaPasta::fases`, com as fases de reativação).
     *
     * @return array{nome: ?string, desde: ?\DateTimeImmutable, encerrado: bool}
     */
    private static function fase(?PastaAndamento $andamento, string $via): array
    {
        if ($andamento === null) {
            return ['nome' => null, 'desde' => null, 'encerrado' => false];
        }
        $marcos = $andamento->getMarcos();
        $fases  = AndamentoDaPasta::fases($via, $marcos['extra']);
        $atual  = $andamento->getAtual();
        $em     = $marcos['em'][$atual] ?? null;

        return [
            'nome'      => $fases[$atual][0] ?? null,
            'desde'     => $em !== null && $em !== '' ? new \DateTimeImmutable($em) : null,
            'encerrado' => $atual === \count($fases) - 1,
        ];
    }

    /**
     * O documento está juntado pela categoria escolhida no upload ou, pelo título ou nome do arquivo,
     * pelo padrão do catálogo (`CatalogoDeDocumentos::TIPOS`), como no "Sugerir documentos".
     *
     * @param list<array{titulo: string, nomeOriginal: string, categoria: string}> $documentos
     */
    private static function temDocumento(array $documentos, string $tipo, string $categoria): bool
    {
        $padrao = CatalogoDeDocumentos::TIPOS[$tipo][1];
        foreach ($documentos as $d) {
            if ($d['categoria'] === $categoria
                || preg_match($padrao, SimilaridadeDeNomes::normalizar($d['titulo'])) === 1
                || preg_match($padrao, SimilaridadeDeNomes::normalizar($d['nomeOriginal'])) === 1) {
                return true;
            }
        }

        return false;
    }
}
