<?php

namespace App\Processo\Entity;

use App\Entity\Tenant\Tenant;
use App\Processo\Repository\MovimentacaoProcessoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MovimentacaoProcessoRepository::class)]
class MovimentacaoProcesso implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataMovimentacao = null;

    #[ORM\Column(length: 255)]
    private string $descricao = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tipo = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $orgao = null;

    // Código TPU do órgão julgador do movimento (quando o Datajud o traz aninhado no movimento).
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $orgaoCodigo = null;

    // complementosTabelados do Datajud: lista de {codigo, valor, nome, descricao} que detalha o
    // movimento (ex.: "Distribuição" + "sorteio"). O nome padronizado da TPU é curto; o complemento
    // é o que aproxima do texto que aparece no portal do tribunal.
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $complementos = null;

    #[ORM\ManyToOne(targetEntity: Processo::class, inversedBy: 'movimentacoes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Processo $processo = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataMovimentacao(): ?\DateTimeInterface
    {
        return $this->dataMovimentacao;
    }

    public function setDataMovimentacao(?\DateTimeInterface $dataMovimentacao): self
    {
        $this->dataMovimentacao = $dataMovimentacao;
        return $this;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = mb_strtoupper(trim($descricao));
        return $this;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(?string $tipo): self
    {
        $this->tipo = $tipo !== null ? mb_strtoupper(trim($tipo)) : null;
        return $this;
    }

    public function getOrgao(): ?string
    {
        return $this->orgao;
    }

    public function setOrgao(?string $orgao): self
    {
        $this->orgao = $orgao !== null ? mb_strtoupper(trim($orgao)) : null;
        return $this;
    }

    public function getOrgaoCodigo(): ?string
    {
        return $this->orgaoCodigo;
    }

    public function setOrgaoCodigo(?string $orgaoCodigo): self
    {
        $this->orgaoCodigo = $orgaoCodigo !== null ? trim($orgaoCodigo) : null;
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getComplementos(): ?array
    {
        return $this->complementos;
    }

    /**
     * @param array<int, array<string, mixed>>|null $complementos
     */
    public function setComplementos(?array $complementos): self
    {
        $this->complementos = ($complementos === null || $complementos === []) ? null : $complementos;
        return $this;
    }

    // Resumo legível dos complementos para exibição (ex.: "sorteio", "Outros documentos").
    public function getComplementosResumo(): ?string
    {
        if (empty($this->complementos)) {
            return null;
        }

        $nomes = [];
        foreach ($this->complementos as $complemento) {
            if (!is_array($complemento) || !isset($complemento['nome']) || !is_scalar($complemento['nome'])) {
                continue;
            }

            $nome = trim((string) $complemento['nome']);
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }

        return $nomes === [] ? null : implode(', ', $nomes);
    }

    public function getProcesso(): ?Processo
    {
        return $this->processo;
    }

    public function setProcesso(?Processo $processo): self
    {
        $this->processo = $processo;
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
}
