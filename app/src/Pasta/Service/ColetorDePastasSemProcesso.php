<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PastaParaAnaliseSemProcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Repository\PastaRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Twig\NumeroProcessoExtension;

/**
 * Junta, em lote e sem N+1, o que o {@see AnalisadorDePastasSemProcesso} lê de cada pasta (desenho
 * 1.2.3, `spAnalisar` L4515-4517): os registros (texto sem HTML, rótulo "Registro de Fulano"), os
 * documentos ("Documento": título, nome e categoria), as metas ("Meta": título e descrição) e o
 * checklist ("Checklist": título). Uma consulta por fonte para cada bloco de pastas, tenant pela pasta.
 */
final class ColetorDePastasSemProcesso
{
    /** Tamanho do bloco do `IN (:ids)`: escritório grande não vira uma consulta com milhares de ids. */
    private const BLOCO = 500;

    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly PastaMensagemRepository $mensagemRepository,
        private readonly PastaDocumentoRepository $documentoRepository,
        private readonly TarefaRepository $tarefaRepository,
        private readonly PastaChecklistItemRepository $checklistRepository,
        private readonly ResolvedorIdentificadorDaPasta $identificador,
    ) {
    }

    /**
     * As pastas ativas do escritório (ou só as de `$ids`), prontas para a análise.
     *
     * @param list<int>|null $ids nulo = todas
     *
     * @return list<PastaParaAnaliseSemProcesso>
     */
    public function coletar(Tenant $tenant, ?array $ids = null): array
    {
        $resultado = [];
        foreach (array_chunk($this->pastaRepository->ativasParaAnaliseSemProcesso($tenant, $ids), self::BLOCO) as $bloco) {
            array_push($resultado, ...$this->montarBloco($bloco, $tenant));
        }

        return $resultado;
    }

    /**
     * As fontes de uma pasta só (qualquer situação): é o que a decisão de "Administrativo sem
     * processo" precisa para saber quais termos judiciais a pasta tinha (L4510).
     *
     * @return list<array{rotulo: string, texto: string}>
     */
    public function fontesDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        $id = (int) $pasta->getId();

        return self::fontes(
            $id,
            $this->mensagemRepository->registrosParaAnaliseSemProcesso([$id], $tenant),
            $this->documentoRepository->textosParaAnaliseSemProcesso([$id], $tenant),
            $this->tarefaRepository->textosParaAnaliseSemProcesso([$id], $tenant),
            $this->checklistRepository->titulosParaAnaliseSemProcesso([$id], $tenant),
        );
    }

    /**
     * @param list<Pasta> $pastas
     *
     * @return list<PastaParaAnaliseSemProcesso>
     */
    private function montarBloco(array $pastas, Tenant $tenant): array
    {
        $ids        = array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas);
        $registros  = $this->mensagemRepository->registrosParaAnaliseSemProcesso($ids, $tenant);
        $documentos = $this->documentoRepository->textosParaAnaliseSemProcesso($ids, $tenant);
        $metas      = $this->tarefaRepository->textosParaAnaliseSemProcesso($ids, $tenant);
        $checklist  = $this->checklistRepository->titulosParaAnaliseSemProcesso($ids, $tenant);

        return array_map(
            fn (Pasta $p): PastaParaAnaliseSemProcesso => self::montarPasta(
                $p,
                (string) $this->identificador->para($p),
                self::fontes((int) $p->getId(), $registros, $documentos, $metas, $checklist),
                $registros[(int) $p->getId()] ?? [],
                \count($documentos[(int) $p->getId()] ?? []),
            ),
            $pastas,
        );
    }

    /**
     * @param list<array{rotulo: string, texto: string}>                                $fontes
     * @param list<array{conteudo: string, autor: string, em: \DateTimeImmutable}>     $registros
     */
    private static function montarPasta(Pasta $p, string $cliente, array $fontes, array $registros, int $totalDocumentos): PastaParaAnaliseSemProcesso
    {
        $processo    = $p->getProcessoPrincipal();
        $responsavel = $p->getResponsavel();

        return new PastaParaAnaliseSemProcesso(
            id: (int) $p->getId(),
            numero: self::numeroDaPasta($p),
            cliente: $cliente,
            responsavel: $responsavel !== null ? (string) ($responsavel->getFullName() ?? $responsavel->getEmail()) : 'Sem responsável',
            responsavelId: $responsavel?->getId(),
            processoNumero: $processo !== null ? (new NumeroProcessoExtension())->numeroCnj($processo->getNumeroProcesso()) : null,
            processoTribunal: $processo !== null && $processo->getSiglaTribunal() !== '' ? $processo->getSiglaTribunal() : null,
            marcada: $p->isAdministrativa(),
            fontes: $fontes,
            registros: array_map(static fn (array $r): array => ['em' => $r['em'], 'autor' => $r['autor']], $registros),
            totalDocumentos: $totalDocumentos,
        );
    }

    public static function numeroDaPasta(Pasta $p): string
    {
        $nup = $p->getNup();

        return $nup !== null && $nup !== '' ? $nup : '#' . $p->getId();
    }

    /**
     * Na ordem do desenho: registros, documentos, metas, checklist (a primeira fonte que casa é a
     * que vira evidência, L4521).
     *
     * @param array<int, list<array{conteudo: string, autor: string, em: \DateTimeImmutable}>> $registros
     * @param array<int, list<string>>                                                         $documentos
     * @param array<int, list<string>>                                                         $metas
     * @param array<int, list<string>>                                                         $checklist
     *
     * @return list<array{rotulo: string, texto: string}>
     */
    private static function fontes(int $id, array $registros, array $documentos, array $metas, array $checklist): array
    {
        $fontes = [];
        foreach ($registros[$id] ?? [] as $r) {
            $fontes[] = ['rotulo' => 'Registro de ' . ($r['autor'] !== '' ? $r['autor'] : '?'), 'texto' => self::semHtml($r['conteudo'])];
        }
        foreach (['Documento' => $documentos, 'Meta' => $metas, 'Checklist' => $checklist] as $rotulo => $porPasta) {
            foreach ($porPasta[$id] ?? [] as $texto) {
                $fontes[] = ['rotulo' => $rotulo, 'texto' => $texto];
            }
        }

        return $fontes;
    }

    /** `replace(/<[^>]+>/g, ' ')` do desenho, e as entidades HTML viram o caractere. */
    private static function semHtml(string $html): string
    {
        return html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
