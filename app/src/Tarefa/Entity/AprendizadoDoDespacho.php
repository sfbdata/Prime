<?php

declare(strict_types=1);

namespace App\Tarefa\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Repository\AprendizadoDoDespachoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um despacho que a pessoa escreveu, guardado para as sugestões do bloco DESPACHO (desenho 04B de 22/10, L145 e
 * L151-156; spec `minhas-metas-esteira-2210.md`, 1.1 item 1, C2 e Fase 4): quem, em qual escritório e etapa, a
 * natureza, o texto e os dias úteis do prazo (0 sem prazo). Fica no servidor, por usuário e escritório.
 *
 * Só o necessário: nada da meta nem da pasta. O texto é o que a pessoa escreveu (as @menções viram "@Nome", sem o id)
 * e fica fora da auditoria (AuditavelCoberturaTest); sai quando a pessoa apaga o que foi aprendido (D4). O
 * usuário apagado leva junto (CASCADE); o escritório purgado, pela `PurgarEscritorioUseCase::ORDEM_DELECAO`.
 */
#[ORM\Entity(repositoryClass: AprendizadoDoDespachoRepository::class)]
#[ORM\Table(name: 'tarefa_aprendizado_despacho')]
#[ORM\Index(name: 'idx_tarefa_aprendizado_despacho_dono', columns: ['tenant_id', 'usuario_id', 'criado_em'])]
class AprendizadoDoDespacho implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(length: 20, enumType: EtapaDaPasta::class)]
        private EtapaDaPasta $etapa,
        #[ORM\Column(length: 20, nullable: true, enumType: NaturezaDoDespacho::class)]
        private ?NaturezaDoDespacho $natureza,
        #[ORM\Column(type: 'text')]
        private string $texto,
        #[ORM\Column(name: 'dias_uteis', type: 'smallint')]
        private int $diasUteis,
        ?\DateTimeImmutable $criadoEm = null,
    ) {
        $this->criadoEm = $criadoEm ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getEtapa(): EtapaDaPasta
    {
        return $this->etapa;
    }

    public function getNatureza(): ?NaturezaDoDespacho
    {
        return $this->natureza;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getDiasUteis(): int
    {
        return $this->diasUteis;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
