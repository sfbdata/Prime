<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A linha da outbox da réplica (spec P2 §3.2, §14). **Só mapeamento**: existe para o Doctrine
 * conhecer a tabela — sem ele, o próximo `make:migration` proporia apagá-la. Quem lê e grava é a
 * {@see \App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao}, por DBAL, porque a reivindicação precisa
 * de `FOR UPDATE SKIP LOCKED` e de trava consultiva, que o ORM não expressa.
 *
 * Não é `TenantAware`: é infraestrutura, lida pelo worker sem escritório na sessão. O tenant fica em
 * `escopo_tenant_id` (nulo = global) e sem FK — o histórico sobrevive à purga do escritório.
 */
#[ORM\Entity]
#[ORM\Table(name: 'armazenamento_replicacao')]
#[ORM\Index(name: 'idx_armazenamento_replicacao_fila', columns: ['estado', 'proxima_tentativa_em', 'id'])]
#[ORM\Index(name: 'idx_armazenamento_replicacao_chave', columns: ['chave_remota', 'id'])]
class ReplicacaoDeArquivo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $operacao = '';

    #[ORM\Column(nullable: true)]
    private ?int $escopoTenantId = null;

    #[ORM\Column(length: 40)]
    private string $categoria = '';

    #[ORM\Column(type: 'text')]
    private string $nome = '';

    #[ORM\Column(type: 'text')]
    private string $chaveRemota = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $tamanhoBytes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(length: 20)]
    private string $estado = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $tentativas = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ultimoErro = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $proximaTentativaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processandoAte = null;

    /** Token de quem detém a posse agora; só ele conclui, reagenda ou renova (revisão da P2.3, I1). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $posse = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    private function __construct()
    {
        $this->proximaTentativaEm = $this->criadoEm = $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
