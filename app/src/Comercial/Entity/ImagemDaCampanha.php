<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Comercial\Repository\ImagemDaCampanhaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A imagem da campanha do modo simples (spec `comercial-e-emails.md` §12, F3i; desenho 05 VS 21.10.26 L274, L596 e
 * L659). Plataforma, do Master: sem escritório. O arquivo é o que o servidor regravou (sem EXIF), e sai pelo endereço
 * público `/e/i/{token}.{ext}` enquanto não vence.
 *
 *   - `token`: 128 bits aleatórios em hexadecimal, a única coisa que a mensagem guarda (nunca URL nem `data:`);
 *   - `nome_arquivo`: a chave no storage, cunhada por ele ({@see \App\Comercial\Armazenamento\ChavesDeComercial});
 *   - `expira_em`: 30 dias depois do envio; cada uso numa campanha e cada modelo salvo com ela empurram para 1 ano.
 *
 * NÃO é `Auditavel`: a própria linha diz quem enviou e quando, e as renovações de validade são do worker, sem sessão.
 */
#[ORM\Entity(repositoryClass: ImagemDaCampanhaRepository::class)]
#[ORM\Table(name: 'comercial_imagem')]
#[ORM\Index(name: 'idx_comercial_imagem_expira_em', columns: ['expira_em'])]
class ImagemDaCampanha
{
    public const PADRAO_DO_TOKEN = '/^[a-f0-9]{32}$/';
    /** O tipo gravado e a extensão do endereço público. */
    public const EXTENSOES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    #[ORM\Column(name: 'nome_arquivo', length: 255)]
    private string $nomeArquivo;

    #[ORM\Column(length: 20)]
    private string $mime;

    #[ORM\Column(name: 'tamanho_bytes')]
    private int $tamanhoBytes;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column]
    private int $largura;

    #[ORM\Column]
    private int $altura;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'enviada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $enviadaPor;

    #[ORM\Column(name: 'enviada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $enviadaEm;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiraEm;

    #[ORM\Column(name: 'ultimo_uso_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimoUsoEm = null;

    public function __construct(
        string $nomeArquivo,
        string $mime,
        int $tamanhoBytes,
        string $sha256,
        int $largura,
        int $altura,
        User $enviadaPor,
        \DateTimeImmutable $enviadaEm,
        \DateTimeImmutable $expiraEm,
    ) {
        if (!isset(self::EXTENSOES[$mime])) {
            throw new \InvalidArgumentException('Tipo de imagem não aceito: ' . $mime);
        }
        $this->token = bin2hex(random_bytes(16));
        $this->nomeArquivo = $nomeArquivo;
        $this->mime = $mime;
        $this->tamanhoBytes = $tamanhoBytes;
        $this->sha256 = $sha256;
        $this->largura = $largura;
        $this->altura = $altura;
        $this->enviadaPor = $enviadaPor;
        $this->enviadaEm = $enviadaEm;
        $this->expiraEm = $expiraEm;
    }

    public function vigente(\DateTimeImmutable $agora): bool
    {
        return $this->expiraEm >= $agora;
    }

    public function extensao(): string
    {
        return self::EXTENSOES[$this->mime];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getNomeArquivo(): string
    {
        return $this->nomeArquivo;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getTamanhoBytes(): int
    {
        return $this->tamanhoBytes;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getLargura(): int
    {
        return $this->largura;
    }

    public function getAltura(): int
    {
        return $this->altura;
    }

    public function getEnviadaPor(): ?User
    {
        return $this->enviadaPor;
    }

    public function getEnviadaEm(): \DateTimeImmutable
    {
        return $this->enviadaEm;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getUltimoUsoEm(): ?\DateTimeImmutable
    {
        return $this->ultimoUsoEm;
    }
}
