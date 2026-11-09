<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um evento da trilha de auditoria do Ponto (spec `ponto-desenho-0811.md`, E9, P64). **Só mapeamento**, como a
 * {@see \App\Shared\EspelhoRemoto\Entity\LiberacaoDeArquivo}: existe para o Doctrine conhecer a tabela, e quem grava e
 * lê é o {@see TrilhaDoPontoRepository}, por DBAL, para a gravação nunca dar `flush()` na unidade de trabalho de quem
 * chama.
 *
 * A trilha é uma cadeia por escritório: `sequencia` começa em 1 e cresce sem buraco, e cada linha guarda o selo da
 * anterior (`selo_anterior`). O selo é um HMAC-SHA256 ({@see \App\Ponto\Service\SeloDaTrilhaDoPonto}) com a chave da
 * versão gravada em `versao_da_chave`; sem segredo configurado a linha entra com selo e versão nulos.
 *
 * `autor_id` é o id de quem agiu no momento do evento, SEM FK: entra no selo, e um `SET NULL` ao apagar o usuário
 * mudaria uma coluna selada e quebraria a cadeia (mesma razão do `usuario_ref` da trilha da assinatura). O nome fica
 * em `autor_nome`, retrato do momento.
 */
#[ORM\Entity(repositoryClass: TrilhaDoPontoRepository::class, readOnly: true)]
#[ORM\Table(name: 'ponto_auditoria')]
#[ORM\UniqueConstraint(name: 'uniq_ponto_auditoria_sequencia', columns: ['tenant_id', 'sequencia'])]
#[ORM\Index(name: 'idx_ponto_auditoria_em', columns: ['tenant_id', 'em'])]
class EventoDaTrilhaDoPonto implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'integer')]
    private int $sequencia = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    #[ORM\Column(name: 'autor_id', type: 'integer', nullable: true)]
    private ?int $autorId = null;

    #[ORM\Column(name: 'autor_nome', type: 'text', nullable: true)]
    private ?string $autorNome = null;

    #[ORM\Column(length: 40)]
    private string $acao = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $detalhe = [];

    #[ORM\Column(name: 'selo_anterior', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $seloAnterior = null;

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $selo = null;

    #[ORM\Column(name: 'versao_da_chave', type: 'smallint', nullable: true)]
    private ?int $versaoDaChave = null;

    private function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
        $this->em     = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }
}
