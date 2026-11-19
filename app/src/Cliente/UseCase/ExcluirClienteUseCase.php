<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\Entity\Carteira;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclui o cliente do escritório (`cliente_delete`, tela, e `atualizar_cliente` excluir_cliente, conector). Sem volta.
 *
 * O que sai junto vem do mapeamento e das chaves do banco: os documentos do cadastro (cascata do ORM), os vínculos com
 * as pastas (`pasta_cliente`, cascata) e, nas pastas em que era o principal, `cliente_principal_id` vira nulo. A única
 * chave que recusa é `cobranca_carteira.cliente_id` (NO ACTION): quem chama pergunta {@see temCarteiraDeCobranca()}
 * antes, para não deixar a FK estourar (ela fecha o EntityManager); a tela ainda pega a
 * {@see ForeignKeyConstraintViolationException} que escapar.
 *
 * NÃO apaga arquivo: devolve as chaves dos arquivos próprios do cadastro para quem chama remover DEPOIS do COMMIT
 * (E2.5, INV-6). O vinculado do menu Documentos não tem arquivo próprio: o da pasta fica.
 *
 * Permissão (recurso `cliente`, ação `delete`) e CSRF são de quem chama: aqui só a posse do escritório e a gravação.
 */
final class ExcluirClienteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClienteRepository $clientes,
    ) {
    }

    /** O cliente tem alguma carteira de cobrança (a única chave do banco que recusa a exclusão). */
    public function temCarteiraDeCobranca(Cliente $cliente, Tenant $escritorio): bool
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Carteira::class, 'c')
            ->where('c.cliente = :cliente')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('cliente', $cliente)
            ->setParameter('tenant', $escritorio)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @return list<ChaveDeArquivo> os arquivos a remover depois do COMMIT
     *
     * @throws ClienteNaoEncontradoException       de outro escritório ou inexistente
     * @throws ForeignKeyConstraintViolationException o banco recusou (carteira de cobrança)
     */
    public function executar(int $clienteId, Tenant $escritorio): array
    {
        $cliente = $this->clientes->findOneBy(['id' => $clienteId, 'tenant' => $escritorio]);
        if ($cliente === null || $cliente->getTenant()?->getId() !== $escritorio->getId()) {
            throw new ClienteNaoEncontradoException($clienteId);
        }

        // As chaves antes de remover; os arquivos só saem depois de o banco aceitar (E2.5).
        $chaves = $this->chavesDosArquivosProprios($cliente);
        $this->em->remove($cliente);
        $this->em->flush();
        // O cliente removido ainda é apontado por pastas e vínculos já carregados na memória de quem chama (o banco
        // já pôs `cliente_principal_id` em nulo); um `flush` depois deles poderia falhar. Como o
        // `ExcluirProcessoUseCase`: quem chama relê o que ainda usa, e as entidades recebidas ficam desanexadas.
        $this->em->clear();

        return $chaves;
    }

    /**
     * @return list<ChaveDeArquivo>
     */
    private function chavesDosArquivosProprios(Cliente $cliente): array
    {
        $chaves = [];
        foreach ($cliente->getDocumentos() as $documento) {
            if (!$documento->isVinculado()) {
                $chaves[] = ChavesDeCliente::documento($documento);
            }
        }

        return $chaves;
    }
}
