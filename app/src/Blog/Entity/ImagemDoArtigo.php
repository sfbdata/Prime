<?php

declare(strict_types=1);

namespace App\Blog\Entity;

use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma imagem enviada ao corpo ou à capa de um {@see ArtigoDoBlog} (spec painel-artigos-blogs, seções 1 e 4).
 * O arquivo mora no armazenamento pela `chave`; o envio e a entrega são da fatia P3.
 *
 * GLOBAL, sem tenant: pertence ao artigo, que é conteúdo da plataforma. Some junto com o artigo (ON DELETE CASCADE).
 * No Markdown exportado ela vira `./imagens/{id}.{extensao}`, e o builder copia o arquivo para ao lado do artigo.
 */
#[SemIsolamentoDeTenant('Blog público da BlueJus: conteúdo global, só o Master edita')]
#[ORM\Entity(repositoryClass: ImagemDoArtigoRepository::class)]
#[ORM\Table(name: 'artigo_blog_imagem')]
#[ORM\Index(name: 'idx_artigo_blog_imagem_artigo', columns: ['artigo_id'])]
class ImagemDoArtigo implements Auditavel
{
    /** Formatos aceitos no envio (seção 4) e a extensão de cada um. */
    public const EXTENSOES_POR_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $alt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ArtigoDoBlog::class)]
        #[ORM\JoinColumn(name: 'artigo_id', nullable: false, onDelete: 'CASCADE')]
        private ArtigoDoBlog $artigo,
        #[ORM\Column(type: 'string', length: 255)]
        private string $chave,
        #[ORM\Column(type: 'string', length: 255)]
        private string $nomeOriginal,
        #[ORM\Column(type: 'string', length: 50)]
        private string $mime,
        #[ORM\Column(type: 'integer')]
        private int $larguraPx,
        #[ORM\Column(type: 'integer')]
        private int $alturaPx,
        #[ORM\Column(type: 'integer')]
        private int $tamanhoBytes,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
    ) {
        if (!isset(self::EXTENSOES_POR_MIME[$mime])) {
            throw new \DomainException('A imagem do artigo precisa ser JPG, PNG ou WEBP.');
        }
    }

    public function descrever(?string $alt): void
    {
        $alt = $alt === null ? null : trim($alt);
        $this->alt = $alt === '' ? null : $alt;
    }

    /** Extensão do arquivo no build (`./imagens/{id}.{extensao}`). */
    public function extensao(): string
    {
        return self::EXTENSOES_POR_MIME[$this->mime];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArtigo(): ArtigoDoBlog
    {
        return $this->artigo;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getNomeOriginal(): string
    {
        return $this->nomeOriginal;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getLarguraPx(): int
    {
        return $this->larguraPx;
    }

    public function getAlturaPx(): int
    {
        return $this->alturaPx;
    }

    public function getTamanhoBytes(): int
    {
        return $this->tamanhoBytes;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }
}
