<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma pasta compartilhada com uma pessoa (spec acesso-restrito-e-compartilhar §4.4; desenho "03 - EXPEDIENTE",
 * janela "Compartilhar pasta"). O interno (equipe do escritório) nasce aceito e vale na hora: a pasta entra no escopo
 * de quem tem acesso restrito e, para os demais, vale como permissão de ver a pasta. Nunca fura o cadeado.
 *
 * Ativo = `Aceita` e dentro da validade (vencido fica inativo na hora, sem job). Um ativo (pendente ou aceito) por
 * pasta + destino: índice único parcial. Tudo vai para a auditoria (`Auditavel`).
 */
#[ORM\Entity(repositoryClass: PastaCompartilhamentoRepository::class)]
#[ORM\Table(name: 'pasta_compartilhamento')]
#[ORM\Index(name: 'idx_pasta_compartilhamento_destino', columns: ['tenant_id', 'usuario_destino_id'])]
#[ORM\Index(name: 'idx_pasta_compartilhamento_pasta', columns: ['pasta_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_compartilhamento_ativo', columns: ['pasta_id', 'usuario_destino_id'], options: ['where' => "((status)::text = ANY (ARRAY[('pendente'::character varying)::text, ('aceita'::character varying)::text]))"])]
class PastaCompartilhamento implements Auditavel, TenantAware
{
    public const MENSAGEM_MAXIMA = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: EstadoDoCompartilhamento::class)]
    private EstadoDoCompartilhamento $status;

    #[ORM\Column(name: 'aceito_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $aceitoEm = null;

    #[ORM\Column(name: 'encerrado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $encerradoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'encerrado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $encerradoPor = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_origem_id', nullable: false, onDelete: 'CASCADE')]
        private User $origem,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_destino_id', nullable: false, onDelete: 'CASCADE')]
        private User $destino,
        #[ORM\Column(type: 'boolean')]
        private bool $interno,
        #[ORM\Column(name: 'pode_movimentar', type: 'boolean')]
        private bool $podeMovimentar,
        #[ORM\Column(name: 'pode_enviar_documentos', type: 'boolean')]
        private bool $podeEnviarDocumentos,
        #[ORM\Column(name: 'pode_baixar_documentos', type: 'boolean')]
        private bool $podeBaixarDocumentos,
        #[ORM\Column(name: 'valido_ate', type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $validoAte,
        #[ORM\Column(length: self::MENSAGEM_MAXIMA, nullable: true)]
        private ?string $mensagem,
        #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
        $this->status = EstadoDoCompartilhamento::Pendente;
    }

    /** Com a equipe do escritório: sem aceite, vale na hora. */
    public static function interno(
        Pasta $pasta,
        User $origem,
        User $destino,
        PermissoesDoCompartilhamento $permissoes,
        ?\DateTimeImmutable $validoAte,
        ?string $mensagem,
        \DateTimeImmutable $agora,
    ): self {
        $tenant = $pasta->getTenant() ?? throw new \InvalidArgumentException('Pasta sem escritório.');
        if ($origem === $destino) {
            throw new \InvalidArgumentException('Ninguém compartilha a pasta consigo mesmo.');
        }
        $mensagem = $mensagem !== null ? trim($mensagem) : null;
        $compartilhamento = new self(
            $tenant,
            $pasta,
            $origem,
            $destino,
            true,
            $permissoes->movimentar,
            $permissoes->enviarDocumentos,
            $permissoes->baixarDocumentos,
            $validoAte,
            $mensagem === '' || $mensagem === null ? null : mb_substr($mensagem, 0, self::MENSAGEM_MAXIMA),
            $agora,
        );
        $compartilhamento->status   = EstadoDoCompartilhamento::Aceita;
        $compartilhamento->aceitoEm = $agora;

        return $compartilhamento;
    }

    /** Encerrar (quem compartilhou, responsável, primário ou Master) ou deixar de colaborar (o próprio destino). */
    public function encerrar(User $quem, \DateTimeImmutable $agora): void
    {
        if ($this->status === EstadoDoCompartilhamento::Encerrada) {
            return;
        }
        $this->status       = EstadoDoCompartilhamento::Encerrada;
        $this->encerradoEm  = $agora;
        $this->encerradoPor = $quem;
    }

    /** Vale agora: aceito e dentro da validade (o último dia conta). */
    public function estaAtivo(\DateTimeImmutable $hoje): bool
    {
        return $this->status === EstadoDoCompartilhamento::Aceita
            && ($this->validoAte === null || $this->validoAte->format('Y-m-d') >= $hoje->format('Y-m-d'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getOrigem(): User
    {
        return $this->origem;
    }

    public function getDestino(): User
    {
        return $this->destino;
    }

    public function isInterno(): bool
    {
        return $this->interno;
    }

    public function getStatus(): EstadoDoCompartilhamento
    {
        return $this->status;
    }

    public function permissoes(): PermissoesDoCompartilhamento
    {
        return new PermissoesDoCompartilhamento($this->podeMovimentar, $this->podeEnviarDocumentos, $this->podeBaixarDocumentos);
    }

    public function getValidoAte(): ?\DateTimeImmutable
    {
        return $this->validoAte;
    }

    public function getMensagem(): ?string
    {
        return $this->mensagem;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAceitoEm(): ?\DateTimeImmutable
    {
        return $this->aceitoEm;
    }

    public function getEncerradoEm(): ?\DateTimeImmutable
    {
        return $this->encerradoEm;
    }

    public function getEncerradoPor(): ?User
    {
        return $this->encerradoPor;
    }
}
