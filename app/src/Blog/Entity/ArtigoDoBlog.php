<?php

declare(strict_types=1);

namespace App\Blog\Entity;

use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Entity\Auth\User;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Artigo do Blog BlueJus e do Blog BlueJus Grátis (spec painel-artigos-blogs, seção 1). O banco é a fonte dos artigos;
 * o build dos sites recebe o `.md` gerado pelo {@see \App\Blog\Service\ExportadorDeArtigo}.
 *
 * GLOBAL, sem tenant, de propósito: conteúdo da plataforma, escrito só pelo Master, como a {@see \App\Master\Entity\ConfiguracaoGlobal}.
 * Auditável: o AuditLogSubscriber grava cada mudança (o UseCase abre o escopo de plataforma).
 *
 * `corpoHtml` é o que o editor guarda (já sanitizado); `corpoMarkdown` é gerado dele a cada salvar e nunca é editado à mão.
 *
 * Ciclo: rascunho, agendado, publicado, despublicado. Salvar um artigo publicado (ou despublicado) guarda o texto e
 * marca "alterações não publicadas" (D7), até o próximo {@see publicar()}.
 */
#[ORM\Entity(repositoryClass: ArtigoDoBlogRepository::class)]
#[ORM\Table(name: 'artigo_blog')]
#[ORM\UniqueConstraint(name: 'uniq_artigo_blog_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_artigo_blog_situacao', columns: ['situacao'])]
#[ORM\Index(name: 'idx_artigo_blog_criado_por', columns: ['criado_por_id'])]
#[ORM\Index(name: 'idx_artigo_blog_alterado_por', columns: ['alterado_por_id'])]
class ArtigoDoBlog implements Auditavel
{
    public const REGEX_SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $titulo = '';

    #[ORM\Column(type: 'string', length: 255)]
    private string $tituloCurto = '';

    #[ORM\Column(type: 'string', length: 60)]
    private string $tituloSeo = '';

    #[ORM\Column(type: 'string', length: 160)]
    private string $descricao = '';

    #[ORM\Column(type: 'text')]
    private string $linhaFina = '';

    #[ORM\Column(type: 'text')]
    private string $resumo = '';

    #[ORM\Column(type: 'string', length: 60)]
    private string $categoria = '';

    #[ORM\Column(type: 'string', length: 60)]
    private string $autor = '';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $revisorNome = null;

    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $revisorOab = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $leitura = '';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imagemChave = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imagemAlt = null;

    /** @var list<array{texto: string, url: string, rotulo: string}> */
    #[ORM\Column(type: 'json')]
    private array $referencias = [];

    /** @var list<string> slugs de outros artigos */
    #[ORM\Column(type: 'json')]
    private array $relacionados = [];

    #[ORM\Column(type: 'boolean')]
    private bool $destaque = false;

    #[ORM\Column(type: 'text')]
    private string $corpoHtml = '';

    #[ORM\Column(type: 'text')]
    private string $corpoMarkdown = '';

    #[ORM\Column(type: 'string', length: 20, enumType: SituacaoDoArtigo::class)]
    private SituacaoDoArtigo $situacao = SituacaoDoArtigo::Rascunho;

    /** Data de publicação ou de agendamento (horário de Brasília); vira `publicadoEm` ao publicar. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publicarEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publicadoEm = null;

    /** Só muda quando um artigo já publicado vai ao ar de novo com texto novo. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    /** Texto guardado depois da última publicação e ainda fora do site (D7). */
    #[ORM\Column(type: 'boolean')]
    private bool $alteracoesPendentes = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct(
        #[ORM\Column(type: 'string', length: 120)]
        private string $slug,
        #[ORM\Column(type: 'string', length: 10, enumType: BlogsDoArtigo::class)]
        private BlogsDoArtigo $blogs,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
        $this->exigirSlugValido($slug);
        $this->alteradoPor = $criadoPor;
        $this->alteradoEm = $criadoEm;
    }

    // ---- edição do conteúdo ----

    public function trocarSlug(string $slug): void
    {
        $this->exigirSlugValido($slug);
        $this->slug = $slug;
    }

    public function definirBlogs(BlogsDoArtigo $blogs): void
    {
        $this->blogs = $blogs;
    }

    public function editarTextos(string $titulo, string $tituloCurto, string $tituloSeo, string $descricao, string $linhaFina, string $resumo): void
    {
        $this->titulo = trim($titulo);
        $this->tituloCurto = trim($tituloCurto);
        $this->tituloSeo = trim($tituloSeo);
        $this->descricao = trim($descricao);
        $this->linhaFina = trim($linhaFina);
        $this->resumo = trim($resumo);
    }

    public function classificar(string $categoria, string $autor, ?string $revisorNome, ?string $revisorOab, string $leitura, bool $destaque): void
    {
        $this->categoria = trim($categoria);
        $this->autor = trim($autor);
        $this->revisorNome = self::vazioViraNulo($revisorNome);
        $this->revisorOab = self::vazioViraNulo($revisorOab);
        $this->leitura = trim($leitura);
        $this->destaque = $destaque;
    }

    public function definirImagem(?string $chave, ?string $alt): void
    {
        $this->imagemChave = self::vazioViraNulo($chave);
        $this->imagemAlt = self::vazioViraNulo($alt);
    }

    /** @param list<array{texto: string, url: string, rotulo: string}> $referencias */
    public function definirReferencias(array $referencias): void
    {
        $this->referencias = $referencias;
    }

    /** @param list<string> $relacionados */
    public function definirRelacionados(array $relacionados): void
    {
        $this->relacionados = array_values(array_unique($relacionados));
    }

    /** O HTML já chega sanitizado e o Markdown gerado dele (HtmlDoArtigoParaMarkdown). */
    public function definirCorpo(string $corpoHtml, string $corpoMarkdown): void
    {
        $this->corpoHtml = $corpoHtml;
        $this->corpoMarkdown = $corpoMarkdown;
    }

    /**
     * Fecha uma edição que mudou o conteúdo. No publicado e no despublicado o site não muda: fica a marca de
     * alterações não publicadas (D7).
     */
    public function registrarAlteracao(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
        if ($this->jaFoiAoAr()) {
            $this->alteracoesPendentes = true;
        }
    }

    /** Impressão digital do que sai no `.md`: o UseCase compara antes e depois para saber se o texto mudou. */
    public function assinaturaDoConteudo(): string
    {
        return hash('sha256', serialize([
            $this->slug, $this->blogs->value, $this->titulo, $this->tituloCurto, $this->tituloSeo, $this->descricao,
            $this->linhaFina, $this->resumo, $this->categoria, $this->autor, $this->revisorNome, $this->revisorOab,
            $this->leitura, $this->imagemChave, $this->imagemAlt, $this->referencias, $this->relacionados,
            $this->destaque, $this->corpoMarkdown,
        ]));
    }

    // ---- ciclo de publicação ----

    public function agendar(\DateTimeImmutable $publicarEm, \DateTimeImmutable $agora): void
    {
        $this->exigirSituacao([SituacaoDoArtigo::Rascunho, SituacaoDoArtigo::Agendado], 'Só um rascunho ou um artigo agendado pode ser agendado.');
        if ($publicarEm <= $agora) {
            throw new \DomainException('A data do agendamento precisa estar no futuro.');
        }

        $this->publicarEm = $publicarEm;
        $this->situacao = SituacaoDoArtigo::Agendado;
    }

    public function cancelarAgendamento(): void
    {
        $this->exigirSituacao([SituacaoDoArtigo::Agendado], 'Só um artigo agendado pode ter o agendamento cancelado.');
        $this->situacao = SituacaoDoArtigo::Rascunho;
    }

    /**
     * Rascunho ou agendado: vai ao ar com a data marcada, se ela já passou, ou com a data de agora.
     * Publicado com alterações ou despublicado: volta ao ar mantendo a data original; texto novo muda `atualizadoEm`.
     */
    public function publicar(\DateTimeImmutable $agora): void
    {
        if ($this->jaFoiAoAr()) {
            $this->republicar($agora);

            return;
        }

        $this->exigirSituacao([SituacaoDoArtigo::Rascunho, SituacaoDoArtigo::Agendado], 'Este artigo não pode ser publicado.');
        $this->publicadoEm = ($this->publicarEm !== null && $this->publicarEm <= $agora) ? $this->publicarEm : $agora;
        $this->publicarEm = $this->publicadoEm;
        $this->situacao = SituacaoDoArtigo::Publicado;
        $this->alteracoesPendentes = false;
    }

    public function despublicar(): void
    {
        $this->exigirSituacao([SituacaoDoArtigo::Publicado], 'Só um artigo publicado pode ser despublicado.');
        $this->situacao = SituacaoDoArtigo::Despublicado;
    }

    /** Publicado pede despublicar antes de excluir (o link do site cairia sem aviso). */
    public function exigirExcluivel(): void
    {
        if (SituacaoDoArtigo::Publicado === $this->situacao) {
            throw new \DomainException('Despublique o artigo antes de excluir.');
        }
    }

    private function republicar(\DateTimeImmutable $agora): void
    {
        if (SituacaoDoArtigo::Publicado === $this->situacao && !$this->alteracoesPendentes) {
            throw new \DomainException('O artigo já está publicado e não tem alterações a publicar.');
        }

        if ($this->alteracoesPendentes) {
            $this->atualizadoEm = $agora;
        }
        $this->situacao = SituacaoDoArtigo::Publicado;
        $this->alteracoesPendentes = false;
    }

    private function jaFoiAoAr(): bool
    {
        return \in_array($this->situacao, [SituacaoDoArtigo::Publicado, SituacaoDoArtigo::Despublicado], true);
    }

    /** @param list<SituacaoDoArtigo> $permitidas */
    private function exigirSituacao(array $permitidas, string $mensagem): void
    {
        if (!\in_array($this->situacao, $permitidas, true)) {
            throw new \DomainException($mensagem);
        }
    }

    private function exigirSlugValido(string $slug): void
    {
        if (\strlen($slug) > 120 || preg_match(self::REGEX_SLUG, $slug) !== 1) {
            throw new \DomainException('Slug inválido: use letras minúsculas sem acento e números, separados por hífen.');
        }
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getBlogs(): BlogsDoArtigo
    {
        return $this->blogs;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getTituloCurto(): string
    {
        return $this->tituloCurto;
    }

    public function getTituloSeo(): string
    {
        return $this->tituloSeo;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function getLinhaFina(): string
    {
        return $this->linhaFina;
    }

    public function getResumo(): string
    {
        return $this->resumo;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function getAutor(): string
    {
        return $this->autor;
    }

    public function getRevisorNome(): ?string
    {
        return $this->revisorNome;
    }

    public function getRevisorOab(): ?string
    {
        return $this->revisorOab;
    }

    public function getLeitura(): string
    {
        return $this->leitura;
    }

    public function getImagemChave(): ?string
    {
        return $this->imagemChave;
    }

    public function getImagemAlt(): ?string
    {
        return $this->imagemAlt;
    }

    /** @return list<array{texto: string, url: string, rotulo: string}> */
    public function getReferencias(): array
    {
        return $this->referencias;
    }

    /** @return list<string> */
    public function getRelacionados(): array
    {
        return $this->relacionados;
    }

    public function isDestaque(): bool
    {
        return $this->destaque;
    }

    public function getCorpoHtml(): string
    {
        return $this->corpoHtml;
    }

    public function getCorpoMarkdown(): string
    {
        return $this->corpoMarkdown;
    }

    public function getSituacao(): SituacaoDoArtigo
    {
        return $this->situacao;
    }

    public function getPublicarEm(): ?\DateTimeImmutable
    {
        return $this->publicarEm;
    }

    public function getPublicadoEm(): ?\DateTimeImmutable
    {
        return $this->publicadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function temAlteracoesPendentes(): bool
    {
        return $this->alteracoesPendentes;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }
}
