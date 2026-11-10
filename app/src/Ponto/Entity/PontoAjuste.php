<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\PontoAjusteRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um ajuste de batida (E10, plano-e10-ponto §6): a batida gravada em `registro_ponto` nunca muda (gatilho
 * `registro_ponto_so_insercao`), e cada correção do gestor entra aqui como uma linha nova, presa à anterior da mesma
 * batida por `anterior`. A linhagem começa no registro original (`registroPonto`) ou numa inclusão (`inclusao`), e toda
 * linha depois da raiz repete a raiz; a cabeça é a linha sem sucessora, e é ela que a folha conta.
 *
 * Imutável: nasce pelos construtores nomeados e não tem setter. Quem grava é a
 * {@see \App\Ponto\Service\GravacaoDoAjusteDoPonto}, sob a trava da pessoa; quem lê a linhagem é o
 * {@see \App\Ponto\Repository\RegistroPontoRepository}. Fora do `audit_log` (não é Auditavel): o evento vai para a
 * trilha do Ponto. O autor fica em `autorNome`, retrato do momento, porque `autor` vira nulo se o usuário for apagado.
 *
 * Os CHECKs da migration Version20261109280000 garantem a forma de cada tipo; os índices únicos parciais garantem uma
 * linhagem sem bifurcação (um sucessor por linha, uma primeira linha por registro).
 */
#[ORM\Entity(repositoryClass: PontoAjusteRepository::class)]
#[ORM\Table(name: 'ponto_ajuste')]
#[ORM\Index(name: 'idx_ponto_ajuste_pessoa_data', columns: ['tenant_id', 'user_id', 'data_hora_nova'])]
#[ORM\Index(name: 'idx_ponto_ajuste_inclusao', columns: ['inclusao_id'])]
#[ORM\Index(name: 'idx_ponto_ajuste_justificativa', columns: ['justificativa_id'])]
#[ORM\Index(name: 'idx_ponto_ajuste_desfaz', columns: ['desfaz_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ponto_ajuste_anterior', columns: ['anterior_id'], options: ['where' => '(anterior_id IS NOT NULL)'])]
#[ORM\UniqueConstraint(
    name: 'uniq_ponto_ajuste_raiz_batida',
    columns: ['registro_ponto_id'],
    options: ['where' => '((anterior_id IS NULL) AND (registro_ponto_id IS NOT NULL))'],
)]
class PontoAjuste implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class), ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class), ORM\JoinColumn(name: 'user_id', nullable: false)]
        private User $user,
        #[ORM\Column(length: 15, enumType: TipoDoAjuste::class)]
        private TipoDoAjuste $tipo,
        #[ORM\Column(type: 'text')]
        private string $motivo,
        #[ORM\ManyToOne(targetEntity: User::class), ORM\JoinColumn(name: 'autor_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $autor,
        #[ORM\Column(length: 255)]
        private string $autorNome,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
        #[ORM\ManyToOne(targetEntity: RegistroPonto::class), ORM\JoinColumn(nullable: true)]
        private ?RegistroPonto $registroPonto = null,
        #[ORM\ManyToOne(targetEntity: PontoAjuste::class), ORM\JoinColumn(nullable: true)]
        private ?PontoAjuste $inclusao = null,
        #[ORM\ManyToOne(targetEntity: PontoAjuste::class), ORM\JoinColumn(nullable: true)]
        private ?PontoAjuste $anterior = null,
        #[ORM\ManyToOne(targetEntity: PontoAjuste::class), ORM\JoinColumn(nullable: true)]
        private ?PontoAjuste $desfaz = null,
        #[ORM\ManyToOne(targetEntity: JustificativaPonto::class), ORM\JoinColumn(nullable: true)]
        private ?JustificativaPonto $justificativa = null,
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $tipoMarcacaoAnterior = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $dataHoraAnterior = null,
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $tipoMarcacaoNova = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $dataHoraNova = null,
    ) {
    }

    /** A batida que não existia: raiz da própria linhagem (o alvo dela é 'a{id}'). */
    public static function inclusao(
        Tenant $escritorio,
        User $pessoa,
        string $tipoMarcacao,
        \DateTimeImmutable $dataHora,
        string $motivo,
        User $autor,
        string $autorNome,
        \DateTimeImmutable $em,
        ?JustificativaPonto $justificativa = null,
    ): self {
        return new self(
            $escritorio,
            $pessoa,
            TipoDoAjuste::Inclusao,
            $motivo,
            $autor,
            $autorNome,
            $em,
            justificativa: $justificativa,
            tipoMarcacaoNova: self::marcacaoValida($tipoMarcacao),
            dataHoraNova: $dataHora,
        );
    }

    /** Novo tipo, data ou hora para a batida do alvo, presa à cabeça atual dele. */
    public static function alteracao(
        AlvoDoAjuste $alvo,
        string $tipoMarcacao,
        \DateTimeImmutable $dataHora,
        string $motivo,
        User $autor,
        string $autorNome,
        \DateTimeImmutable $em,
    ): self {
        self::exigirNaConta($alvo);

        return self::depoisDaCabeca($alvo, TipoDoAjuste::Alteracao, $motivo, $autor, $autorNome, $em, null, null, self::marcacaoValida($tipoMarcacao), $dataHora);
    }

    /** Tira da conta a batida do alvo; `$desfaz` é o ajuste que esta desconsideração desfaz (a inclusão do abono revertido). */
    public static function desconsideracao(
        AlvoDoAjuste $alvo,
        string $motivo,
        User $autor,
        string $autorNome,
        \DateTimeImmutable $em,
        ?JustificativaPonto $justificativa = null,
        ?self $desfaz = null,
    ): self {
        self::exigirNaConta($alvo);

        return self::depoisDaCabeca($alvo, TipoDoAjuste::Desconsideracao, $motivo, $autor, $autorNome, $em, $justificativa, $desfaz, null, null);
    }

    /**
     * Desfaz a desconsideração: a batida volta com o tipo e a data/hora que tinha antes dela. Presa à própria
     * desconsideração, que precisa ser a cabeça (o índice único de `anterior_id` recusa a bifurcação).
     */
    public static function restauracao(self $desconsideracao, string $motivo, User $autor, string $autorNome, \DateTimeImmutable $em): self
    {
        if ($desconsideracao->tipo !== TipoDoAjuste::Desconsideracao) {
            throw new \LogicException('Só a desconsideração se restaura.');
        }

        return new self(
            $desconsideracao->tenant,
            $desconsideracao->user,
            TipoDoAjuste::Restauracao,
            $motivo,
            $autor,
            $autorNome,
            $em,
            registroPonto: $desconsideracao->registroPonto,
            inclusao: $desconsideracao->inclusao,
            anterior: $desconsideracao,
            desfaz: $desconsideracao,
            tipoMarcacaoNova: $desconsideracao->tipoMarcacaoAnterior,
            dataHoraNova: $desconsideracao->dataHoraAnterior,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTipo(): TipoDoAjuste
    {
        return $this->tipo;
    }

    public function getMotivo(): string
    {
        return $this->motivo;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getAutorNome(): string
    {
        return $this->autorNome;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getRegistroPonto(): ?RegistroPonto
    {
        return $this->registroPonto;
    }

    public function getInclusao(): ?self
    {
        return $this->inclusao;
    }

    public function getAnterior(): ?self
    {
        return $this->anterior;
    }

    public function getDesfaz(): ?self
    {
        return $this->desfaz;
    }

    public function getJustificativa(): ?JustificativaPonto
    {
        return $this->justificativa;
    }

    public function getTipoMarcacaoAnterior(): ?string
    {
        return $this->tipoMarcacaoAnterior;
    }

    public function getDataHoraAnterior(): ?\DateTimeImmutable
    {
        return $this->dataHoraAnterior;
    }

    public function getTipoMarcacaoNova(): ?string
    {
        return $this->tipoMarcacaoNova;
    }

    public function getDataHoraNova(): ?\DateTimeImmutable
    {
        return $this->dataHoraNova;
    }

    /** O alvo da linhagem deste ajuste: 'b{id do registro}' ou 'a{id da inclusão}' (a própria, se for a inclusão). */
    public function alvo(): string
    {
        if ($this->registroPonto !== null) {
            return 'b' . ($this->registroPonto->getId() ?? throw new \LogicException('O registro da linhagem ainda não foi gravado.'));
        }
        $inclusao = $this->tipo === TipoDoAjuste::Inclusao ? $this : $this->inclusao;

        return 'a' . ($inclusao?->getId() ?? throw new \LogicException('A inclusão da linhagem ainda não foi gravada.'));
    }

    private static function depoisDaCabeca(
        AlvoDoAjuste $alvo,
        TipoDoAjuste $tipo,
        string $motivo,
        User $autor,
        string $autorNome,
        \DateTimeImmutable $em,
        ?JustificativaPonto $justificativa,
        ?self $desfaz,
        ?string $tipoMarcacaoNova,
        ?\DateTimeImmutable $dataHoraNova,
    ): self {
        return new self(
            $alvo->escritorio,
            $alvo->pessoa,
            $tipo,
            $motivo,
            $autor,
            $autorNome,
            $em,
            registroPonto: $alvo->raizBatida,
            inclusao: $alvo->raizInclusao,
            anterior: $alvo->cabeca,
            desfaz: $desfaz,
            justificativa: $justificativa,
            tipoMarcacaoAnterior: $alvo->tipoAtual,
            dataHoraAnterior: $alvo->dataHoraAtual,
            tipoMarcacaoNova: $tipoMarcacaoNova,
            dataHoraNova: $dataHoraNova,
        );
    }

    private static function exigirNaConta(AlvoDoAjuste $alvo): void
    {
        if ($alvo->tipoAtual === null || $alvo->dataHoraAtual === null) {
            throw new \LogicException('A batida está fora da conta: só a restauração a traz de volta.');
        }
    }

    private static function marcacaoValida(string $tipo): string
    {
        if (!\in_array($tipo, RegistroPonto::TIPOS_VALIDOS, true)) {
            throw new \InvalidArgumentException('Tipo de batida inválido.');
        }

        return $tipo;
    }
}
