<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaVerClienteException;
use App\Cliente\UseCase\ListarDocumentosDoClienteUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;

/**
 * `documentos_do_cliente` da `ler_clientes` (`docs/specs/mcp-remoto-oauth.md`, 6.4): a lista, só com metadados, dos
 * documentos do cadastro que a pessoa vê na tela ({@see ListarDocumentosDoClienteUseCase}). Nunca sai o conteúdo, nem
 * o valor do documento (6.2, regra 4); o texto do banco passa pela {@see ProtecaoDoTextoDoChat}. O vinculado de pasta
 * em segredo de justiça ou com a aba Documentos oculta sai e é contado ({@see DocumentosDoClienteNoRecorte}).
 */
final class LerClientesNosDocumentos
{
    public function __construct(
        private readonly ListarDocumentosDoClienteUseCase $listar,
        private readonly DocumentosDoClienteNoRecorte $recorte,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    /** @throws ErroDaFerramenta */
    public function ler(int $clienteId, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $contexto = $chamada->contexto;
        try {
            $visiveis = $this->listar->executar($clienteId, $contexto->pessoa, $contexto->escritorio);
        } catch (ClienteNaoEncontradoException|SemPermissaoParaVerClienteException) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $r = $this->recorte->recortar($visiveis, $chamada, LerClientesFerramenta::NOME, 'documentos_do_cliente');
        $itens = array_map(fn (ClienteDocumento $d): array => $this->item($d, $chamada), $r['documentos']);
        $pastas = array_values(array_unique(array_filter(array_column($itens, 'pasta_id'))));

        return new RespostaDaFerramenta(
            [
                'cliente_id' => $clienteId,
                'documentos' => $itens,
                'documentos_omitidos_em_segredo' => $r['em_segredo'],
                'documentos_omitidos_sem_acesso' => $r['sem_acesso'],
            ],
            contagens: ['documentos' => \count($itens), 'em_segredo' => $r['em_segredo'], 'sem_acesso' => $r['sem_acesso']],
            ids: ['cliente' => [$clienteId], 'documento' => array_column($itens, 'id'), 'pasta' => $pastas],
        );
    }

    /** @return array<string, mixed> */
    private function item(ClienteDocumento $documento, ChamadaDoConector $chamada): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $descricao = trim((string) $documento->getDescricao());
        $pastaId = $documento->getPastaDocumento()?->getPasta()?->getId();

        return [
            'id' => (int) $documento->getId(),
            'categoria' => $documento->getCategoria(),
            'nome_original' => $this->protecao->rotulo($escritorio, $documento->getNomeOriginal(), 'sem nome'),
            'descricao' => $descricao === '' ? null : $this->protecao->rotulo($escritorio, $descricao, ''),
            'tamanho_bytes' => $documento->getTamanhoBytes(),
            'enviado_em' => $documento->getUploadedAt()->format('Y-m-d'),
            'vinculado_de_pasta' => $documento->isVinculado(),
            'pasta_id' => $pastaId === null ? null : (int) $pastaId,
        ];
    }
}
