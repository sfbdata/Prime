<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\TemaConfigRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuração dos temas comemorativos (spec docs/specs/temas-comemorativos.md): modo, tema escolhido à mão e
 * temas desligados. Linha ÚNICA (id 1), escrita só pelo Master.
 *
 * GLOBAL, sem tenant, de propósito: é configuração da PLATAFORMA, como o {@see RecursoEstado}. A cor vale para
 * todas as contas ao mesmo tempo; não há dado de escritório nem de cliente aqui, então não entra na purga de
 * escritório nem no TenantFilter. Sem a linha, vale o modo automático com todos os temas ligados.
 * Auditável, como o RecursoEstado: o AuditLogSubscriber grava cada mudança, no escopo de plataforma.
 */
#[ORM\Entity(repositoryClass: TemaConfigRepository::class)]
#[ORM\Table(name: 'tema_config')]
#[ORM\Index(name: 'idx_tema_config_alterado_por', columns: ['alterado_por_id'])]
class TemaConfig implements Auditavel
{
    public const ID_UNICO = 1;

    public const MODO_AUTOMATICO = 'automatico';
    public const MODO_MANUAL = 'manual';
    public const MODO_DESLIGADO = 'desligado';

    public const MODOS = [self::MODO_AUTOMATICO, self::MODO_MANUAL, self::MODO_DESLIGADO];

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    #[ORM\Column(length: 12, options: ['default' => self::MODO_AUTOMATICO])]
    private string $modo = self::MODO_AUTOMATICO;

    /** Id do tema fixado no modo manual; nulo = nenhum escolhido (sem tema). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $manual = null;

    /** @var list<string> ids dos temas desligados no modo automático */
    #[ORM\Column(type: 'json')]
    private array $desligados = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct()
    {
        $this->alteradoEm = new \DateTimeImmutable();
    }

    /** Troca o modo e o tema fixado. O UseCase já validou o modo e o id do tema. */
    public function definirModo(string $modo, ?string $manual, ?User $por, \DateTimeImmutable $em): void
    {
        if (!in_array($modo, self::MODOS, true)) {
            throw new \InvalidArgumentException(sprintf('Modo de tema inválido: %s', $modo));
        }

        $this->modo = $modo;
        $this->manual = $manual;
        $this->registrarAlteracao($por, $em);
    }

    /** Liga ou desliga um tema no modo automático. */
    public function ligarTema(string $id, bool $ligado, ?User $por, \DateTimeImmutable $em): void
    {
        $desligados = array_values(array_filter($this->desligados, static fn (string $d): bool => $d !== $id));
        if (!$ligado) {
            $desligados[] = $id;
        }

        $this->desligados = $desligados;
        $this->registrarAlteracao($por, $em);
    }

    public function estaLigado(string $id): bool
    {
        return !in_array($id, $this->desligados, true);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getModo(): string
    {
        return $this->modo;
    }

    public function getManual(): ?string
    {
        return $this->manual;
    }

    /** @return list<string> */
    public function getDesligados(): array
    {
        return $this->desligados;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    private function registrarAlteracao(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }
}
