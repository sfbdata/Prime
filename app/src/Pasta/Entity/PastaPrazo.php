<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Prazo ou audiência registrado à mão na pasta: o "+ Adicionar prazo ou audiência" do trilho da aba Dados
 * (desenho 1.2.3, `bj-pz-manual-v1`). Aparece em "Próximos prazos" junto das metas com prazo e alimenta os
 * filtros EXPEDIENTES da Lista (Audiência, Com prazo, Prazo findou).
 *
 * Cumprido não sai do banco: some da lista e fica a data e quem cumpriu. Excluir apaga de verdade, como o
 * desenho; o cartão de sistema no Registro e o audit_log guardam o que foi.
 * Responsável é texto livre, como o desenho (não é vínculo com colaborador).
 */
#[ORM\Entity(repositoryClass: PastaPrazoRepository::class)]
#[ORM\Table(name: 'pasta_prazo')]
#[ORM\Index(name: 'idx_pasta_prazo_tenant_data', columns: ['tenant_id', 'data'])]
#[ORM\Index(name: 'idx_pasta_prazo_pasta', columns: ['pasta_id'])]
class PastaPrazo implements TenantAware, Auditavel
{
    public const TIPO_PRAZO     = 'prazo';
    public const TIPO_AUDIENCIA = 'audiencia';
    public const TIPOS = [self::TIPO_PRAZO, self::TIPO_AUDIENCIA];

    public const ORIGEM_MANUAL   = 'manual';
    public const ORIGEM_REGISTRO = 'registro';
    /** Calculado da publicação do Push (docs/specs/prazo-do-push.md): `registroRef` é "push:<id da publicação>". */
    public const ORIGEM_PUSH     = 'push';
    public const REF_PUSH        = 'push:';

    /** Onde o prazo lido foi escrito (prefixo de `registroRef`) e o rótulo que a tela mostra, como o desenho. */
    public const ROTULOS_REGISTRO = ['expedientes' => 'Registro dos expedientes', 'detalhes' => 'Detalhes', 'financeiro' => 'Financeiro'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $tipo = self::TIPO_PRAZO;

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $data;

    /** "HH:MM" ou null (a hora é opcional, também na audiência, como o desenho). */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $hora = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $responsavel = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'cumprido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cumpridoEm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'cumprido_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $cumpridoPor = null;

    /**
     * `manual` (o "+" do trilho), `registro` (lido de uma data escrita num registro da pasta pelo LeitorDeDatas,
     * E12c) ou `push` (calculado da publicação do Push, que se cumpre). O lido e o calculado não se editam: mudam
     * quando a origem muda.
     */
    #[ORM\Column(length: 20, options: ['default' => self::ORIGEM_MANUAL])]
    private string $origem = self::ORIGEM_MANUAL;

    /** "expedientes:123", "detalhes:45", "financeiro:6": o registro de onde a data foi lida; "push:7", a publicação. */
    #[ORM\Column(name: 'registro_ref', length: 40, nullable: true)]
    private ?string $registroRef = null;

    /** Data contada pelo leitor ("prazo de 15 dias úteis"), a conferir. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $estimado = false;

    /**
     * Do Push: a parte parece Fazenda Pública, MP ou Defensoria ({@see \App\Pasta\Service\SinalDePrazoEmDobro}). O
     * prazo NÃO é dobrado; a tela só avisa que pode ser prazo em dobro.
     */
    #[ORM\Column(name: 'pode_ser_em_dobro', type: 'boolean', options: ['default' => false])]
    private bool $podeSerEmDobro = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
        $this->data = new \DateTimeImmutable('today');
    }

    public function definir(string $tipo, string $titulo, \DateTimeImmutable $data, ?string $hora, ?string $responsavel): void
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \DomainException('Tipo de prazo inválido.');
        }
        $titulo = trim($titulo);
        if ($titulo === '') {
            throw new \DomainException('Informe a descrição e a data');
        }
        if ($hora !== null && $hora !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            throw new \DomainException('Hora inválida.');
        }
        $this->tipo = $tipo;
        $this->titulo = mb_substr($titulo, 0, 255);
        $this->data = $data;
        $this->hora = ($hora === null || $hora === '') ? null : $hora;
        $responsavel = $responsavel === null ? '' : trim($responsavel);
        $this->responsavel = $responsavel === '' ? null : mb_substr($responsavel, 0, 120);
    }

    /**
     * Prazo lido de um registro: a data de criação é a do registro (é ela que conta como atividade da pasta), e o
     * responsável é quem escreveu.
     */
    public static function doRegistro(Tenant $tenant, Pasta $pasta, string $ref, \DateTimeImmutable $escritoEm, ?User $autor, string $tipo, string $titulo, \DateTimeImmutable $data, ?string $hora, bool $estimado): self
    {
        $prazo = new self($tenant, $pasta, $autor);
        $prazo->definir($tipo, $titulo, $data, $hora, $autor?->getFullName());
        $prazo->origem = self::ORIGEM_REGISTRO;
        $prazo->registroRef = $ref;
        $prazo->estimado = $estimado;
        $prazo->criadoEm = $escritoEm;

        return $prazo;
    }

    /**
     * Prazo calculado da publicação do Push: sem autor nem responsável, e a data de criação é a da disponibilização
     * no DJEN (a publicação não é atividade nova da pasta só porque a sincronização rodou agora).
     */
    public static function doPush(Tenant $tenant, Pasta $pasta, int $publicacaoId, \DateTimeImmutable $disponibilizadaEm, string $tipo, string $titulo, \DateTimeImmutable $data, ?string $hora, bool $estimado, bool $podeSerEmDobro = false): self
    {
        $prazo = new self($tenant, $pasta, null);
        $prazo->definir($tipo, $titulo, $data, $hora, null);
        $prazo->origem = self::ORIGEM_PUSH;
        $prazo->registroRef = self::REF_PUSH . $publicacaoId;
        $prazo->estimado = $estimado;
        $prazo->podeSerEmDobro = $podeSerEmDobro;
        $prazo->criadoEm = $disponibilizadaEm;

        return $prazo;
    }

    public function isManual(): bool { return $this->origem === self::ORIGEM_MANUAL; }
    public function isPush(): bool { return $this->origem === self::ORIGEM_PUSH; }
    public function getOrigem(): string { return $this->origem; }
    public function getRegistroRef(): ?string { return $this->registroRef; }
    public function isEstimado(): bool { return $this->estimado; }
    public function podeSerEmDobro(): bool { return $this->podeSerEmDobro; }

    /**
     * "Manual", "Push processual", ou de onde foi lido, com " (estimado: conferir)" quando a data foi contada (desenho
     * `pzLista`). Do Push, o selo "prazo calculado, confira" fica com a tela (catálogo de tradução).
     */
    public function getOrigemRotulo(): string
    {
        if ($this->isManual()) {
            return 'Manual';
        }
        if ($this->isPush()) {
            return 'Push processual';
        }
        $onde = self::ROTULOS_REGISTRO[explode(':', (string) $this->registroRef)[0]] ?? 'Registro';

        return $onde . ($this->estimado ? ' (estimado: conferir)' : '');
    }

    public function cumprir(User $por): void
    {
        if ($this->cumpridoEm !== null) {
            throw new \DomainException('Este prazo já foi cumprido.');
        }
        $this->cumpridoEm = new \DateTimeImmutable();
        $this->cumpridoPor = $por;
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getPasta(): Pasta { return $this->pasta; }
    public function getTipo(): string { return $this->tipo; }
    public function isAudiencia(): bool { return $this->tipo === self::TIPO_AUDIENCIA; }
    public function getTitulo(): string { return $this->titulo; }
    public function getData(): \DateTimeImmutable { return $this->data; }
    public function getHora(): ?string { return $this->hora; }
    public function getResponsavel(): ?string { return $this->responsavel; }
    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }
    public function getCriadoPor(): ?User { return $this->criadoPor; }
    public function getCumpridoEm(): ?\DateTimeImmutable { return $this->cumpridoEm; }
    public function getCumpridoPor(): ?User { return $this->cumpridoPor; }
    public function estaCumprido(): bool { return $this->cumpridoEm !== null; }
}
