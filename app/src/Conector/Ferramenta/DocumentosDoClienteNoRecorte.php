<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Cliente\Entity\ClienteDocumento;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\SegredoNasListas;

/**
 * O recorte do conector sobre os documentos do cadastro que a pessoa já vê na tela
 * ({@see \App\Cliente\Service\DocumentosVisiveisDoCadastro}), para `ler_clientes` (`documentos_do_cliente`) e para o
 * `atualizar_cliente` (`excluir_documento`). O anexo próprio fica sempre. O VINCULADO de pasta sai quando:
 *
 *  - a pasta tem processo em segredo de justiça (contado em `em_segredo`);
 *  - a linha da {@see TabelaDeAbaEChave} da ação recusa a pasta (aba Documentos oculta, cadeado, chave do Master):
 *    contado em `sem_acesso`.
 *
 * Um erro da guarda que não seja "não encontrado" (módulo, função desligada) segue adiante.
 */
final class DocumentosDoClienteNoRecorte
{
    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly SegredoNasListas $segredo,
    ) {
    }

    /**
     * @param list<ClienteDocumento> $documentos
     *
     * @return array{documentos: list<ClienteDocumento>, em_segredo: int, sem_acesso: int}
     *
     * @throws ErroDaFerramenta
     */
    public function recortar(array $documentos, ChamadaDoConector $chamada, string $ferramenta, string $acao): array
    {
        $foraDoSegredo = $this->segredo->tirarDe(
            $documentos,
            static fn (ClienteDocumento $d): ?int => $d->isVinculado() ? self::pastaId($d) : null,
            $chamada->contexto->escritorio,
        );
        $ficam = [];
        $semAcesso = 0;
        foreach ($foraDoSegredo['itens'] as $documento) {
            if ($documento->isVinculado() && !$this->pastaAbre($documento, $chamada, $ferramenta, $acao)) {
                ++$semAcesso;
                continue;
            }
            $ficam[] = $documento;
        }

        return ['documentos' => $ficam, 'em_segredo' => $foraDoSegredo['em_segredo'], 'sem_acesso' => $semAcesso];
    }

    private function pastaAbre(ClienteDocumento $documento, ChamadaDoConector $chamada, string $ferramenta, string $acao): bool
    {
        $pasta = $documento->getPastaDocumento()?->getPasta();
        if ($pasta === null) {
            return false;
        }
        try {
            $this->guarda->exigir($chamada, $ferramenta, $acao, $pasta);
        } catch (ErroDaFerramenta $e) {
            if ($e->motivo !== ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    private static function pastaId(ClienteDocumento $documento): ?int
    {
        $id = $documento->getPastaDocumento()?->getPasta()?->getId();

        return $id === null ? null : (int) $id;
    }
}
