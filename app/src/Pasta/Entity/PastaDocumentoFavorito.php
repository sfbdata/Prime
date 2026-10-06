<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaDocumentoFavoritoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um arquivo ou uma subpasta que o usuário marcou com a estrela no explorador da aba Documentos
 * (desenho "02 - EXPEDIENTES 1.2.3", DOC-23). Favoritos sobem ao topo do nível aberto — para QUEM
 * marcou, e para mais ninguém.
 *
 * É preferência pessoal, irmã de {@see PastaFavorita}: só existe enquanto a linha existe (desmarcar
 * apaga), não há "favorito = false" gravado, e não é auditada (`AuditavelCoberturaTest`).
 *
 * Anatomia:
 *   - exatamente UM alvo: `documento_id` OU `secao_id` — o CHECK `chk_pasta_documento_favorito_um_alvo`
 *     da migration garante no banco; os construtores nomeados garantem aqui;
 *   - UNIQUE (user_id, documento_id) e UNIQUE (user_id, secao_id): no PostgreSQL NULL nunca colide
 *     num índice único, então os dois índices comuns já valem como "um por usuário × alvo" sem
 *     índice parcial (a linha de seção tem documento_id NULL e não disputa o primeiro);
 *   - índice (tenant_id, user_id): é por ele que o explorador pergunta "quais são os meus";
 *   - `documento_id`/`secao_id` CASCADE: excluir o documento ou a subpasta apaga a estrela;
 *     `user_id` CASCADE pelo mesmo motivo; `tenant_id` sem ação — a purga apaga explicitamente.
 *
 * Os nomes de índice são explícitos no mapeamento para o `doctrine:schema:validate` bater com a
 * migration escrita à mão.
 */
#[ORM\Entity(repositoryClass: PastaDocumentoFavoritoRepository::class)]
#[ORM\Table(name: 'pasta_documento_favorito')]
#[ORM\Index(name: 'idx_pasta_documento_favorito_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\Index(name: 'idx_pasta_documento_favorito_documento', columns: ['documento_id'])]
#[ORM\Index(name: 'idx_pasta_documento_favorito_secao', columns: ['secao_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_documento_favorito_user_documento', columns: ['user_id', 'documento_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_documento_favorito_user_secao', columns: ['user_id', 'secao_id'])]
class PastaDocumentoFavorito implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\ManyToOne(targetEntity: PastaDocumento::class)]
        #[ORM\JoinColumn(name: 'documento_id', nullable: true, onDelete: 'CASCADE')]
        private ?PastaDocumento $documento,
        #[ORM\ManyToOne(targetEntity: PastaSecao::class)]
        #[ORM\JoinColumn(name: 'secao_id', nullable: true, onDelete: 'CASCADE')]
        private ?PastaSecao $secao,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public static function doDocumento(Tenant $tenant, User $usuario, PastaDocumento $documento): self
    {
        return new self($tenant, $usuario, $documento, null);
    }

    public static function daSecao(Tenant $tenant, User $usuario, PastaSecao $secao): self
    {
        return new self($tenant, $usuario, null, $secao);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getDocumento(): ?PastaDocumento
    {
        return $this->documento;
    }

    public function getSecao(): ?PastaSecao
    {
        return $this->secao;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
