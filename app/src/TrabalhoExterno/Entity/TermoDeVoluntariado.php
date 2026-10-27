<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\TrabalhoExterno\Enum\StatusDoTermo;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O Termo de Adesão ao Serviço Voluntário de um currículo do banco de talentos (spec
 * `docs/specs/trabalho-externo-termo-voluntariado.md`, desenho 06 de 25/10, `termoNovo`/`enviarTermo`).
 *
 * Enviado é IMUTÁVEL: `campos` (o snapshot de tudo que o modal edita, CPF e RG inclusive) só é escrito na
 * criação, e a única mudança depois é o aceite. "Novo termo" cria outro registro. O estado do currículo é o do
 * termo mais recente.
 *
 * Não implementa `TenantAware` de propósito, como o currículo: o voluntário (externo) lê o termo dele por
 * qualquer escritório em que esteja, e o `TenantFilter` o esconderia dele. O isolamento é do acesso por
 * currículo (`LocalizadorDeCurriculo`) e da `PoliticaDoTermo`. A coluna `tenant_id` é o escritório emissor
 * (a sede da Central), para a purga do escritório e o `PurgaCoberturaSchemaTest` a enxergarem.
 *
 * Dado pessoal: o campo `campos` (CPF, RG) fica fora do diff da auditoria (`AuditLogSubscriber`).
 */
#[ORM\Entity(repositoryClass: TermoDeVoluntariadoRepository::class)]
#[ORM\Table(name: 'trabalho_externo_termo_voluntariado')]
#[ORM\Index(name: 'idx_te_termo_tenant_curriculo_enviado', columns: ['tenant_id', 'curriculo_id', 'enviado_em'])]
#[ORM\UniqueConstraint(name: 'uniq_te_termo_tenant_codigo', columns: ['tenant_id', 'codigo'])]
class TermoDeVoluntariado implements Auditavel
{
    /** A versão do modelo de texto (`Component.TERMO_V`, desenho l.943). */
    public const VERSAO_DO_MODELO = '1.0';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** `TV-0001`: sequência legível, dada pelo repositório ({@see TermoDeVoluntariadoRepository::proximoCodigo()}). */
    #[ORM\Column(length: 30)]
    private string $codigo;

    #[ORM\Column(enumType: StatusDoTermo::class, length: 10)]
    private StatusDoTermo $status = StatusDoTermo::Enviado;

    #[ORM\Column(length: 10)]
    private string $versaoModelo = self::VERSAO_DO_MODELO;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $enviadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $aceitoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'aceito_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $aceitoPor = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $aceiteIp = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $aceiteUserAgent = null;

    /** O sha256 do HTML do termo, como renderizado no instante do aceite. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hashAceite = null;

    /**
     * @param array<string, string> $campos o snapshot do que o modal edita (CPF e RG incluídos)
     */
    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Curriculo::class)]
        #[ORM\JoinColumn(name: 'curriculo_id', nullable: false, onDelete: 'CASCADE')]
        private Curriculo $curriculo,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        private array $campos,
        string $codigo,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
        \DateTimeImmutable $agora,
    ) {
        $this->enviadoEm = $agora;
        $this->codigo = $codigo;
    }

    /** "Enviar para aceite" (`enviarTermo`, l.1134): nasce Enviado. @param array<string, string> $campos */
    public static function enviar(Tenant $escritorio, Curriculo $curriculo, array $campos, string $codigo, ?User $criadoPor, \DateTimeImmutable $agora): self
    {
        return new self($escritorio, $curriculo, $campos, $codigo, $criadoPor, $agora);
    }

    /** `TV-0001`: o formato do código que a URL aceita. */
    public static function codigoValido(string $codigo): bool
    {
        return preg_match('/^TV-\d{4,9}$/', $codigo) === 1;
    }

    /** O aceite do voluntário: o servidor grava quando, quem, de onde e o hash. Só uma vez. */
    public function aceitar(User $quem, \DateTimeImmutable $agora, ?string $ip, ?string $userAgent, string $hash): void
    {
        if ($this->status !== StatusDoTermo::Enviado) {
            throw new TransicaoInvalida('Este termo já foi aceito.');
        }
        $this->status = StatusDoTermo::Aceito;
        $this->aceitoEm = $agora;
        $this->aceitoPor = $quem;
        $this->aceiteIp = $ip !== null ? mb_substr($ip, 0, 45) : null;
        $this->aceiteUserAgent = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
        $this->hashAceite = $hash;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getCurriculo(): Curriculo
    {
        return $this->curriculo;
    }

    public function getStatus(): StatusDoTermo
    {
        return $this->status;
    }

    public function foiAceito(): bool
    {
        return $this->status === StatusDoTermo::Aceito;
    }

    public function getVersaoModelo(): string
    {
        return $this->versaoModelo;
    }

    /** @return array<string, string> */
    public function getCampos(): array
    {
        return $this->campos;
    }

    public function getEnviadoEm(): \DateTimeImmutable
    {
        return $this->enviadoEm;
    }

    public function getAceitoEm(): ?\DateTimeImmutable
    {
        return $this->aceitoEm;
    }

    public function getAceitoPor(): ?User
    {
        return $this->aceitoPor;
    }

    public function getAceiteIp(): ?string
    {
        return $this->aceiteIp;
    }

    public function getHashAceite(): ?string
    {
        return $this->hashAceite;
    }
}
