<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Pasta\UseCase\ExcluirDeVezDaLixeiraUseCase;
use App\Shared\Doctrine\Filter\AcessoALixeira;

/**
 * `excluir_de_vez` da `documentos_e_pecas` (aba Documentos, 6.6, PR 14): apaga PARA SEMPRE, linha e arquivo, documentos
 * e subpastas que estão NA LIXEIRA da pasta, pelo {@see ExcluirDeVezDaLixeiraUseCase} da tela. Sempre por proposta
 * (6.7): é irreversível, e o resumo diz isso e lista os itens.
 *
 * As conferências são as da rota da tela (`pasta_documentos_excluir_de_vez`): cada id NA LIXEIRA desta pasta e deste
 * escritório, achado com a lixeira visível; contrato de honorários com o Financeiro oculto, selecionado ou em qualquer
 * nível da subárvore de uma subpasta, responde "não encontrado" (não dá para desfazer). A permissão (recurso `pasta`
 * de editar, aba Documentos) a {@see DocumentosEPecasFerramenta} já conferiu antes; na confirmação, tudo de novo.
 *
 * O arquivo sai DEPOIS do COMMIT da confirmação (INV-6): a gravação usa o caminho sem remoção do UseCase, que só apaga
 * as linhas e devolve as chaves livres, e o resultado leva a remoção como passo depois do COMMIT
 * ({@see ResultadoDaConfirmacao::$depoisDoCommit}). Se a confirmação desfizer, nenhum arquivo sai.
 */
final class DocumentosEPecasNaLixeira implements DocumentosEPecasGrupo
{
    public const ACAO = 'excluir_de_vez.excluir';

    public function __construct(
        private readonly DocumentosEPecasResolvedor $resolvedor,
        private readonly AcessoALixeira $lixeira,
        private readonly ExcluirDeVezDaLixeiraUseCase $excluirDeVez,
    ) {
    }

    public function chaves(): array
    {
        return ['excluir_de_vez'];
    }

    public function conferir(string $acao, array $campos, DocumentosEPecasContexto $contexto): array
    {
        if ($acao !== self::ACAO) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $this->lixeira->comLixeiraVisivel(function () use ($campos, $contexto): array {
            $documentos = $this->resolvedor->documentos($campos['documentos'] ?? [], $contexto, true);
            $subpastas = $this->resolvedor->subpastas($campos['subpastas'] ?? [], $contexto, true);
            $daArvore = $this->documentosDaArvore($subpastas);
            if (\count(VisibilidadeDasAbasDaPasta::documentosVisiveis($daArvore, $contexto->abasOcultas)) !== \count($daArvore)) {
                throw ErroDaFerramenta::naoEncontrado();
            }

            return ['documentos' => $documentos, 'subpastas' => $subpastas];
        });
    }

    public function resumir(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): string
    {
        $escritorio = $contexto->escritorio;
        $partes = [];
        if ($achados['documentos'] !== []) {
            $partes[] = sprintf('%d documento(s): %s', \count($achados['documentos']), $this->resolvedor->nomesDosDocumentos($escritorio, $achados['documentos']));
        }
        if ($achados['subpastas'] !== []) {
            $partes[] = sprintf('%d subpasta(s), com tudo o que está dentro delas: %s', \count($achados['subpastas']), $this->resolvedor->nomesDasSubpastas($escritorio, $achados['subpastas']));
        }

        return sprintf(
            "Excluir DE VEZ, da lixeira da pasta %s, %s.\nNão dá para desfazer: os itens e os arquivos saem do sistema e não voltam pela lixeira.",
            $this->resolvedor->nome($escritorio, (string) $contexto->pasta->getNup()),
            implode('; ', $partes),
        );
    }

    public function gravar(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $ids = [
            'documento' => array_map(static fn (PastaDocumento $d): int => (int) $d->getId(), $achados['documentos']),
            'subpasta' => array_map(static fn (PastaSecao $s): int => (int) $s->getId(), $achados['subpastas']),
        ];
        // Os ids antes: a purga dá `clear()` no EntityManager e as entidades achadas ficam desanexadas.
        $r = DocumentosEPecasRecusa::traduzindo(fn () => $this->excluirDeVez->excluirDoBanco($contexto->pasta, $achados['documentos'], $achados['subpastas'], $contexto->escritorio));
        $dados = [
            'excluidos_de_vez' => ['documentos' => $r->documentosRemovidos, 'subpastas' => $r->secoesRemovidas],
            'pulados' => ['documentos' => $r->documentosPulados, 'subpastas' => $r->secoesPuladas],
        ];

        return DocumentosEPecasResultado::de(
            $contexto,
            $dados,
            $ids,
            ['documentos' => $r->documentosRemovidos, 'subpastas' => $r->secoesRemovidas],
            $this->remocaoDepoisDoCommit($r->arquivosARemover),
        );
    }

    /**
     * A remoção dos arquivos livres, para depois do COMMIT da confirmação. Só chaves, nenhuma entidade.
     *
     * @param list<\App\Shared\Armazenamento\ChaveDeArquivo> $chaves
     *
     * @return (\Closure(): void)|null
     */
    private function remocaoDepoisDoCommit(array $chaves): ?\Closure
    {
        if ($chaves === []) {
            return null;
        }
        $excluirDeVez = $this->excluirDeVez;

        return static function () use ($excluirDeVez, $chaves): void {
            $excluirDeVez->removerArquivos($chaves);
        };
    }

    /**
     * Todos os documentos dentro das subpastas (subárvore inteira, até o limite de segurança), como a rota da tela, para
     * conferir o contrato oculto. Chamar com a lixeira visível: o que está dentro também está na lixeira.
     *
     * @param list<PastaSecao> $subpastas
     *
     * @return list<PastaDocumento>
     */
    private function documentosDaArvore(array $subpastas, int $profundidade = 0): array
    {
        if ($profundidade >= PastaSecao::LIMITE_SEGURANCA) {
            return [];
        }
        $documentos = [];
        foreach ($subpastas as $subpasta) {
            array_push($documentos, ...$subpasta->getDocumentos()->toArray());
            array_push($documentos, ...$this->documentosDaArvore($subpasta->getFilhas()->toArray(), $profundidade + 1));
        }

        return $documentos;
    }
}
