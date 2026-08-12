<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioLinha;
use App\Cobranca\Enum\BlocoRelatorio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelatorioLinha>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class RelatorioLinhaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelatorioLinha::class);
    }

    public function salvar(RelatorioLinha $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Quantas linhas de cada balde — o lado esquerdo da reconciliação da SPEC §5.4.
     *
     * A soma dos valores devolvidos tem que dar `RelatorioImportado::getLinhasTotal()`. O teste
     * assere essa IDENTIDADE, nunca contagens literais: elas variam por arquivo, e fixá-las na spec
     * foi o erro que derrubou duas versões dela.
     *
     * @return array<string, int> chave = valor do enum BlocoRelatorio
     */
    public function contarPorBloco(RelatorioImportado $relatorio): array
    {
        /** @var list<array{bloco: BlocoRelatorio, total: int}> $linhas */
        $linhas = $this->createQueryBuilder('l')
            ->select('l.bloco AS bloco', 'COUNT(l.id) AS total')
            ->andWhere('l.relatorio = :relatorio')
            ->setParameter('relatorio', $relatorio)
            ->groupBy('l.bloco')
            ->getQuery()
            ->getResult();

        $porBloco = [];

        foreach ($linhas as $linha) {
            $porBloco[$linha['bloco']->value] = (int) $linha['total'];
        }

        return $porBloco;
    }

    /**
     * As unidades distintas já espelhadas, por carteira — insumo do nível 3 da atribuição de
     * carteira (SPEC §4.4), usado para descobrir de qual carteira é um arquivo que não se identifica
     * nem pelo nome nem pela alíquota de honorários.
     *
     * @return array<int, list<string>> carteiraId => unidades
     */
    public function unidadesPorCarteira(): array
    {
        /** @var list<array{carteiraId: int, unidade: string}> $linhas */
        $linhas = $this->createQueryBuilder('l')
            ->select('IDENTITY(r.carteira) AS carteiraId', 'l.unidade AS unidade')
            ->join('l.relatorio', 'r')
            ->andWhere('l.bloco = :bloco')
            ->andWhere('l.unidade IS NOT NULL')
            ->setParameter('bloco', BlocoRelatorio::Dados)
            ->groupBy('carteiraId', 'l.unidade')
            ->getQuery()
            ->getResult();

        $porCarteira = [];

        foreach ($linhas as $linha) {
            $porCarteira[(int) $linha['carteiraId']][] = $linha['unidade'];
        }

        return $porCarteira;
    }

    /**
     * As somas das colunas monetárias das linhas de DADOS — o lado esquerdo da reconciliação interna
     * (INV-T1): tem que bater com o totalizador da própria planilha.
     *
     * Só o bloco `Dados` entra: incluir o totalizador contaria o mesmo dinheiro duas vezes, que é a
     * classe de defeito que esta frente inteira existe para consertar.
     *
     * @return array{valor: int, juros: int, multa: int, correcao: int, honorarios: int, total: int}
     */
    public function somarDados(RelatorioImportado $relatorio): array
    {
        /** @var array<string, string|int|null> $soma */
        $soma = $this->createQueryBuilder('l')
            ->select(
                'COALESCE(SUM(l.valor), 0) AS valor',
                'COALESCE(SUM(l.juros), 0) AS juros',
                'COALESCE(SUM(l.multa), 0) AS multa',
                'COALESCE(SUM(l.correcao), 0) AS correcao',
                'COALESCE(SUM(l.honorarios), 0) AS honorarios',
                'COALESCE(SUM(l.total), 0) AS total',
            )
            ->andWhere('l.relatorio = :relatorio')
            ->andWhere('l.bloco = :bloco')
            ->setParameter('relatorio', $relatorio)
            ->setParameter('bloco', BlocoRelatorio::Dados)
            ->getQuery()
            ->getSingleResult();

        return [
            'valor' => (int) $soma['valor'],
            'juros' => (int) $soma['juros'],
            'multa' => (int) $soma['multa'],
            'correcao' => (int) $soma['correcao'],
            'honorarios' => (int) $soma['honorarios'],
            'total' => (int) $soma['total'],
        ];
    }
}
