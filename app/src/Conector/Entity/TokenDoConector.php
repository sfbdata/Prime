<?php

declare(strict_types=1);

namespace App\Conector\Entity;

use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Enum\RotacaoDaRenovacao;
use App\Conector\Enum\TipoDoTokenDoConector;
use App\Conector\Exception\RenovacaoReutilizada;
use App\Conector\Repository\TokenDoConectorRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Token opaco do conector, de acesso ou de renovação (`docs/specs/mcp-remoto-oauth.md`, 3.2, 9.1, 13.2 e decisão 14.c).
 * São 256 bits aleatórios entregues ao app; o banco guarda só o SHA-256 em `identificador_hash`.
 *
 * - **Acesso** vale 1 hora. **Renovação** vale até o `expira_em` da concessão (7 dias da autorização) e gira a cada uso.
 * - `gerado_por` é a renovação que gerou este par (nulo no primeiro par, que sai do código).
 * - **Rotação** ({@see girar()}): renovação nunca usada grava `usado_em` e o servidor emite um par novo. Já usada e
 *   reapresentada pelo MESMO app em até {@see JANELA_DE_TOLERANCIA} segundos do `usado_em`: o servidor revoga o par
 *   emitido antes a partir dela e emite outro. Fora da janela, ou por outro app: a concessão cai
 *   ({@see RenovacaoReutilizada}).
 *
 * Escritório, pessoa, validade de 7 dias, corte de "Sair de todos os aparelhos" e impressão da senha moram na concessão.
 */
#[ORM\Entity(repositoryClass: TokenDoConectorRepository::class)]
#[ORM\Table(name: 'conector_ia_token')]
#[ORM\UniqueConstraint(name: 'uniq_conector_ia_token_identificador', columns: ['identificador_hash'])]
#[ORM\Index(name: 'idx_conector_ia_token_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_conector_ia_token_concessao', columns: ['concessao_id'])]
#[ORM\Index(name: 'idx_conector_ia_token_gerado_por', columns: ['gerado_por_id'])]
class TokenDoConector implements TenantAware
{
    use ConfereHash;

    /** O token de acesso vale 1 hora (decisão 14.c). */
    public const VALIDADE_DO_ACESSO = 'PT1H';

    /** Segundos, contados do `usado_em`, em que a mesma renovação reapresentada pelo mesmo app ainda gira (9.1). */
    public const JANELA_DE_TOLERANCIA = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'identificador_hash', length: 64)]
    private string $identificadorHash;

    /** Copiado da concessão. */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $escopos;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiraEm;

    #[ORM\Column(name: 'usado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usadoEm = null;

    #[ORM\Column(name: 'revogado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revogadoEm = null;

    private function __construct(
        string $identificadorHash,
        #[ORM\Column(length: 10, enumType: TipoDoTokenDoConector::class)]
        private TipoDoTokenDoConector $tipo,
        #[ORM\ManyToOne(targetEntity: ConcessaoDoConector::class)]
        #[ORM\JoinColumn(name: 'concessao_id', nullable: false, onDelete: 'CASCADE')]
        private ConcessaoDoConector $concessao,
        #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'gerado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?self $geradoPor,
    ) {
        if (!$concessao->estaValidaEm($criadoEm)) {
            throw new \DomainException('Concessão revogada ou vencida não emite token.');
        }
        if ($geradoPor !== null && ($geradoPor->getTipo() !== TipoDoTokenDoConector::Renovacao || $geradoPor->getConcessao() !== $concessao)) {
            throw new \LogicException('O par só é gerado por uma renovação da mesma concessão.');
        }
        $this->identificadorHash = self::exigirHash($identificadorHash, 'identificador_hash');
        $this->tenant = $concessao->getTenant() ?? throw new \LogicException('Concessão sem escritório.');
        $this->escopos = $concessao->getEscopos();
        $this->expiraEm = $tipo === TipoDoTokenDoConector::Acesso
            ? $criadoEm->add(new \DateInterval(self::VALIDADE_DO_ACESSO))
            : $concessao->getExpiraEm();
    }

    public static function deAcesso(string $identificadorHash, ConcessaoDoConector $concessao, \DateTimeImmutable $agora, ?self $geradoPor = null): self
    {
        return new self($identificadorHash, TipoDoTokenDoConector::Acesso, $concessao, $agora, $geradoPor);
    }

    public static function deRenovacao(string $identificadorHash, ConcessaoDoConector $concessao, \DateTimeImmutable $agora, ?self $geradoPor = null): self
    {
        return new self($identificadorHash, TipoDoTokenDoConector::Renovacao, $concessao, $agora, $geradoPor);
    }

    /**
     * Usa esta renovação para girar (9.1). Revogada, vencida ou com a concessão inválida: recusa sem mexer em nada.
     * Outro app, ou repetição fora da janela: revoga a concessão e lança {@see RenovacaoReutilizada}.
     */
    public function girar(ClienteDoConector $quemApresentou, \DateTimeImmutable $agora): RotacaoDaRenovacao
    {
        $this->exigirRenovacaoUtilizavel($agora);
        if (!$this->mesmoApp($quemApresentou)) {
            $this->derrubarCadeia($agora);
        }
        if ($this->usadoEm === null) {
            $this->usadoEm = $agora;

            return RotacaoDaRenovacao::Primeira;
        }
        if ($agora->getTimestamp() - $this->usadoEm->getTimestamp() > self::JANELA_DE_TOLERANCIA) {
            $this->derrubarCadeia($agora);
        }

        return RotacaoDaRenovacao::RepeticaoNaJanela;
    }

    /** Revogar de novo não muda a data da primeira revogação. */
    public function revogar(\DateTimeImmutable $agora): void
    {
        if ($this->revogadoEm !== null) {
            return;
        }

        $this->revogadoEm = $agora;
    }

    public function venceuEm(\DateTimeImmutable $agora): bool
    {
        return $agora >= $this->expiraEm;
    }

    private function exigirRenovacaoUtilizavel(\DateTimeImmutable $agora): void
    {
        if ($this->tipo !== TipoDoTokenDoConector::Renovacao) {
            throw new \LogicException('Só a renovação gira.');
        }
        if ($this->revogadoEm !== null || $this->venceuEm($agora) || !$this->concessao->estaValidaEm($agora)) {
            throw new \DomainException('A renovação não vale mais.');
        }
    }

    private function mesmoApp(ClienteDoConector $quemApresentou): bool
    {
        $cliente = $this->concessao->getCliente();

        return $quemApresentou === $cliente
            || ($quemApresentou->getId() !== null && $quemApresentou->getId() === $cliente->getId());
    }

    private function derrubarCadeia(\DateTimeImmutable $agora): never
    {
        $this->concessao->revogar(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $agora);

        throw new RenovacaoReutilizada();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentificadorHash(): string
    {
        return $this->identificadorHash;
    }

    public function getTipo(): TipoDoTokenDoConector
    {
        return $this->tipo;
    }

    public function getConcessao(): ConcessaoDoConector
    {
        return $this->concessao;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /** @return list<string> */
    public function getEscopos(): array
    {
        return $this->escopos;
    }

    public function getGeradoPor(): ?self
    {
        return $this->geradoPor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getUsadoEm(): ?\DateTimeImmutable
    {
        return $this->usadoEm;
    }

    public function getRevogadoEm(): ?\DateTimeImmutable
    {
        return $this->revogadoEm;
    }
}
