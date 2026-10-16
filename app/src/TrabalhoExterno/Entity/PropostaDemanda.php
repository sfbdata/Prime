<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoProposta;
use Doctrine\ORM\Mapping as ORM;

/** Uma versão da proposta comercial (v1, v2…). Só a mais recente pode ser aceita. */
#[ORM\Entity]
#[ORM\Table(name: 'trabalho_externo_proposta')]
#[ORM\UniqueConstraint(name: 'uniq_te_proposta_versao', columns: ['demanda_id', 'versao'])]
class PropostaDemanda
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: SituacaoProposta::class, length: 20)]
    private SituacaoProposta $situacao = SituacaoProposta::Enviada;

    #[ORM\Column(length: 255)]
    private string $nomeAutor;

    #[ORM\Column(enumType: Perfil::class, length: 20)]
    private Perfil $perfilAutor;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $enviadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $aceitaEm = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeAceitaPor = null;

    #[ORM\Column(enumType: Perfil::class, length: 20, nullable: true)]
    private ?Perfil $perfilAceitaPor = null;

    /**
     * @param string $valor    decimal com ponto ("1500.00"), já normalizado
     * @param ?string $despesas idem
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'propostas')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        #[ORM\Column]
        private int $versao,
        #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
        private string $valor,
        #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
        private ?string $despesas,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $prazoEstimado,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $validade,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $pagamento,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $escopo,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $naoInclui,
        Autor $autor,
    ) {
        $this->nomeAutor = $autor->nome;
        $this->perfilAutor = $autor->perfil;
        $this->enviadaEm = new \DateTimeImmutable();
    }

    public function aceitar(Autor $autor): void
    {
        $this->situacao = SituacaoProposta::Aceita;
        $this->aceitaEm = new \DateTimeImmutable();
        $this->nomeAceitaPor = $autor->nome;
        $this->perfilAceitaPor = $autor->perfil;
    }

    public function pedirAjustes(): void
    {
        $this->situacao = SituacaoProposta::Ajustes;
    }

    public function recusar(): void
    {
        $this->situacao = SituacaoProposta::Recusada;
    }

    public function substituirSeEmAberto(): void
    {
        if ($this->situacao === SituacaoProposta::Enviada || $this->situacao === SituacaoProposta::Ajustes) {
            $this->situacao = SituacaoProposta::Substituida;
        }
    }

    public function valorFormatado(): string
    {
        return self::brl($this->valor);
    }

    public static function brl(?string $decimal): string
    {
        if ($decimal === null || $decimal === '') {
            return 'Não informado';
        }

        return 'R$ ' . number_format((float) $decimal, 2, ',', '.');
    }

    public function getVersao(): int
    {
        return $this->versao;
    }

    public function getValor(): string
    {
        return $this->valor;
    }

    public function getDespesas(): ?string
    {
        return $this->despesas;
    }

    public function getPrazoEstimado(): ?string
    {
        return $this->prazoEstimado;
    }

    public function getValidade(): \DateTimeImmutable
    {
        return $this->validade;
    }

    public function getPagamento(): ?string
    {
        return $this->pagamento;
    }

    public function getEscopo(): ?string
    {
        return $this->escopo;
    }

    public function getNaoInclui(): ?string
    {
        return $this->naoInclui;
    }

    public function getSituacao(): SituacaoProposta
    {
        return $this->situacao;
    }

    public function getEnviadaEm(): \DateTimeImmutable
    {
        return $this->enviadaEm;
    }

    public function getAceitaEm(): ?\DateTimeImmutable
    {
        return $this->aceitaEm;
    }

    public function getNomeAceitaPor(): ?string
    {
        return $this->nomeAceitaPor;
    }

    public function getPerfilAceitaPor(): ?Perfil
    {
        return $this->perfilAceitaPor;
    }

    public function getNomeAutor(): string
    {
        return $this->nomeAutor;
    }

    public function getPerfilAutor(): Perfil
    {
        return $this->perfilAutor;
    }
}
