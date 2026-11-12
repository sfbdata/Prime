<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /** Soma um voto numa opção só: o caso de uma escolha de {@see somarVotos()}. */
    public function somarVoto(OpcaoDaPauta $opcao, string $peso): void
    {
        $this->somarVotos([$opcao], $peso);
    }

    /**
     * Soma o voto de uma unidade no contador de cada opção escolhida (uma na vaga única, até N nas pautas de várias
     * vagas), por SQL direto (spec voto-assembleia-condominio, seção 2): sem passar pela unidade de trabalho do
     * Doctrine, nada vai para o audit_log. Tem de rodar na MESMA transação do INSERT da participação. A instância em
     * memória não muda (ver OpcaoDaPauta).
     *
     * O UPDATE toca TODAS as opções da pauta (as não escolhidas somam zero). Se tocasse só as escolhidas, a versão nova
     * da linha levaria o id da transação do voto (coluna oculta `xmin` do PostgreSQL), o mesmo bloco de ids da
     * participação gravada junto: um SELECT de leitura ligaria a unidade às opções. Com todas tocadas, a pauta inteira
     * leva a mesma marca e a escolha não aparece. Todas as escolhidas têm de ser da mesma pauta.
     *
     * @param list<OpcaoDaPauta> $opcoes
     * @param string             $peso   decimal não negativo com ponto (UnidadeDaAssembleia::pesoDoVoto())
     */
    public function somarVotos(array $opcoes, string $peso): void
    {
        if (1 !== preg_match('/^\d{1,8}(\.\d{1,6})?$/', $peso)) {
            throw new \InvalidArgumentException('Peso do voto inválido.');
        }
        if ([] === $opcoes) {
            throw new \InvalidArgumentException('Nenhuma opção para somar.');
        }
        $pauta = $opcoes[0]->getPauta();
        $ids = [];
        foreach ($opcoes as $opcao) {
            if ($opcao->getPauta() !== $pauta) {
                throw new \InvalidArgumentException('As opções do voto são de pautas diferentes.');
            }
            $ids[] = (int) $opcao->getId();
        }
        $afetadas = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE voto.assembleia_opcao
                SET total_unidades = total_unidades + CASE WHEN id IN (:escolhidas) THEN 1 ELSE 0 END,
                    total_peso = total_peso + CASE WHEN id IN (:escolhidas) THEN CAST(:peso AS NUMERIC) ELSE 0 END
              WHERE pauta_id = :pauta AND tenant_id = :tenant
                AND (
                    SELECT COUNT(*) FROM voto.assembleia_opcao escolhida
                     WHERE escolhida.id IN (:escolhidas) AND escolhida.pauta_id = :pauta AND escolhida.tenant_id = :tenant
                ) = :quantas',
            [
                'peso' => $peso,
                'escolhidas' => $ids,
                'quantas' => \count($ids),
                'pauta' => $pauta->getId(),
                'tenant' => $pauta->getTenant()->getId(),
            ],
            ['escolhidas' => ArrayParameterType::INTEGER],
        );
        if ($afetadas < 1) {
            throw new \RuntimeException('A opção do voto não foi encontrada.');
        }
    }
}
