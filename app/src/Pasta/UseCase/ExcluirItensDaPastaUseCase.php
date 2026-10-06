<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclui uma seleção de documentos e subpastas da pasta — o Del e o "Excluir (N)" da barra de
 * seleção do explorador (D4, DOC-35/57).
 *
 * A semântica é a MESMA da exclusão de um item só (`pasta_documento_delete` e
 * `pasta_secao_excluir`): a linha sai do banco agora, o arquivo sai do disco DEPOIS do COMMIT,
 * pela `RemocaoAposTransacao` (INV-6) — e é por isso que este UseCase não apaga arquivo nenhum:
 * ele devolve as chaves, e a rota as entrega à remoção com o banco já confirmado. A lixeira
 * (lápide + restaurar) é o L7, e é aqui que a troca físico→lápide vai acontecer.
 *
 * As chaves são coletadas ANTES do `remove()`, percorrendo a árvore inteira de cada subpasta: o
 * cascade do banco apaga as linhas das filhas e netas, e sem a varredura os arquivos delas ficam
 * órfãos no disco. Item dentro de subpasta selecionada não é removido "de novo" — o cascade o
 * cobre — e entra uma vez só na contagem e nas chaves.
 *
 * Auditoria: `PastaDocumento` e `PastaSecao` são `Auditavel`; o `AuditLogSubscriber` registra
 * cada linha removida no `onFlush`, inclusive as do cascade — nada a fazer aqui.
 *
 * @see MoverItensDaPastaUseCase o par deste, com a mesma seleção
 */
final class ExcluirItensDaPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     */
    public function executar(Pasta $pasta, array $documentos, array $secoes, User $autor, Tenant $tenant): ResultadoExcluirItensDaPasta
    {
        $selecao = SelecaoDeItensDaPasta::de($documentos, $secoes, $pasta, $tenant);

        // Só COLETA (leitura) — a árvore ainda está viva; depois do flush não há o que percorrer.
        $chaves    = [];
        $subpastas = 0;
        $arquivos  = 0;

        foreach ($selecao->documentos as $documento) {
            $chaves[] = ChavesDePasta::documento($documento);
            ++$arquivos;
        }

        foreach ($selecao->secoes as $secao) {
            $daArvore   = $this->coletarArvore($secao);
            $subpastas += 1 + $daArvore['subpastas'];
            $arquivos  += count($daArvore['chaves']);
            array_push($chaves, ...$daArvore['chaves']);
        }

        foreach ($selecao->documentos as $documento) {
            $this->em->remove($documento);
        }

        foreach ($selecao->secoes as $secao) {
            $this->em->remove($secao);
        }

        $this->em->flush();

        return new ResultadoExcluirItensDaPasta(
            chaves: self::semRepetidas($chaves),
            documentosRemovidos: count($selecao->documentos),
            subpastasRemovidas: $subpastas,
            arquivosRemovidos: $arquivos,
        );
    }

    /**
     * As chaves de todos os arquivos de $secao e da descendência, e quantas subpastas
     * DESCENDENTES ela tem (a própria não conta — como `contarConteudoRecursivo()`).
     *
     * O corte em `LIMITE_SEGURANCA` não é o teto de produto: é a proteção contra ciclo gravado no
     * banco, que viraria recursão infinita (o desfazer da auditoria grava `pai` pelo setter).
     *
     * @return array{chaves: list<ChaveDeArquivo>, subpastas: int}
     */
    private function coletarArvore(PastaSecao $secao, int $profundidade = 0): array
    {
        if ($profundidade >= PastaSecao::LIMITE_SEGURANCA) {
            return ['chaves' => [], 'subpastas' => 0];
        }

        $chaves    = [];
        $subpastas = 0;

        foreach ($secao->getDocumentos() as $documento) {
            $chaves[] = ChavesDePasta::documento($documento);
        }

        foreach ($secao->getFilhas() as $filha) {
            $daFilha    = $this->coletarArvore($filha, $profundidade + 1);
            $subpastas += 1 + $daFilha['subpastas'];
            array_push($chaves, ...$daFilha['chaves']);
        }

        return ['chaves' => $chaves, 'subpastas' => $subpastas];
    }

    /**
     * @param list<ChaveDeArquivo> $chaves
     *
     * @return list<ChaveDeArquivo>
     */
    private static function semRepetidas(array $chaves): array
    {
        $vistas = [];
        $unicas = [];

        foreach ($chaves as $chave) {
            $texto = $chave->comoTexto();
            if (isset($vistas[$texto])) {
                continue;
            }
            $vistas[$texto] = true;
            $unicas[]       = $chave;
        }

        return $unicas;
    }
}
