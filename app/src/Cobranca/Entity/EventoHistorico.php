<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Evento da linha do tempo OPERACIONAL do Caso de Cobrança (SPEC §13). É o histórico de
 * domínio, visível ao usuário, escrito EXPLICITAMENTE pelos UseCases — NÃO é auditoria técnica
 * (invariável 26), por isso NÃO implementa Auditavel (seria log de log). Append-only: registra
 * o que aconteceu no trabalho de cobrança (data, usuário, descrição e dados necessários).
 */
#[ORM\Entity(repositoryClass: EventoHistoricoRepository::class)]
#[ORM\Table(name: 'cobranca_evento_historico')]
#[ORM\Index(name: 'idx_cobranca_evento_tenant_caso', columns: ['tenant_id', 'caso_id'])]
// Eixo da Central de Acompanhamento: "o que a equipe fez NO PERÍODO". Os índices por caso/usuário não
// servem para filtrar por faixa de data, que é o filtro de toda consulta daquela tela.
#[ORM\Index(name: 'idx_cobranca_evento_tenant_ocorrido', columns: ['tenant_id', 'ocorrido_em'])]
class EventoHistorico implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?CasoCobranca $caso = null;

    #[ORM\Column(enumType: TipoEventoHistorico::class)]
    private TipoEventoHistorico $tipo = TipoEventoHistorico::CasoAberto;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $ocorridoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $usuario = null;

    #[ORM\Column(type: 'text')]
    private string $descricao = '';

    /** Dados estruturados do evento (ex.: valor solicitado, novo prazo, de→para). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dados = null;

    /**
     * Quando o texto foi reescrito pelo autor (ajuste 2026-07). Só ANOTAÇÃO é editável, e só na janela
     * de `JANELA_EDICAO`; null = nunca editada. A timeline mostra "(editado)" quando preenchido — o
     * leitor precisa saber que o texto de hoje não é o que foi escrito na hora.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editadoEm = null;

    /**
     * Janela em que o AUTOR pode corrigir ou apagar a própria anotação (decisão do dono, 2026-07-22).
     * Passado esse prazo o registro fica firme: histórico de cobrança pode ser levado a juízo, e um
     * texto reescrito meses depois não vale como prova do que foi feito.
     */
    public const JANELA_EDICAO = 'PT48H';

    /**
     * Janela para DESFAZER uma qualificação de contato (2026-07-27). É muito mais curta que a da
     * anotação de propósito: a qualificação nasce de um clique único, sem formulário nem confirmação,
     * então a válvula existe para o engano do momento — não para revisar o registro depois.
     */
    public const JANELA_DESFAZER_QUALIFICACAO = 'PT5M';

    public function __construct()
    {
        $this->ocorridoEm = new \DateTimeImmutable();
    }

    /**
     * Só ANOTAÇÃO é editável/apagável, e só pelo próprio autor dentro da janela. Contato, acordo,
     * pagamento e os demais NÃO são texto de alguém — são o que o sistema registrou quando o fato
     * aconteceu; reescrevê-los falsificaria o histórico.
     */
    public function podeSerEditadaPor(?User $usuario, \DateTimeImmutable $agora): bool
    {
        if ($this->tipo !== TipoEventoHistorico::Anotacao || $usuario === null || $this->usuario === null) {
            return false;
        }

        if ($this->usuario->getId() !== $usuario->getId()) {
            return false;
        }

        return $agora <= $this->ocorridoEm->add(new \DateInterval(self::JANELA_EDICAO));
    }

    /**
     * Condições de DESFAZER que dependem só deste evento: ser uma qualificação de contato, ter sido
     * registrada por quem está desfazendo, e estar dentro dos 5 minutos.
     *
     * A quarta condição da spec — ser a qualificação MAIS RECENTE do caso — não cabe aqui: um evento
     * não conhece os irmãos. Ela é verificada no `DesfazerQualificacaoContatoUseCase`, que é quem tem
     * o caso inteiro à mão. Manter a divisão explícita evita a armadilha de alguém achar que este
     * método sozinho autoriza a remoção.
     */
    public function podeSerDesfeitaPor(?User $usuario, \DateTimeImmutable $agora): bool
    {
        if ($this->tipo !== TipoEventoHistorico::QualificacaoContato || $usuario === null || $this->usuario === null) {
            return false;
        }

        if ($this->usuario->getId() !== $usuario->getId()) {
            return false;
        }

        return $agora <= $this->ocorridoEm->add(new \DateInterval(self::JANELA_DESFAZER_QUALIFICACAO));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

        return $this;
    }

    public function getTipo(): TipoEventoHistorico
    {
        return $this->tipo;
    }

    public function setTipo(TipoEventoHistorico $tipo): self
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getOcorridoEm(): \DateTimeImmutable
    {
        return $this->ocorridoEm;
    }

    public function setOcorridoEm(\DateTimeImmutable $ocorridoEm): self
    {
        $this->ocorridoEm = $ocorridoEm;

        return $this;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;

        return $this;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function getDados(): ?array
    {
        return $this->dados;
    }

    public function setDados(?array $dados): self
    {
        $this->dados = $dados;

        return $this;
    }

    public function getEditadoEm(): ?\DateTimeImmutable
    {
        return $this->editadoEm;
    }

    /**
     * Reescreve o texto e carimba a edição. Não mexe em `ocorridoEm`: a janela de 48h continua contando
     * do registro original, senão editar de hora em hora renovaria o prazo para sempre.
     */
    public function reescrever(string $descricao, \DateTimeImmutable $em): self
    {
        $this->descricao = $descricao;
        $this->editadoEm = $em;

        return $this;
    }
}
