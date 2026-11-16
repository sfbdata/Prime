<?php

declare(strict_types=1);

namespace App\Conector\Entity;

use App\Conector\Enum\EstadoDaPropostaDoConector;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A ação que espera a confirmação da pessoa no chat (`docs/specs/mcp-remoto-oauth.md`, 6.7 e 13.2): cadastrar
 * cliente e toda exclusão (gravar por cima inclusive). Presa à pessoa, ao escritório, ao consentimento e ao app de
 * I.A. do token que a pediu; vale 10 minutos e é de uso único. Guarda só o hash do id que vai na resposta.
 *
 * `dados` (o JSON exato do que será feito, pode ter CPF) e `resumo` (o texto que a pessoa lê) são ZERADOS ao
 * confirmar, cancelar ou vencer: sobra estado, datas e o `resultado` (ids, sem texto). Por isso a proposta não é
 * `Auditavel`: `dados` e `resumo` nunca vão para log nem auditoria (6.7, item 6).
 *
 * Transição única: `pendente` para `confirmada`, `cancelada` ou `expirada`. A reconferência da confirmação (mesma
 * conexão, resumo e hash iguais, permissão de novo) é do PR 9. FKs em cascata para escritório, pessoa, consentimento e
 * app (9.9).
 */
#[ORM\Entity(repositoryClass: PropostaDoConectorRepository::class)]
#[ORM\Table(name: 'conector_ia_proposta')]
#[ORM\UniqueConstraint(name: 'uniq_conector_ia_proposta_identificador', columns: ['identificador_hash'])]
#[ORM\Index(name: 'idx_conector_ia_proposta_dono', columns: ['tenant_id', 'user_id', 'estado'])]
#[ORM\Index(name: 'idx_conector_ia_proposta_consentimento', columns: ['consentimento_id'])]
#[ORM\Index(name: 'idx_conector_ia_proposta_cliente', columns: ['cliente_id'])]
#[ORM\Index(name: 'idx_conector_ia_proposta_user', columns: ['user_id'])]
class PropostaDoConector implements TenantAware
{
    use ConfereHash;

    /** A proposta vale 10 minutos a partir de quando foi feita. */
    public const VALIDADE = 'PT10M';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'identificador_hash', length: 64)]
    private string $identificadorHash;

    /** Copiados do consentimento: pessoa, escritório e app são os dele, nunca outros. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: ClienteDoConector::class)]
    #[ORM\JoinColumn(name: 'cliente_id', nullable: false, onDelete: 'CASCADE')]
    private ClienteDoConector $cliente;

    /** @var array<string, mixed>|null o que será feito; nulo depois de decidida ou vencida */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dados;

    #[ORM\Column(name: 'dados_hash', length: 64)]
    private string $dadosHash;

    /** O texto que a pessoa lê; nulo depois de decidida ou vencida. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resumo;

    #[ORM\Column(length: 12, enumType: EstadoDaPropostaDoConector::class)]
    private EstadoDaPropostaDoConector $estado = EstadoDaPropostaDoConector::Pendente;

    /** @var list<int>|null ids do que foi feito, sem texto */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resultado = null;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiraEm;

    #[ORM\Column(name: 'decidida_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decididaEm = null;

    /**
     * @param array<string, mixed> $dados
     */
    public function __construct(
        string $identificadorHash,
        #[ORM\ManyToOne(targetEntity: ConsentimentoDoConector::class)]
        #[ORM\JoinColumn(name: 'consentimento_id', nullable: false, onDelete: 'CASCADE')]
        private ConsentimentoDoConector $consentimento,
        #[ORM\Column(length: 60)]
        private string $ferramenta,
        #[ORM\Column(length: 60)]
        private string $acao,
        array $dados,
        string $dadosHash,
        string $resumo,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
    ) {
        if (!$consentimento->isAtivo()) {
            throw new \DomainException('Consentimento revogado não registra proposta.');
        }
        if (trim($resumo) === '') {
            throw new \InvalidArgumentException('A proposta precisa de um resumo do que vai acontecer.');
        }
        $this->identificadorHash = self::exigirHash($identificadorHash, 'identificador_hash');
        $this->dadosHash = self::exigirHash($dadosHash, 'dados_hash');
        $this->user = $consentimento->getUser();
        $this->tenant = $consentimento->getTenant() ?? throw new \LogicException('Consentimento sem escritório.');
        $this->cliente = $consentimento->getCliente();
        $this->dados = $dados;
        $this->resumo = $resumo;
        $this->expiraEm = $criadaEm->add(new \DateInterval(self::VALIDADE));
    }

    /**
     * A pessoa confirmou e a ação rodou: guarda os ids do resultado e zera os dados. Só pendente e no prazo.
     *
     * @param list<int> $idsDoResultado
     */
    public function confirmar(array $idsDoResultado, \DateTimeImmutable $agora): void
    {
        $this->exigirPendenteNoPrazo($agora);
        $this->resultado = $this->exigirIds($idsDoResultado);
        $this->decidir(EstadoDaPropostaDoConector::Confirmada, $agora);
    }

    /** A pessoa recusou (ou a elicitation foi cancelada): zera os dados. Só pendente. */
    public function cancelar(\DateTimeImmutable $agora): void
    {
        $this->exigirPendente();
        $this->decidir(EstadoDaPropostaDoConector::Cancelada, $agora);
    }

    /** Vencida: vira `expirada` e zera os dados. Pendente ainda no prazo não expira. */
    public function expirar(\DateTimeImmutable $agora): void
    {
        $this->exigirPendente();
        if (!$this->venceuEm($agora)) {
            throw new \DomainException('A proposta ainda está no prazo.');
        }
        $this->decidir(EstadoDaPropostaDoConector::Expirada, $agora);
    }

    public function venceuEm(\DateTimeImmutable $agora): bool
    {
        return $agora >= $this->expiraEm;
    }

    private function decidir(EstadoDaPropostaDoConector $estado, \DateTimeImmutable $agora): void
    {
        $this->estado = $estado;
        $this->decididaEm = $agora;
        $this->dados = null;
        $this->resumo = null;
    }

    private function exigirPendente(): void
    {
        if ($this->estado !== EstadoDaPropostaDoConector::Pendente) {
            throw new \DomainException('A proposta já foi decidida.');
        }
    }

    private function exigirPendenteNoPrazo(\DateTimeImmutable $agora): void
    {
        $this->exigirPendente();
        if ($this->venceuEm($agora)) {
            throw new \DomainException('A proposta venceu.');
        }
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<int>
     */
    private function exigirIds(array $ids): array
    {
        foreach ($ids as $id) {
            if (!\is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('O resultado da proposta guarda só ids.');
            }
        }

        return array_values($ids);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentificadorHash(): string
    {
        return $this->identificadorHash;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getConsentimento(): ConsentimentoDoConector
    {
        return $this->consentimento;
    }

    public function getCliente(): ClienteDoConector
    {
        return $this->cliente;
    }

    public function getFerramenta(): string
    {
        return $this->ferramenta;
    }

    public function getAcao(): string
    {
        return $this->acao;
    }

    /** @return array<string, mixed>|null */
    public function getDados(): ?array
    {
        return $this->dados;
    }

    public function getDadosHash(): string
    {
        return $this->dadosHash;
    }

    public function getResumo(): ?string
    {
        return $this->resumo;
    }

    public function getEstado(): EstadoDaPropostaDoConector
    {
        return $this->estado;
    }

    /** @return list<int>|null */
    public function getResultado(): ?array
    {
        return $this->resultado;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getDecididaEm(): ?\DateTimeImmutable
    {
        return $this->decididaEm;
    }
}
