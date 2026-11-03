<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Entity;

use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do registro de liberação do disco (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §4,
 * etapa B). **Só mapeamento**, como a {@see ReplicacaoDeArquivo}: existe para o Doctrine conhecer a
 * tabela, e quem lê e grava é o {@see \App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao}, por
 * DBAL.
 *
 * A linha diz que a cópia local de uma chave saiu do disco (etapa C) depois de o conteúdo ter sido
 * provado no R2 (`sha256` e `tamanho_bytes`). Ela fica ABERTA enquanto `restaurado_em` é nulo; o
 * índice único parcial impede duas linhas abertas da mesma chave remota.
 *
 * O tenant fica em `escopo_tenant_id` (nulo = global) e sem FK, como na outbox: o registro sobrevive
 * à purga do escritório.
 */
#[ORM\Entity]
#[ORM\Table(name: 'armazenamento_liberacao')]
#[ORM\Index(name: 'idx_armazenamento_liberacao_lote', columns: ['lote'])]
#[ORM\UniqueConstraint(name: 'uniq_armazenamento_liberacao_aberta', columns: ['chave_remota'], options: ['where' => '(restaurado_em IS NULL)'])]
#[SemIsolamentoDeTenant('Infraestrutura do espelho, lida pelo worker e pela reconciliação sem escritório na sessão, como a outbox')]
class LiberacaoDeArquivo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $lote = '';

    #[ORM\Column(length: 40)]
    private string $categoria = '';

    #[ORM\Column(nullable: true)]
    private ?int $escopoTenantId = null;

    #[ORM\Column(type: 'text')]
    private string $nome = '';

    #[ORM\Column(type: 'text')]
    private string $chaveRemota = '';

    #[ORM\Column(length: 64)]
    private string $sha256 = '';

    #[ORM\Column(type: 'bigint')]
    private int $tamanhoBytes = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $liberadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $restauradoEm = null;

    private function __construct()
    {
        $this->liberadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
