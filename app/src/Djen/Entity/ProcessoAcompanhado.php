<?php

declare(strict_types=1);

namespace App\Djen\Entity;

use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Anotações do Push Processual sobre um número de processo (aba "Processos da OAB", desenho 11):
 * e-mail alternativo dos avisos, observação, área e fase.
 *
 * A chave é o NÚMERO (20 dígitos, sem máscara) dentro do escritório, não o `Processo`: a pessoa pode
 * incluir um número para acompanhar sem cadastrar o processo inteiro, e o processo cadastrado ganha as
 * mesmas anotações pela linha do número. Uma linha por (escritório, número).
 *
 * `acompanhando` liga o número ao Push mesmo sem `Processo` cadastrado (a sincronização o consulta como
 * os processos sem baixa). Parar de acompanhar um número sem processo apaga a linha; o processo
 * cadastrado é pausado pelo monitoramento dele, e a linha só guarda as anotações.
 */
#[ORM\Entity(repositoryClass: ProcessoAcompanhadoRepository::class)]
#[ORM\Table(name: 'djen_processo_acompanhado')]
#[ORM\Index(name: 'idx_djen_proc_acomp_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_djen_proc_acomp_criador', columns: ['criado_por_id'])]
#[ORM\UniqueConstraint(name: 'uniq_djen_proc_acomp_tenant_numero', columns: ['tenant_id', 'numero_processo'])]
class ProcessoAcompanhado implements TenantAware
{
    public const LIMITE_OBSERVACAO = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'email_alternativo', length: 255, nullable: true)]
    private ?string $emailAlternativo = null;

    #[ORM\Column(length: self::LIMITE_OBSERVACAO, nullable: true)]
    private ?string $observacao = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $area = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $fase = null;

    #[ORM\Column(name: 'excluido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidoEm = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(name: 'numero_processo', length: 20)]
        private string $numeroProcesso,
        #[ORM\Column(options: ['default' => true])]
        private bool $acompanhando = true,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor = null,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getNumeroProcesso(): string
    {
        return $this->numeroProcesso;
    }

    public function isAcompanhando(): bool
    {
        return $this->acompanhando;
    }

    public function getEmailAlternativo(): ?string
    {
        return $this->emailAlternativo;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function getArea(): ?string
    {
        return $this->area;
    }

    public function getFase(): ?string
    {
        return $this->fase;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function acompanhar(): void
    {
        $this->acompanhando = true;
    }

    /** Incluir de novo (campo INCLUIR ou Relatório) desfaz o "Excluir processo". */
    public function limparExclusao(): void
    {
        $this->excluidoEm = null;
    }

    public function getExcluidoEm(): ?\DateTimeImmutable
    {
        return $this->excluidoEm;
    }

    /** "Excluir processo" (desenho 11): para de acompanhar e não volta ao Relatório até ser incluído de novo. */
    public function excluir(\DateTimeImmutable $quando): void
    {
        $this->acompanhando = false;
        $this->excluidoEm = $quando;
    }

    public function pararDeAcompanhar(): void
    {
        $this->acompanhando = false;
    }

    public function anotar(?string $emailAlternativo, ?string $observacao, ?string $area, ?string $fase): void
    {
        $this->emailAlternativo = self::vazioViraNulo($emailAlternativo);
        $this->observacao = self::vazioViraNulo($observacao);
        $this->area = self::vazioViraNulo($area);
        $this->fase = self::vazioViraNulo($fase);
    }

    private static function vazioViraNulo(?string $valor): ?string
    {
        $valor = $valor === null ? '' : trim($valor);

        return $valor === '' ? null : $valor;
    }
}
