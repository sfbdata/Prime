<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\NotificacaoRecebidaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um aviso (webhook) recebido de um gateway de cartão ou de um banco PIX, guardado ANTES de qualquer
 * processamento. Serve a duas coisas:
 *  - idempotência: a entrega é "pelo menos uma vez"; o mesmo `(origem, idEvento)` não é processado duas
 *    vezes (constraint única);
 *  - trilha: o que chegou, quando, e se já foi tratado (`processadaEm`).
 *
 * Global (sem tenant): quem manda é o gateway, e o escritório só é conhecido depois da reconsulta. Usada
 * pela C3 (Asaas e os outros gateways) e pela C4 (Cora e os bancos PIX).
 */
#[ORM\Entity(repositoryClass: NotificacaoRecebidaRepository::class)]
#[ORM\Table(name: 'contribua_notificacao_recebida')]
#[ORM\UniqueConstraint(name: 'uniq_contribua_notificacao_origem_evento', columns: ['origem', 'id_evento'])]
#[ORM\Index(name: 'idx_contribua_notificacao_pendente', columns: ['processada_em'])]
class NotificacaoRecebida
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $recebidaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processadaEm = null;

    public function __construct(
        /** 'asaas', 'mercado_pago', 'stripe', 'pagarme', 'cora', 'inter', 'bb'... */
        #[ORM\Column(length: 20)]
        private string $origem,
        #[ORM\Column(length: 120)]
        private string $idEvento,
        #[ORM\Column(length: 60)]
        private string $tipo,
        /** O que reconsultar no gateway: assinatura, cobrança, cliente (o que o aviso trouxer). */
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $idRecurso = null,
    ) {
        $this->recebidaEm = new \DateTimeImmutable();
    }

    public function marcarProcessada(): void
    {
        $this->processadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getIdEvento(): string
    {
        return $this->idEvento;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getIdRecurso(): ?string
    {
        return $this->idRecurso;
    }

    public function getProcessadaEm(): ?\DateTimeImmutable
    {
        return $this->processadaEm;
    }
}
