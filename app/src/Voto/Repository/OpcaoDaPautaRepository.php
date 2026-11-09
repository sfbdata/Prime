<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpcaoDaPauta>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class OpcaoDaPautaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpcaoDaPauta::class);
    }

    public function salvar(OpcaoDaPauta $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(OpcaoDaPauta $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Guarda de posse: a opção pelo id, só se for da pauta (e do escritório dela). */
    public function buscarDaPauta(int $id, PautaDaAssembleia $pauta): ?OpcaoDaPauta
    {
        return $this->findOneBy(['id' => $id, 'pauta' => $pauta, 'tenant' => $pauta->getTenant()]);
    }

    /**
     * Soma um voto no contador da opção, por SQL direto (spec voto-assembleia-condominio, seção 2): sem passar pela
     * unidade de trabalho do Doctrine, nada vai para o audit_log. Tem de rodar na MESMA transação do INSERT da
     * participação. A instância em memória não muda (ver OpcaoDaPauta).
     *
     * O UPDATE toca TODAS as opções da pauta (as outras somam zero). Se tocasse só a escolhida, a versão nova da linha
     * levaria o id da transação do voto (coluna oculta `xmin` do PostgreSQL), o mesmo bloco de ids da participação
     * gravada junto: um SELECT de leitura ligaria a unidade à opção. Com todas tocadas, a pauta inteira leva a mesma
     * marca e a escolha não aparece.
     *
     * @param string $peso decimal não negativo com ponto (UnidadeDaAssembleia::pesoDoVoto())
     */
    public function somarVoto(OpcaoDaPauta $opcao, string $peso): void
    {
        if (1 !== preg_match('/^\d{1,8}(\.\d{1,6})?$/', $peso)) {
            throw new \InvalidArgumentException('Peso do voto inválido.');
        }
        $afetadas = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE voto.assembleia_opcao
                SET total_unidades = total_unidades + CASE WHEN id = :opcao THEN 1 ELSE 0 END,
                    total_peso = total_peso + CASE WHEN id = :opcao THEN CAST(:peso AS NUMERIC) ELSE 0 END
              WHERE pauta_id = :pauta AND tenant_id = :tenant
                AND EXISTS (
                    SELECT 1 FROM voto.assembleia_opcao escolhida
                     WHERE escolhida.id = :opcao AND escolhida.pauta_id = :pauta AND escolhida.tenant_id = :tenant
                )',
            [
                'peso' => $peso,
                'opcao' => $opcao->getId(),
                'pauta' => $opcao->getPauta()->getId(),
                'tenant' => $opcao->getTenant()->getId(),
            ],
        );
        if ($afetadas < 1) {
            throw new \RuntimeException('A opção do voto não foi encontrada.');
        }
    }
}
