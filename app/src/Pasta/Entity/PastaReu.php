<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaReuRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réu (parte adversa) cadastrado na pasta, pela aba Réu do Cadastro (desenho 1.2.3, `reuVals`; E12h). O primeiro da
 * ordem é o "Principal" e aparece na coluna Réu da Lista do Expediente ("e outros" quando há mais de um); sem réu
 * cadastrado, a Lista continua lendo as partes do Datajud.
 *
 * Nome em maiúsculas com espaços normalizados (como o desenho); documento só com dígitos (11 = CPF, 14 = CNPJ) ou
 * nulo.
 */
#[ORM\Entity(repositoryClass: PastaReuRepository::class)]
#[ORM\Table(name: 'pasta_reu')]
#[ORM\Index(name: 'idx_pasta_reu_pasta', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_reu_tenant', columns: ['tenant_id'])]
class PastaReu implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nome;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $documento = null;

    /** Cadastro do réu na Controladoria (fatia B): todos opcionais; salvar nunca exige e-mail nem endereço. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $qualificacao = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $endereco = null;

    #[ORM\Column(type: 'integer')]
    private int $ordem = 0;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        string $nome,
        ?string $documento,
        int $ordem,
    ) {
        $this->nome = self::normalizarNome($nome);
        $this->documento = $documento !== null && $documento !== '' ? $documento : null;
        $this->ordem = $ordem;
        $this->criadoEm = new \DateTimeImmutable();
    }

    public static function normalizarNome(string $nome): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $nome)));
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getPasta(): Pasta { return $this->pasta; }
    public function getNome(): string { return $this->nome; }
    public function getDocumento(): ?string { return $this->documento; }
    public function setNome(string $nome): void { $this->nome = self::normalizarNome($nome); }
    public function setDocumento(?string $documento): void { $this->documento = $documento !== null && $documento !== '' ? $documento : null; }
    public function getQualificacao(): ?string { return $this->qualificacao; }
    public function getEmail(): ?string { return $this->email; }
    public function getEndereco(): ?string { return $this->endereco; }

    public function completar(?string $qualificacao, ?string $email, ?string $endereco): void
    {
        $this->qualificacao = $qualificacao;
        $this->email = $email;
        $this->endereco = $endereco;
    }

    /** Cadastro completo = CPF (ou CNPJ) e qualificação informados; e-mail e endereço são opcionais. */
    public function estaCompleto(): bool
    {
        return $this->documento !== null && $this->qualificacao !== null;
    }

    public function getOrdem(): int { return $this->ordem; }
    public function setOrdem(int $ordem): void { $this->ordem = $ordem; }
    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }
}
