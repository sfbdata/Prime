<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um e-mail pedido ao Carteiro (spec `comercial-e-emails.md` §3.2). Do Master e dos consumidores, cruza escritórios:
 * NÃO é TenantAware; `tenant_id` null = plataforma. Gravado e lido por SQL (reivindicação atômica, vazão por
 * contagem); a entidade existe para o esquema e a purga. O corpo mora em {@see ConteudoDoEnvio}, apagado no estado
 * final.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_envio')]
#[ORM\UniqueConstraint(name: 'uniq_comercial_envio_token', columns: ['token'])]
#[ORM\Index(name: 'idx_comercial_envio_origem', columns: ['origem', 'criado_em'])]
#[ORM\Index(name: 'idx_comercial_envio_situacao', columns: ['situacao', 'enviado_em'])]
#[ORM\Index(name: 'idx_comercial_envio_destinatario', columns: ['destinatario'])]
#[ORM\Index(name: 'idx_comercial_envio_tenant', columns: ['tenant_id'])]
class EnvioDeEmail
{
    public const ENFILEIRADO = 'enfileirado';
    public const ENVIANDO = 'enviando';
    public const ENVIADO = 'enviado';
    public const FALHOU = 'falhou';
    public const BLOQUEADO_DESCADASTRO = 'bloqueado_descadastro';
    public const SIMULADO = 'simulado';
    /** Da campanha encerrada antes de sair (spec §8.1.5): o handler não envia e o conteúdo some. */
    public const CANCELADO = 'cancelado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $token = '';

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 180)]
    private string $destinatario = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $nome = null;

    #[ORM\Column(length: 255)]
    private string $assunto = '';

    #[ORM\Column(length: 20)]
    private string $tipo = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lista = null;

    #[ORM\Column(length: 60)]
    private string $origem = '';

    #[ORM\Column(length: 30)]
    private string $situacao = self::ENFILEIRADO;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $erro = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'tentativa_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $tentativaEm = null;

    /**
     * Quando a mensagem na fila fica disponível: agora, no Carteiro; o horário do DelayStamp, quando a vazão adia. A
     * recuperação da varredura só redespacha o `enfileirado` com esta hora vencida há mais de 30 min (a mensagem se
     * perdeu), nunca o que espera a vazão.
     */
    #[ORM\Column(name: 'proxima_tentativa_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $proximaTentativaEm = null;

    #[ORM\Column(name: 'enviado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enviadoEm = null;

    #[ORM\Column(name: 'primeira_abertura_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $primeiraAberturaEm = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $aberturas = 0;

    #[ORM\Column(name: 'primeiro_clique_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $primeiroCliqueEm = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $cliques = 0;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
