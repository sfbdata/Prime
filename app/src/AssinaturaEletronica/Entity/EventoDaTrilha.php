<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Entity;

use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\Entity\Auth\User;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um evento da trilha de auditoria do documento. Só se cria (sem setter, entidade somente leitura para o Doctrine):
 * cada evento guarda o hash do anterior, e o próprio hash cobre os campos abaixo, então mexer numa linha antiga quebra a
 * corrente dali para a frente (contrato da etapa 1, seção 3.2).
 *
 * `hash = sha256(hash_anterior | documento_id | tipo | dados (JSON, chaves ordenadas) | criado_em (ISO 8601, UTC) |
 * signatario_ref | usuario_ref | ip | user_agent)`, com nulo como texto vazio. A hora é a do servidor, em segundos
 * (é o que a coluna guarda), para o hash recalculado depois de reler bater com o gravado.
 *
 * `signatario_ref` e `usuario_ref` guardam o id de quem agiu no momento do evento e nunca mudam. As relações
 * `signatario` e `usuario` (que dão o nome na tela) têm FK `SET NULL`: apagar o usuário ou o signatário anula a FK, e
 * por isso o hash usa as colunas imutáveis, senão a corrente antiga deixaria de conferir.
 */
#[ORM\Entity(repositoryClass: EventoDaTrilhaRepository::class, readOnly: true)]
#[ORM\Table(name: 'assinatura_eletronica_evento')]
#[SemIsolamentoDeTenant('Filho do documento (TenantAware, CASCADE): só é lido a partir do documento já filtrado')]
class EventoDaTrilha
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentoParaAssinar::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentoParaAssinar $documento;

    #[ORM\ManyToOne(targetEntity: Signatario::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Signatario $signatario;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $usuario;

    /** O id do signatário no momento do evento, sem FK: entra no hash e sobrevive à exclusão do signatário. */
    #[ORM\Column(name: 'signatario_ref', type: 'integer', nullable: true)]
    private ?int $signatarioRef;

    /** O id do usuário no momento do evento, sem FK: entra no hash e sobrevive à exclusão do usuário. */
    #[ORM\Column(name: 'usuario_ref', type: 'integer', nullable: true)]
    private ?int $usuarioRef;

    #[ORM\Column(length: 60, enumType: TipoDeEvento::class)]
    private TipoDeEvento $tipo;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $dados;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'hash_anterior', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $hashAnterior;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $hash;

    /**
     * @param array<string, mixed> $dados os parâmetros do texto do evento
     */
    public function __construct(
        DocumentoParaAssinar $documento,
        TipoDeEvento $tipo,
        array $dados,
        \DateTimeImmutable $agora,
        ?string $hashAnterior,
        ?User $usuario = null,
        ?Signatario $signatario = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ) {
        if ($documento->getId() === null) {
            throw new \LogicException('O evento da trilha precisa do documento já gravado.');
        }

        $this->documento = $documento;
        $this->tipo = $tipo;
        $this->dados = $dados;
        $this->criadoEm = self::emSegundos($agora);
        $this->hashAnterior = $hashAnterior;
        $this->usuario = $usuario;
        $this->signatario = $signatario;
        $this->usuarioRef = $usuario?->getId();
        $this->signatarioRef = $signatario?->getId();
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->hash = $this->calcularHash();
    }

    /** Recalcula o hash a partir dos campos gravados: falso = a linha foi alterada depois de criada. */
    public function hashConfere(): bool
    {
        return hash_equals($this->hash, $this->calcularHash());
    }

    public function calcularHash(): string
    {
        $partes = [
            $this->hashAnterior ?? '',
            (string) $this->documento->getId(),
            $this->tipo->value,
            json_encode(self::ordenar($this->dados), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            $this->criadoEm->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            (string) $this->signatarioRef,
            (string) $this->usuarioRef,
            $this->ip ?? '',
            $this->userAgent ?? '',
        ];

        return hash('sha256', implode('|', $partes));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocumento(): DocumentoParaAssinar
    {
        return $this->documento;
    }

    public function getSignatario(): ?Signatario
    {
        return $this->signatario;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function getSignatarioRef(): ?int
    {
        return $this->signatarioRef;
    }

    public function getUsuarioRef(): ?int
    {
        return $this->usuarioRef;
    }

    public function getTipo(): TipoDeEvento
    {
        return $this->tipo;
    }

    /** @return array<string, mixed> */
    public function getDados(): array
    {
        return $this->dados;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getHashAnterior(): ?string
    {
        return $this->hashAnterior;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    /** A coluna guarda segundos, no fuso do PHP: o objeto em memória fica igual ao que volta do banco. */
    private static function emSegundos(\DateTimeImmutable $agora): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $agora->getTimestamp()))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @param array<mixed> $valor
     *
     * @return array<mixed>
     */
    private static function ordenar(array $valor): array
    {
        if (!array_is_list($valor)) {
            ksort($valor, \SORT_STRING);
        }

        foreach ($valor as $chave => $item) {
            if (\is_array($item)) {
                $valor[$chave] = self::ordenar($item);
            }
        }

        return $valor;
    }
}
