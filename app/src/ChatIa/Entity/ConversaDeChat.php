<?php

declare(strict_types=1);

namespace App\ChatIa\Entity;

use App\ChatIa\Repository\ConversaDeChatRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma conversa do Chat I.A em página inteira (`docs/specs/chat-pagina-inteira.md`). É PARTICULAR de quem a criou:
 * conversa alheia é 404, nem o administrador do escritório a lê. Fora da auditoria (`Auditavel`) pelo mesmo motivo da
 * `central_conversa`: o `audit_log` é lido por quem administra o escritório.
 *
 * O título nasce vazio. A primeira resposta gera um automático; renomear à mão (`renomear`) marca `tituloManual` e o
 * automático nunca mais sobrescreve.
 */
#[ORM\Entity(repositoryClass: ConversaDeChatRepository::class)]
#[ORM\Table(name: 'chat_ia_conversa')]
#[ORM\Index(name: 'idx_chat_ia_conversa_dono', columns: ['tenant_id', 'usuario_id', 'atualizada_em'])]
class ConversaDeChat implements TenantAware
{
    public const TAMANHO_MAXIMO_DO_TITULO = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $titulo = null;

    #[ORM\Column(name: 'titulo_manual', type: 'boolean', options: ['default' => false])]
    private bool $tituloManual = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $fixada = false;

    #[ORM\Column(name: 'atualizada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false)]
        private User $usuario,
        #[ORM\Column(length: 40, enumType: ModeloDeIa::class)]
        private ModeloDeIa $modelo,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
    ) {
        $this->atualizadaEm = $criadaEm;
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

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function temTituloManual(): bool
    {
        return $this->tituloManual;
    }

    public function estaFixada(): bool
    {
        return $this->fixada;
    }

    public function getModelo(): ModeloDeIa
    {
        return $this->modelo;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getAtualizadaEm(): \DateTimeImmutable
    {
        return $this->atualizadaEm;
    }

    /** Renomear à mão vale sobre o título automático; título em branco volta ao automático. */
    public function renomear(string $titulo): void
    {
        $titulo = trim($titulo);
        $this->titulo = $titulo === '' ? null : mb_substr($titulo, 0, self::TAMANHO_MAXIMO_DO_TITULO);
        $this->tituloManual = $titulo !== '';
    }

    /** O título gerado depois da primeira resposta. Não faz nada se a pessoa já renomeou. */
    public function definirTituloAutomatico(string $titulo): void
    {
        $titulo = trim($titulo);
        if ($this->tituloManual || $titulo === '') {
            return;
        }
        $this->titulo = mb_substr($titulo, 0, self::TAMANHO_MAXIMO_DO_TITULO);
    }

    public function fixar(bool $fixada): void
    {
        $this->fixada = $fixada;
    }

    public function trocarModelo(ModeloDeIa $modelo): void
    {
        $this->modelo = $modelo;
    }

    public function registrarAtividade(\DateTimeImmutable $em): void
    {
        $this->atualizadaEm = $em;
    }
}
