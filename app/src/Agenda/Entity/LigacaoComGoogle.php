<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Repository\LigacaoComGoogleRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um compromisso na agenda do escritório no Google de uma pessoa (modelo do dono de 20/10/2026): o mesmo
 * compromisso tem uma ligação para o criador e uma para cada participante conectado. Guarda o id do evento lá e
 * o resumo do último envio (`hash`; com o prefixo `x:` quando o Google recusou aquela versão). Apaga junto com
 * a conexão ou com o compromisso.
 */
#[ORM\Entity(repositoryClass: LigacaoComGoogleRepository::class)]
#[ORM\Table(name: 'agenda_google_ligacao')]
#[ORM\Index(name: 'idx_ligacao_conexao', columns: ['conexao_id'])]
#[ORM\Index(name: 'idx_ligacao_evento', columns: ['evento_id'])]
#[ORM\Index(name: 'idx_ligacao_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_agenda_google_ligacao_evento', columns: ['conexao_id', 'evento_id'])]
#[ORM\UniqueConstraint(name: 'uniq_agenda_google_ligacao_google', columns: ['conexao_id', 'google_id'])]
class LigacaoComGoogle implements TenantAware
{
    public const RECUSADO = 'x:';

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
        #[ORM\ManyToOne(targetEntity: Evento::class)]
        #[ORM\JoinColumn(name: 'evento_id', nullable: false, onDelete: 'CASCADE')]
        private Evento $evento,
        #[ORM\Column(name: 'google_id', length: 1024)]
        private string $googleId,
    ) {
    }

    /** Igual ao Google depois de enviar ou de trazer. */
    public function sincronizou(string $hash): void
    {
        $this->hash = $hash;
    }

    /** O Google recusou esta versão: não tenta de novo até o compromisso mudar. */
    public function recusada(string $resumo): void
    {
        $this->hash = self::RECUSADO . $resumo;
    }

    /** Força o próximo envio (a cópia do participante foi mexida no Google: só leitura, volta como aqui). */
    public function esquecerEnvio(): void
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

    public function getEvento(): Evento
    {
        return $this->evento;
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
