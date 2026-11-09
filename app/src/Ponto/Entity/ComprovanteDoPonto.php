<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\ComprovanteDoPontoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O comprovante de UMA batida (spec ponto-desenho-0811, P48 a P50, D9 e E15): o código `BJP-XXXX-XXXX-XXXX`, o NSR,
 * os dados assinados (o que a imagem e a validação mostram) e o HMAC deles, com a versão da chave que selou.
 *
 * Tudo sai do servidor, na mesma transação da batida. A validação pública confere o HMAC sobre `dadosAssinados` e
 * só então mostra o que está neles; nada é lido da batida viva (o gestor ainda edita a batida até a E10).
 *
 * O comprovante é a prova da marcação e sobrevive à batida: se o gestor apagar a batida, a ligação fica nula
 * (ON DELETE SET NULL) e o código continua autêntico, porque a validação só depende dos dados selados.
 */
#[ORM\Entity(repositoryClass: ComprovanteDoPontoRepository::class)]
#[ORM\Table(name: 'ponto_comprovante')]
#[ORM\Index(name: 'idx_ponto_comprovante_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ponto_comprovante_registro', columns: ['registro_ponto_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ponto_comprovante_codigo', columns: ['codigo'])]
class ComprovanteDoPonto implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        // Uma por batida: a unicidade é o índice `uniq_ponto_comprovante_registro` (ManyToOne com nome escolhido,
        // em vez do OneToOne, que criaria um segundo índice único com nome gerado).
        #[ORM\ManyToOne(targetEntity: RegistroPonto::class)]
        #[ORM\JoinColumn(name: 'registro_ponto_id', nullable: true, onDelete: 'SET NULL')]
        private ?RegistroPonto $registro,
        #[ORM\Column(type: 'bigint')]
        private int $nsr,
        #[ORM\Column(length: 18)]
        private string $codigo,
        #[ORM\Column(length: 64)]
        private string $hmac,
        #[ORM\Column(name: 'versao_chave', type: 'integer')]
        private int $versaoChave,
        #[ORM\Column(name: 'emitido_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $emitidoEm,
        #[ORM\Column(name: 'dados_assinados', type: 'text')]
        private string $dadosAssinados,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /** Null depois que o gestor apaga a batida (o comprovante continua valendo). */
    public function getRegistro(): ?RegistroPonto
    {
        return $this->registro;
    }

    public function getNsr(): int
    {
        return $this->nsr;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getHmac(): string
    {
        return $this->hmac;
    }

    public function getVersaoChave(): int
    {
        return $this->versaoChave;
    }

    public function getEmitidoEm(): \DateTimeImmutable
    {
        return $this->emitidoEm;
    }

    public function getDadosAssinados(): string
    {
        return $this->dadosAssinados;
    }
}
