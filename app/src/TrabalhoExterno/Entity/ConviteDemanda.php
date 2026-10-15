<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\SituacaoConvite;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Convite de um profissional externo para UMA demanda (spec §8). Não cria vínculo com escritório:
 * quem aceita enxerga só esta demanda, como Convidado, e só enquanto o convite estiver vigente.
 *
 * O token não fica no banco: só o hash SHA-256 dele. Quem tem o banco não tem o link; quem tem o
 * link ainda precisa entrar com a conta do e-mail convidado para aceitar.
 */
#[ORM\Entity(repositoryClass: ConviteDemandaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_convite')]
#[ORM\UniqueConstraint(name: 'uniq_te_convite_token', columns: ['token_hash'])]
class ConviteDemanda
{
    /** As permissões do desenho (`Component.PERMS`), todas ligadas por padrão no formulário. */
    public const PERMISSOES = [
        'ver' => 'Ver instruções',
        'baixar' => 'Baixar documentos',
        'enviar' => 'Enviar arquivos',
        'chat' => 'Conversar no chat',
        'andamento' => 'Registrar andamento',
        'concluir' => 'Marcar como concluída',
    ];

    public const MAX_REENVIOS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: SituacaoConvite::class, length: 20)]
    private SituacaoConvite $situacao = SituacaoConvite::Enviado;

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $permissoes;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $convidado = null;

    #[ORM\Column(length: 255)]
    private string $nomeAutor;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $enviadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $aceitoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revogadoEm = null;

    #[ORM\Column]
    private int $reenvios = 0;

    /**
     * Id da última mensagem do chat no momento do aceite: o Convidado lê só as POSTERIORES. Por id e
     * não por horário, porque as datas são gravadas sem fração de segundo (um registro do mesmo
     * segundo do aceite passaria).
     */
    #[ORM\Column]
    private int $ultimaMensagemAntesDoAceite = 0;

    /** @param array<string, bool> $permissoes */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'convites')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        #[ORM\Column(length: 255)]
        private string $nome,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $funcao,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $validade,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        array $permissoes,
        Autor $autor,
    ) {
        $this->email = mb_strtolower(trim($email));
        $this->permissoes = [];
        foreach (array_keys(self::PERMISSOES) as $chave) {
            $this->permissoes[$chave] = (bool) ($permissoes[$chave] ?? false);
        }
        $this->nomeAutor = $autor->nome;
        $this->enviadoEm = new \DateTimeImmutable();
    }

    public static function hashDoToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Vale para acesso: aceito, não revogado e dentro da validade (o dia da validade conta). */
    public function vigente(\DateTimeImmutable $hoje): bool
    {
        return $this->situacao === SituacaoConvite::Aceito && $this->validade >= $hoje->setTime(0, 0);
    }

    /** O link ainda pode ser usado para aceitar. */
    public function aceitavel(\DateTimeImmutable $hoje): bool
    {
        return $this->situacao !== SituacaoConvite::Revogado && $this->validade >= $hoje->setTime(0, 0);
    }

    public function destinadoA(User $usuario): bool
    {
        return mb_strtolower((string) $usuario->getEmail()) === $this->email;
    }

    public function aceitar(User $usuario, \DateTimeImmutable $hoje): void
    {
        if (!$this->aceitavel($hoje)) {
            throw new TransicaoInvalida('Este convite não está mais disponível.');
        }
        if (!$this->destinadoA($usuario)) {
            throw new TransicaoInvalida('Este convite é para outro e-mail.');
        }
        if ($this->situacao === SituacaoConvite::Aceito) {
            return;
        }
        $this->situacao = SituacaoConvite::Aceito;
        $this->convidado = $usuario;
        foreach ($this->demanda->getMensagens() as $m) {
            $this->ultimaMensagemAntesDoAceite = max($this->ultimaMensagemAntesDoAceite, (int) $m->getId());
        }
        $this->aceitoEm = new \DateTimeImmutable();
    }

    public function revogar(): void
    {
        if ($this->situacao !== SituacaoConvite::Revogado) {
            $this->situacao = SituacaoConvite::Revogado;
            $this->revogadoEm = new \DateTimeImmutable();
        }
    }

    /** Novo link: o banco só guarda o hash, então reenviar é trocar o token (o antigo deixa de valer). */
    public function reenviar(string $novoTokenHash): void
    {
        if ($this->situacao === SituacaoConvite::Revogado) {
            throw new TransicaoInvalida('Convite revogado não pode ser reenviado.');
        }
        if ($this->validade < new \DateTimeImmutable('today')) {
            throw new TransicaoInvalida('Convite vencido: envie um novo convite.');
        }
        if ($this->reenvios >= self::MAX_REENVIOS) {
            throw new TransicaoInvalida(sprintf('O convite já foi reenviado %d vezes.', self::MAX_REENVIOS));
        }
        ++$this->reenvios;
        $this->tokenHash = $novoTokenHash;
    }

    public function pode(string $permissao): bool
    {
        return (bool) ($this->permissoes[$permissao] ?? false);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemanda(): DemandaExterna
    {
        return $this->demanda;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFuncao(): ?string
    {
        return $this->funcao;
    }

    public function getValidade(): \DateTimeImmutable
    {
        return $this->validade;
    }

    public function getSituacao(): SituacaoConvite
    {
        return $this->situacao;
    }

    /** @return array<string, bool> */
    public function getPermissoes(): array
    {
        return $this->permissoes;
    }

    public function getConvidado(): ?User
    {
        return $this->convidado;
    }

    public function getAceitoEm(): ?\DateTimeImmutable
    {
        return $this->aceitoEm;
    }

    /** O Convidado lê esta mensagem? (só as posteriores ao aceite; ainda não gravada = nova) */
    public function mensagemVisivel(MensagemDemanda $m): bool
    {
        return $this->aceitoEm !== null && ($m->getId() === null || $m->getId() > $this->ultimaMensagemAntesDoAceite);
    }

    public function getNomeAutor(): string
    {
        return $this->nomeAutor;
    }
}
