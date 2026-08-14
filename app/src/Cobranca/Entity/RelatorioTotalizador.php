<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\FormaTotalizador;
use App\Cobranca\Repository\RelatorioTotalizadorRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O rodapé somado da própria planilha (SPEC espelho §4.2 e §4.3).
 *
 * INV-T1: **é a prova mais barata que existe de que o leitor leu certo.** Antes de conferir o
 * sistema contra a contabilidade, o espelho confere contra ele mesmo: a soma das linhas de dado tem
 * que bater com este totalizador. Medido no TL1 de 12/08, sobre 4.123 linhas —
 * `H 44.197.594 · I 15.147.395 · J 883.952 · K 0 · L 11.714.735 · M 71.943.676`, idêntico ao rodapé,
 * e `H+I+J+K+L == M`. Se essa conta não fechar, o leitor está errado e nenhuma conferência posterior
 * vale.
 *
 * INV-T2: **o bloco tem DUAS formas de layout**, e confundi-las grava valor nulo em silêncio:
 *  - `Larga` — 15 colunas, igual ao bloco de dados: rótulo em A, valores em H..M. É a linha
 *    "Total inadimplência das unidades".
 *  - `Estreita` — 7 colunas: rótulo em A, valores em B..G. São as linhas por classe de conta e o
 *    "Total de inadimplência".
 *
 * O leitor normaliza as duas para as mesmas colunas, mas `forma` registra de qual veio — sem isso
 * não há como provar que a normalização acertou.
 *
 * Não implementa `Auditavel` — ver INV-E2 em {@see RelatorioImportado}.
 */
#[ORM\Entity(repositoryClass: RelatorioTotalizadorRepository::class)]
#[ORM\Table(name: 'cobranca_relatorio_totalizador')]
#[ORM\Index(name: 'idx_cobranca_relatorio_totalizador_relatorio', columns: ['relatorio_id'])]
class RelatorioTotalizador implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: RelatorioImportado::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?RelatorioImportado $relatorio = null;

    #[ORM\Column(type: 'integer')]
    private int $numeroLinha = 0;

    /** De qual layout esta linha veio — ver INV-T2. */
    #[ORM\Column(length: 20, enumType: FormaTotalizador::class)]
    private FormaTotalizador $forma = FormaTotalizador::Estreita;

    /** O texto da coluna A: a classe de conta, ou "Total de inadimplência". */
    #[ORM\Column(length: 255)]
    private string $rotulo = '';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valor = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $juros = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $multa = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $correcao = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $honorarios = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $total = null;

    /**
     * A segunda coluna de dinheiro do rodapé de RECEITAS (`Valor recebido`). `null` nos outros três
     * layouts, que não a têm.
     *
     * 🔑 Coluna própria, e não `total` "porque cabe" — ver o mesmo aviso em {@see RelatorioLinha}.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valorRecebido = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValorRecebido(): ?int
    {
        return $this->valorRecebido;
    }

    public function setValorRecebido(?int $valorRecebido): self
    {
        $this->valorRecebido = $valorRecebido;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getRelatorio(): ?RelatorioImportado
    {
        return $this->relatorio;
    }

    public function setRelatorio(RelatorioImportado $relatorio): self
    {
        $this->relatorio = $relatorio;

        return $this;
    }

    public function getNumeroLinha(): int
    {
        return $this->numeroLinha;
    }

    public function setNumeroLinha(int $numeroLinha): self
    {
        $this->numeroLinha = $numeroLinha;

        return $this;
    }

    public function getForma(): FormaTotalizador
    {
        return $this->forma;
    }

    public function setForma(FormaTotalizador $forma): self
    {
        $this->forma = $forma;

        return $this;
    }

    public function getRotulo(): string
    {
        return $this->rotulo;
    }

    public function setRotulo(string $rotulo): self
    {
        $this->rotulo = $rotulo;

        return $this;
    }

    public function getValor(): ?int
    {
        return $this->valor;
    }

    public function setValor(?int $valor): self
    {
        $this->valor = $valor;

        return $this;
    }

    public function getJuros(): ?int
    {
        return $this->juros;
    }

    public function setJuros(?int $juros): self
    {
        $this->juros = $juros;

        return $this;
    }

    public function getMulta(): ?int
    {
        return $this->multa;
    }

    public function setMulta(?int $multa): self
    {
        $this->multa = $multa;

        return $this;
    }

    public function getCorrecao(): ?int
    {
        return $this->correcao;
    }

    public function setCorrecao(?int $correcao): self
    {
        $this->correcao = $correcao;

        return $this;
    }

    public function getHonorarios(): ?int
    {
        return $this->honorarios;
    }

    public function setHonorarios(?int $honorarios): self
    {
        $this->honorarios = $honorarios;

        return $this;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function setTotal(?int $total): self
    {
        $this->total = $total;

        return $this;
    }
}
