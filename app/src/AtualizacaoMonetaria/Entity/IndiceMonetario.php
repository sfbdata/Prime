<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Entity;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use App\AtualizacaoMonetaria\Service\VariacaoPercentual;
use Doctrine\ORM\Mapping as ORM;

/**
 * Variação mensal de um índice oficial (INPC, IPCA ou taxa legal da Lei 14.905/2024), como o Banco
 * Central publica no SGS.
 *
 * ⚠️ **Sem `tenant_id`, de propósito** (spec §7.1). É a única exceção à regra de isolamento
 * multi-tenant do projeto, e ela não é frouxidão: índice oficial de inflação é dado público,
 * idêntico para todo escritório. Replicá-lo por tenant não protegeria nada e abriria a porta para
 * dois escritórios calcularem o mesmo mês com números diferentes. Tratamento: tabela global de
 * referência, **somente leitura para a aplicação** — só o importador
 * (`app:importar-indices-monetarios`) escreve aqui, e nenhum dado de tenant entra.
 */
#[ORM\Entity(repositoryClass: IndiceMonetarioRepository::class)]
#[ORM\Table(name: 'indice_monetario')]
// O UNIQUE (serie, competencia) é também o índice das duas consultas quentes — "toda a série X em
// ordem" (montagem da TabelaIndices) e "última competência publicada de X" —, nessa mesma ordem de
// colunas. Um #[ORM\Index] igual seria redundante: o Doctrine o descarta ao gerar a migration e o
// mapeamento passaria a divergir do banco a cada `make:migration`.
#[ORM\UniqueConstraint(name: 'uniq_indice_monetario_serie_competencia', columns: ['serie', 'competencia'])]
class IndiceMonetario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 20, enumType: SerieIndice::class)]
        private SerieIndice $serie,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $competencia,
        /** Variação percentual do mês, como o BCB publica (ex.: '0.14' = 0,14%). String, nunca float. */
        #[ORM\Column(type: 'decimal', precision: 12, scale: 6)]
        private string $variacaoPct,
        #[ORM\Column(type: 'string', length: 40)]
        private string $fonte,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $importadoEm,
    ) {
        $this->competencia = self::normalizarCompetencia($competencia);
        $this->variacaoPct = VariacaoPercentual::canonizar($variacaoPct);
    }

    /**
     * Competência é sempre o dia 1 do mês. Recusar em vez de normalizar em silêncio: um dia 15
     * chegando aqui significa que o chamador confundiu "data do valor" com "mês de referência", e
     * calar isso duplicaria a linha do mesmo mês.
     */
    private static function normalizarCompetencia(\DateTimeImmutable $competencia): \DateTimeImmutable
    {
        if ($competencia->format('d') !== '01') {
            throw new \InvalidArgumentException(sprintf(
                'Competência deve ser o dia 1 do mês, recebido %s.',
                $competencia->format('Y-m-d'),
            ));
        }

        return $competencia->setTime(0, 0);
    }

    /**
     * O índice publicado é outro? Responde sem mexer em nada — é o que permite ao importador
     * contar as revisões no `--dry-run` sem sujar o EntityManager.
     */
    public function variacaoDivergeDe(string $variacaoPct): bool
    {
        return VariacaoPercentual::canonizar($variacaoPct)
            !== VariacaoPercentual::canonizar($this->variacaoPct);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSerie(): SerieIndice
    {
        return $this->serie;
    }

    public function getCompetencia(): \DateTimeImmutable
    {
        return $this->competencia;
    }

    /** Chave 'Y-m-01' usada como índice na TabelaIndices e no mapa do importador. */
    public function chaveCompetencia(): string
    {
        return $this->competencia->format('Y-m-01');
    }

    public function getVariacaoPct(): string
    {
        return $this->variacaoPct;
    }

    public function getFonte(): string
    {
        return $this->fonte;
    }

    public function getImportadoEm(): \DateTimeImmutable
    {
        return $this->importadoEm;
    }

    /**
     * Revisão de índice já publicado (o IBGE faz). Devolve `true` quando o número mudou de fato —
     * é o gancho que permite ao importador REGISTRAR a alteração em vez de sobrescrever calado,
     * como exige a spec §8.
     */
    public function atualizarVariacao(string $variacaoPct, \DateTimeImmutable $importadoEm): bool
    {
        if ($this->variacaoDivergeDe($variacaoPct) === false) {
            // Nada muda, nem o carimbo: é o que faz a reimportação mensal ser um no-op de verdade,
            // sem UPDATE inútil em ~1.100 linhas e sem `importado_em` mentindo que houve revisão.
            return false;
        }

        $this->variacaoPct = VariacaoPercentual::canonizar($variacaoPct);
        $this->importadoEm = $importadoEm;

        return true;
    }
}
