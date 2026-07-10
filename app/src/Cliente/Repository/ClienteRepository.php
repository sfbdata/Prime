<?php

namespace App\Cliente\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Tenant\Tenant;
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
                $qb->andWhere('c INSTANCE OF App\Cliente\Entity\ClientePF');
            } elseif ($filters['tipo'] === 'PJ') {
                $qb->andWhere('c INSTANCE OF App\Cliente\Entity\ClientePJ');
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
                if ($cliente instanceof ClientePF) {
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
                if ($cliente instanceof ClientePF) {
                    return str_contains($cliente->getCpf(), $documento);
                } else {
                    return str_contains($cliente->getCnpj(), $documento);
                }
            });
        }

        return array_values($results);
    }

    /**
     * Opções (nome de exibição => id) dos clientes do tenant, ordenadas por nome, para popular o
     * ChoiceType de credor ao criar uma Carteira de Cobrança (Cobranças 8B). Tenant SEMPRE explícito
     * (isolamento multi-tenant). Como o nome vem de subtipos distintos (PF: nomeCompleto, PJ:
     * razaoSocial) via `getNomeExibicao()`, hidratam-se as entidades e o mapa é montado em PHP.
     *
     * @return array<string, int>
     */
    public function opcoesDoTenant(Tenant $tenant): array
    {
        $clientes = $this->findBy(['tenant' => $tenant]);

        $opcoes = [];
        foreach ($clientes as $cliente) {
            $id = $cliente->getId();
            if ($id !== null) {
                $opcoes[$cliente->getNomeExibicao()] = $id;
            }
        }

        uksort($opcoes, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $opcoes;
    }

    /**
     * @return array<string, string>
     */
    public function findAllNomes(): array
    {
        $clientes = $this->findAll();
        $nomes = [];
        foreach ($clientes as $cliente) {
            if ($cliente instanceof ClientePF) {
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
            if ($cliente instanceof ClientePF) {
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
