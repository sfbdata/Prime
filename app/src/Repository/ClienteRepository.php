<?php

namespace App\Repository;

use App\Entity\Cliente\Cliente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cliente>
 */
class ClienteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cliente::class);
    }

    public function save(Cliente $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Cliente $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return Cliente[]
     */
    public function findByFilters(array $filters): array
    {
        $qb = $this->createQueryBuilder('c');

        if (!empty($filters['tipo'])) {
            if ($filters['tipo'] === 'PF') {
                $qb->andWhere('c INSTANCE OF App\Entity\Cliente\ClientePF');
            } elseif ($filters['tipo'] === 'PJ') {
                $qb->andWhere('c INSTANCE OF App\Entity\Cliente\ClientePJ');
            }
        }

        if (!empty($filters['celular'])) {
            $qb->andWhere('c.telefoneCelular LIKE :celular')
               ->setParameter('celular', '%' . $filters['celular'] . '%');
        }

        $results = $qb->orderBy('c.id', 'DESC')->getQuery()->getResult();

        // Filtrar por nome (nomeCompleto para PF, razaoSocial para PJ)
        if (!empty($filters['nome'])) {
            $nome = mb_strtolower($filters['nome']);
            $results = array_filter($results, function ($cliente) use ($nome) {
                if ($cliente instanceof \App\Entity\Cliente\ClientePF) {
                    return str_contains(mb_strtolower($cliente->getNomeCompleto()), $nome);
                } else {
                    return str_contains(mb_strtolower($cliente->getRazaoSocial()), $nome);
                }
            });
        }

        // Filtrar por documento (cpf para PF, cnpj para PJ)
        if (!empty($filters['documento'])) {
            $documento = $filters['documento'];
            $results = array_filter($results, function ($cliente) use ($documento) {
                if ($cliente instanceof \App\Entity\Cliente\ClientePF) {
                    return str_contains($cliente->getCpf(), $documento);
                } else {
                    return str_contains($cliente->getCnpj(), $documento);
                }
            });
        }

        return array_values($results);
    }

    /**
     * @return array<string, string>
     */
    public function findAllNomes(): array
    {
        $clientes = $this->findAll();
        $nomes = [];
        foreach ($clientes as $cliente) {
            if ($cliente instanceof \App\Entity\Cliente\ClientePF) {
                $nomes[$cliente->getNomeCompleto()] = $cliente->getNomeCompleto();
            } else {
                $nomes[$cliente->getRazaoSocial()] = $cliente->getRazaoSocial();
            }
        }
        return $nomes;
    }

    /**
     * @return array<string, string>
     */
    public function findAllDocumentos(): array
    {
        $clientes = $this->findAll();
        $documentos = [];
        foreach ($clientes as $cliente) {
            if ($cliente instanceof \App\Entity\Cliente\ClientePF) {
                $doc = $cliente->getCpf();
                if ($doc) {
                    $documentos[$doc] = $doc;
                }
            } else {
                $doc = $cliente->getCnpj();
                if ($doc) {
                    $documentos[$doc] = $doc;
                }
            }
        }
        return $documentos;
    }

    /**
     * @return array<string, string>
     */
    public function findAllCelulares(): array
    {
        $clientes = $this->findAll();
        $celulares = [];
        foreach ($clientes as $cliente) {
            $celular = $cliente->getTelefoneCelular();
            if ($celular) {
                $celulares[$celular] = $celular;
            }
        }
        return $celulares;
    }
}
