<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Exception\DocumentoDoClienteNaoEncontradoException;
use App\Cliente\Service\HistoricoDoCliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclui um documento do cadastro (`cliente_documento_delete`, tela, e `atualizar_cliente` excluir_documento, conector).
 *
 * Grava o histórico do cadastro, remove a linha e dá `flush`. NÃO apaga arquivo: devolve as chaves para quem chama
 * remover DEPOIS do COMMIT (E2.5, INV-6; `RemocaoAposTransacao` na tela, `depoisDoCommit` no conector). O vinculado do
 * menu Documentos não tem arquivo próprio (o arquivo é da pasta): sai só o vínculo e não volta chave nenhuma.
 *
 * Permissão (recurso `cliente`, ação `edit`) e CSRF são de quem chama: aqui só a posse do escritório e a gravação.
 */
final class ExcluirDocumentoDoClienteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HistoricoDoCliente $historico,
    ) {
    }

    /**
     * @return list<ChaveDeArquivo> os arquivos a remover depois do COMMIT
     *
     * @throws DocumentoDoClienteNaoEncontradoException documento de outro escritório
     */
    public function executar(ClienteDocumento $documento, User $autor, Tenant $escritorio): array
    {
        if ($documento->getTenant()?->getId() !== $escritorio->getId()) {
            throw new DocumentoDoClienteNaoEncontradoException();
        }

        // As chaves antes de remover: depois do flush a entidade não diz mais de quem era.
        $chaves = $documento->isVinculado() ? [] : [ChavesDeCliente::documento($documento)];
        $this->historico->documentoRemovido($documento, $autor);
        $this->em->remove($documento);
        $this->em->flush();

        return $chaves;
    }
}
