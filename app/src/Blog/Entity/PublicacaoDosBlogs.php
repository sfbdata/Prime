<?php

declare(strict_types=1);

namespace App\Blog\Entity;

use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDaPublicacao;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Entity\Auth\User;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma geração dos blogs pelo builder (spec painel-artigos-blogs, seções 1 e 6.1). É o histórico da faixa da lista e o
 * que impede duas gerações ao mesmo tempo: Publicar com um pedido ainda na fila não cria outro.
 *
 * GLOBAL, sem tenant, como o {@see ArtigoDoBlog}: conteúdo da plataforma, só o Master pede. Auditável (o UseCase abre
 * o escopo de plataforma).
 *
 * `artigos` nulo quer dizer que a montagem do pacote ainda não começou: só esse pedido na fila é reaproveitado por um
 * Publicar novo, porque o worker ainda vai ler os artigos do banco. O worker marca o início da montagem
 * ({@see marcarEmMontagem()}, `artigos = []`, com flush) ANTES de ler os artigos: um Publicar durante a montagem já não
 * reaproveita o pedido e pede outra geração (o builder faz uma por vez, na ordem).
 *
 * Ciclo: na_fila, gerando (o builder escreveu `iniciado`), no_ar ou falhou (o `resultado.json`, ou 30 minutos sem
 * resposta). `pedidaPor` nulo = o agendador ou o console.
 */
#[SemIsolamentoDeTenant('Blog público da BlueJus: conteúdo global, só o Master publica')]
#[ORM\Entity(repositoryClass: PublicacaoDosBlogsRepository::class)]
#[ORM\Table(name: 'publicacao_blogs')]
#[ORM\Index(name: 'idx_publicacao_blogs_situacao', columns: ['situacao'])]
#[ORM\Index(name: 'idx_publicacao_blogs_pedida_por', columns: ['pedida_por_id'])]
class PublicacaoDosBlogs implements Auditavel
{
    /** Quantas linhas do log do builder ficam guardadas (as últimas). */
    public const LINHAS_DO_LOG = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20, enumType: SituacaoDaPublicacao::class)]
    private SituacaoDaPublicacao $situacao = SituacaoDaPublicacao::NaFila;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $iniciadaEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $terminadaEm = null;

    /** Hash do commit do repositório dos sites usado na geração. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $commitDosSites = null;

    /** As últimas {@see self::LINHAS_DO_LOG} linhas do builder, ou o motivo da falha. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $log = null;

    /** @var array<string, list<string>>|null pasta do blog => slugs que entraram; nulo = montagem não começou; [] = montando */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $artigos = null;

    public function __construct(
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $pedidaEm,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'pedida_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $pedidaPor,
        #[ORM\Column(type: 'string', length: 20, enumType: MotivoDaPublicacao::class)]
        private MotivoDaPublicacao $motivo,
    ) {
    }

    /** O worker vai ler os artigos: daqui em diante um Publicar novo não reaproveita este pedido. */
    public function marcarEmMontagem(): void
    {
        $this->exigirSituacao([SituacaoDaPublicacao::NaFila], 'Só um pedido na fila é montado.');
        $this->artigos = [];
    }

    /** @param array<string, list<string>> $artigos pasta do blog => slugs que entraram no pacote */
    public function registrarPacote(array $artigos): void
    {
        $this->exigirSituacao([SituacaoDaPublicacao::NaFila, SituacaoDaPublicacao::Gerando], 'Só um pedido aberto recebe o pacote.');
        $this->artigos = $artigos;
    }

    /** O builder começou (escreveu `iniciado`). Repetir não muda nada. */
    public function marcarGerando(\DateTimeImmutable $iniciadaEm): void
    {
        $this->exigirSituacao([SituacaoDaPublicacao::NaFila, SituacaoDaPublicacao::Gerando], 'Só um pedido aberto pode estar gerando.');
        $this->situacao = SituacaoDaPublicacao::Gerando;
        $this->iniciadaEm ??= $iniciadaEm;
    }

    /** O builder terminou (escreveu `resultado.json`). */
    public function concluir(bool $noAr, ?string $commit, string $log, ?\DateTimeImmutable $iniciadaEm, \DateTimeImmutable $terminadaEm): void
    {
        $this->exigirSituacao([SituacaoDaPublicacao::NaFila, SituacaoDaPublicacao::Gerando], 'Este pedido já terminou.');
        $this->situacao = $noAr ? SituacaoDaPublicacao::NoAr : SituacaoDaPublicacao::Falhou;
        $this->commitDosSites = self::vazioViraNulo($commit === null ? null : mb_substr($commit, 0, 64));
        $this->log = self::ultimasLinhas($log);
        $this->iniciadaEm = $iniciadaEm ?? $this->iniciadaEm;
        $this->terminadaEm = $terminadaEm;
    }

    /** Falhou sem resultado do builder: o pacote não foi montado, ou o builder não respondeu a tempo. */
    public function falhar(string $motivo, \DateTimeImmutable $em): void
    {
        $this->exigirSituacao([SituacaoDaPublicacao::NaFila, SituacaoDaPublicacao::Gerando], 'Este pedido já terminou.');
        $this->situacao = SituacaoDaPublicacao::Falhou;
        $this->log = self::ultimasLinhas($motivo);
        $this->terminadaEm = $em;
    }

    public function estaAberta(): bool
    {
        return $this->situacao->estaAberta();
    }

    /** A montagem começou (ou terminou): o pedido já não leva mudanças novas. */
    public function montagemIniciada(): bool
    {
        return $this->artigos !== null;
    }

    /** O pacote está montado: os slugs que entraram já foram guardados. */
    public function pacoteMontado(): bool
    {
        return $this->artigos !== null && $this->artigos !== [];
    }

    /** @param list<SituacaoDaPublicacao> $permitidas */
    private function exigirSituacao(array $permitidas, string $mensagem): void
    {
        if (!\in_array($this->situacao, $permitidas, true)) {
            throw new \DomainException($mensagem);
        }
    }

    private static function ultimasLinhas(string $texto): string
    {
        $linhas = explode("\n", rtrim($texto, "\n"));

        return implode("\n", \array_slice($linhas, -self::LINHAS_DO_LOG));
    }

    private static function vazioViraNulo(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }

    // ---- leitura ----

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPedidaEm(): \DateTimeImmutable
    {
        return $this->pedidaEm;
    }

    public function getPedidaPor(): ?User
    {
        return $this->pedidaPor;
    }

    public function getMotivo(): MotivoDaPublicacao
    {
        return $this->motivo;
    }

    public function getSituacao(): SituacaoDaPublicacao
    {
        return $this->situacao;
    }

    public function getIniciadaEm(): ?\DateTimeImmutable
    {
        return $this->iniciadaEm;
    }

    public function getTerminadaEm(): ?\DateTimeImmutable
    {
        return $this->terminadaEm;
    }

    public function getCommitDosSites(): ?string
    {
        return $this->commitDosSites;
    }

    public function getLog(): ?string
    {
        return $this->log;
    }

    /** @return array<string, list<string>>|null */
    public function getArtigos(): ?array
    {
        return $this->artigos;
    }
}
