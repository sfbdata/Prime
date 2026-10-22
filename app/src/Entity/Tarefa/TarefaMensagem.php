<?php

namespace App\Entity\Tarefa;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Tarefa\Enum\NaturezaDoDespacho;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mensagem da meta, que a tela chama de despacho (desenho 04B de 22/10; spec `minhas-metas-esteira-2210.md`, Fase 2).
 * Desde a Fase 2a o despacho guarda natureza, prazo de cumprimento, ciência, certidão e o código do registro (D1,
 * C13). As mensagens antigas ficam sem código: a tela não mostra a linha de registro nelas.
 */
#[ORM\Entity(repositoryClass: TarefaMensagemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tarefa_mensagem_tenant_codigo', columns: ['tenant_id', 'codigo'])]
class TarefaMensagem implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tarefa::class, inversedBy: 'mensagens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tarefa $tarefa = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $usuario = null;

    #[ORM\Column(type: 'text')]
    private string $mensagem;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $arquivoAnexo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'editado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editadoEm = null;

    #[ORM\Column(length: 20, nullable: true, enumType: NaturezaDoDespacho::class)]
    private ?NaturezaDoDespacho $natureza = null;

    #[ORM\Column(name: 'prazo_cumprimento', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazoCumprimento = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'ciente_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $cientePor = null;

    #[ORM\Column(name: 'ciente_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cienteEm = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $certidao = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'certificado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $certificadoPor = null;

    #[ORM\Column(name: 'certificado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $certificadoEm = null;

    /** Código do registro (D1): `XXXX.XXXX`, sorteado e único no escritório. Nulo nas mensagens antigas. */
    #[ORM\Column(length: 9, nullable: true)]
    private ?string $codigo = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarefa(): ?Tarefa
    {
        return $this->tarefa;
    }

    public function setTarefa(?Tarefa $tarefa): self
    {
        $this->tarefa = $tarefa;
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

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
    }

    public function getMensagem(): string
    {
        return $this->mensagem;
    }

    public function setMensagem(string $mensagem): self
    {
        $this->mensagem = $mensagem;
        return $this;
    }

    public function getArquivoAnexo(): ?string
    {
        return $this->arquivoAnexo;
    }

    public function setArquivoAnexo(?string $arquivoAnexo): self
    {
        $this->arquivoAnexo = $arquivoAnexo;
        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function setCriadoEm(\DateTimeImmutable $criadoEm): self
    {
        $this->criadoEm = $criadoEm;
        return $this;
    }

    public function getEditadoEm(): ?\DateTimeImmutable
    {
        return $this->editadoEm;
    }

    public function setEditadoEm(?\DateTimeImmutable $editadoEm): self
    {
        $this->editadoEm = $editadoEm;
        return $this;
    }

    public function getNatureza(): ?NaturezaDoDespacho
    {
        return $this->natureza;
    }

    public function setNatureza(?NaturezaDoDespacho $natureza): self
    {
        $this->natureza = $natureza;

        return $this;
    }

    public function getPrazoCumprimento(): ?\DateTimeImmutable
    {
        return $this->prazoCumprimento;
    }

    public function setPrazoCumprimento(?\DateTimeImmutable $prazoCumprimento): self
    {
        $this->prazoCumprimento = $prazoCumprimento?->setTime(0, 0);

        return $this;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    /** O código é dado uma vez só: trocar o de um despacho já registrado invalidaria o que foi conferido. */
    public function registrarCodigo(string $codigo): self
    {
        if ($this->codigo !== null) {
            throw new \LogicException('Este despacho já tem código de registro.');
        }
        if (preg_match('/^\d{4}\.\d{4}$/', $codigo) !== 1) {
            throw new \InvalidArgumentException('Código de registro fora do formato XXXX.XXXX.');
        }

        $this->codigo = $codigo;

        return $this;
    }

    public function getCientePor(): ?User
    {
        return $this->cientePor;
    }

    public function getCienteEm(): ?\DateTimeImmutable
    {
        return $this->cienteEm;
    }

    public function temCiencia(): bool
    {
        return $this->cienteEm !== null;
    }

    /** Ciência automática (C14): a primeira vale; as seguintes não mudam nada. */
    public function darCiencia(User $quem, \DateTimeImmutable $quando): void
    {
        if ($this->cienteEm !== null) {
            return;
        }

        $this->cientePor = $quem;
        $this->cienteEm  = $quando;
    }

    public function getCertidao(): ?string
    {
        return $this->certidao;
    }

    public function getCertificadoPor(): ?User
    {
        return $this->certificadoPor;
    }

    public function getCertificadoEm(): ?\DateTimeImmutable
    {
        return $this->certificadoEm;
    }

    public function temCertidao(): bool
    {
        return $this->certidao !== null;
    }

    /** Certidão de cumprimento (C15): uma por despacho. */
    public function certificar(User $quem, string $texto, \DateTimeImmutable $quando): void
    {
        if ($this->certidao !== null) {
            throw new \LogicException('Este despacho já tem certidão.');
        }

        $this->certidao       = $texto;
        $this->certificadoPor = $quem;
        $this->certificadoEm  = $quando;
    }
}
