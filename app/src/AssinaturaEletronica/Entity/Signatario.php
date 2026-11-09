<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Entity;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quem assina um documento (contrato da etapa 1, seção 3.2). As colunas da assinatura em si (token, código, rubrica,
 * IP, localização...) já nascem aqui, nulas, para a etapa 2 não precisar de outra migration nesta tabela.
 */
#[ORM\Entity]
#[ORM\Table(name: 'assinatura_eletronica_signatario')]
#[ORM\UniqueConstraint(name: 'uniq_assinatura_eletronica_signatario_token', columns: ['token_hash'])]
#[SemIsolamentoDeTenant('Filho do documento (TenantAware, CASCADE): só é lido pela coleção do documento já filtrado')]
class Signatario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentoParaAssinar::class, inversedBy: 'signatarios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentoParaAssinar $documento;

    #[ORM\Column(type: 'smallint')]
    private int $ordem;

    #[ORM\Column(length: 200)]
    private string $nome;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $cpf;

    #[ORM\Column(length: 120)]
    private string $papel;

    #[ORM\Column(length: 20, enumType: NivelDeAssinatura::class)]
    private NivelDeAssinatura $nivel;

    #[ORM\Column(length: 20, enumType: SituacaoDoSignatario::class)]
    private SituacaoDoSignatario $situacao = SituacaoDoSignatario::Pendente;

    #[ORM\Column(name: 'token_hash', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $tokenHash = null;

    #[ORM\Column(name: 'codigo_hash', length: 255, nullable: true)]
    private ?string $codigoHash = null;

    #[ORM\Column(name: 'codigo_enviado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $codigoEnviadoEm = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $tentativas = 0;

    #[ORM\Column(name: 'bloqueado_ate', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $bloqueadoAte = null;

    #[ORM\Column(name: 'assinado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $assinadoEm = null;

    #[ORM\Column(name: 'nome_digitado', length: 200, nullable: true)]
    private ?string $nomeDigitado = null;

    #[ORM\Column(name: 'cpf_digitado', length: 11, nullable: true)]
    private ?string $cpfDigitado = null;

    #[ORM\Column(name: 'rubrica_chave', length: 255, nullable: true)]
    private ?string $rubricaChave = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $metodo = null;

    #[ORM\Column(name: 'motivo_recusa', type: 'text', nullable: true)]
    private ?string $motivoRecusa = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 8, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 11, scale: 8, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $precisao = null;

    public function __construct(
        DocumentoParaAssinar $documento,
        int $ordem,
        string $nome,
        ?string $email,
        ?string $cpf,
        string $papel,
        NivelDeAssinatura $nivel,
    ) {
        $this->documento = $documento;
        $this->ordem = $ordem;
        $this->nome = $nome;
        $this->email = $email;
        $this->cpf = $cpf;
        $this->papel = $papel;
        $this->nivel = $nivel;
    }

    /** Para o "Duplicar": os mesmos dados, de volta a pendente, sem nada da assinatura anterior. */
    public function copiaPendentePara(DocumentoParaAssinar $copia): self
    {
        return new self($copia, $this->ordem, $this->nome, $this->email, $this->cpf, $this->papel, $this->nivel);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocumento(): DocumentoParaAssinar
    {
        return $this->documento;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function getPapel(): string
    {
        return $this->papel;
    }

    public function getNivel(): NivelDeAssinatura
    {
        return $this->nivel;
    }

    public function getSituacao(): SituacaoDoSignatario
    {
        return $this->situacao;
    }

    public function getAssinadoEm(): ?\DateTimeImmutable
    {
        return $this->assinadoEm;
    }

    public function getMetodo(): ?string
    {
        return $this->metodo;
    }

    public function getTentativas(): int
    {
        return $this->tentativas;
    }
}
