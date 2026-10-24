<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Repository\DataNoGoogleRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma data do sistema (feriado, recesso, tribunal, comemorativa, data do escritório ou aniversário da equipe) na
 * agenda do escritório no Google de uma pessoa (decisão do dono, spec agenda-google §9). Só leitura lá. Guarda a chave
 * da data na Agenda, o id do evento lá e o resumo do último envio (`hash`; com o prefixo `x:` quando foi apagado lá
 * ou recusado pelo Google). Apaga junto com a conexão.
 */
#[ORM\Entity(repositoryClass: DataNoGoogleRepository::class)]
#[ORM\Table(name: 'agenda_google_data')]
#[ORM\Index(name: 'idx_data_google_conexao', columns: ['conexao_id'])]
#[ORM\Index(name: 'idx_data_google_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_agenda_google_data', columns: ['conexao_id', 'chave'])]
#[ORM\UniqueConstraint(name: 'uniq_agenda_google_data_google', columns: ['conexao_id', 'google_id'])]
class DataNoGoogle implements TenantAware
{
    public const APAGADO = 'x:';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $hash = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: ConexaoGoogleAgenda::class)]
        #[ORM\JoinColumn(name: 'conexao_id', nullable: false, onDelete: 'CASCADE')]
        private ConexaoGoogleAgenda $conexao,
        #[ORM\Column(length: 255)]
        private string $chave,
        #[ORM\Column(name: 'google_id', length: 1024)]
        private string $googleId,
    ) {
    }

    /** Igual ao Google depois de enviar. */
    public function enviado(string $resumo): void
    {
        $this->hash = $resumo;
    }

    /** Apagado lá (ou recusado pelo Google) nesta versão: não vai de novo até a data mudar aqui. */
    public function apagadoLa(string $resumo): void
    {
        $this->hash = self::APAGADO . $resumo;
    }

    /** Mexido lá: só leitura, vai de novo como está aqui. */
    public function mexidoLa(): void
    {
        $this->hash = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getConexao(): ConexaoGoogleAgenda
    {
        return $this->conexao;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getGoogleId(): string
    {
        return $this->googleId;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }
}
