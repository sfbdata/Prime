<?php

declare(strict_types=1);

namespace App\Shared\Scheduler\Entity;

use App\Entity\Auth\User;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma execução de tarefa agendada que falhou (spec scheduler-tarefas-agendadas, "Aviso de falha").
 *
 * Dado do SISTEMA, sem escritório: a tarefa roda para todos. Fora do TenantAware e da auditoria
 * (coberturas em TenantAwareCoberturaTest e AuditavelCoberturaTest).
 *
 * Guarda só o comando, a hora, a classe do erro e o código de saída. A mensagem e a saída do comando
 * podem trazer dado de cliente e ficam só no log do contêiner do agendador.
 *
 * Pendente enquanto `dispensada_em` é NULL: é o que o aviso da moldura mostra ao Master e ao e-mail de
 * AGENDADOR_ALERTA_EMAIL. Dispensar marca todas as pendentes de uma vez, com quem e quando
 * ({@see FalhaAgendadaRepository::dispensarPendentes()}).
 * Gravada por SQL ({@see FalhaAgendadaRepository::registrar()}), para não depender do EntityManager do
 * consumidor depois de uma falha.
 */
#[ORM\Entity(repositoryClass: FalhaAgendadaRepository::class)]
#[ORM\Table(name: 'falha_agendada')]
#[ORM\Index(name: 'idx_falha_agendada_pendentes', columns: ['dispensada_em', 'ocorrida_em'])]
class FalhaAgendada
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'dispensada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dispensadaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'dispensada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $dispensadaPor = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $comando,
        #[ORM\Column(name: 'ocorrida_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $ocorridaEm,
        #[ORM\Column(name: 'classe_erro', length: 255)]
        private string $classeErro,
        #[ORM\Column(name: 'codigo_saida', type: 'integer', nullable: true)]
        private ?int $codigoSaida = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getComando(): string
    {
        return $this->comando;
    }

    public function getOcorridaEm(): \DateTimeImmutable
    {
        return $this->ocorridaEm;
    }

    public function getClasseErro(): string
    {
        return $this->classeErro;
    }

    public function getCodigoSaida(): ?int
    {
        return $this->codigoSaida;
    }

    public function getDispensadaEm(): ?\DateTimeImmutable
    {
        return $this->dispensadaEm;
    }

    public function getDispensadaPor(): ?User
    {
        return $this->dispensadaPor;
    }
}
