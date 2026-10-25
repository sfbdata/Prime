<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\DocumentoJuridico;
use App\Inteligencia\Repository\VersaoDeDocumentoJuridicoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma versão ajustada de um documento jurídico, enviada pelo Master no bloco "Questões jurídicas": qual documento, a
 * partir de qual questão (opcional), o nome original, o arquivo guardado (nome cunhado pelo storage, categoria
 * `inteligencia_documento_juridico`, escopo global), quem enviou e quando. Só é criada: enviar de novo é outra versão.
 *
 * PLATAFORMA, sem tenant: os documentos são do BlueJus. O nome de quem enviou fica gravado (o usuário pode ser apagado
 * depois e a FK vira NULL).
 *
 * Auditável: o AuditLogSubscriber grava o envio, no escopo de plataforma.
 */
#[ORM\Entity(repositoryClass: VersaoDeDocumentoJuridicoRepository::class)]
#[ORM\Table(name: 'inteligencia_documento_juridico_versao')]
#[ORM\Index(name: 'idx_inteligencia_documento_juridico_versao_documento', columns: ['documento', 'enviada_em'])]
#[ORM\Index(name: 'idx_inteligencia_documento_juridico_versao_por', columns: ['enviada_por_id'])]
class VersaoDeDocumentoJuridico implements Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $enviadaPorNome;

    public function __construct(
        #[ORM\Column(length: 40, enumType: DocumentoJuridico::class)]
        private DocumentoJuridico $documento,
        #[ORM\Column(length: 80, nullable: true)]
        private ?string $questao,
        #[ORM\Column(length: 255)]
        private string $nomeOriginal,
        #[ORM\Column(length: 100)]
        private string $nomeArquivo,
        #[ORM\Column(length: 150)]
        private string $mimeType,
        #[ORM\Column(type: 'integer')]
        private int $tamanhoBytes,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'enviada_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $enviadaPor,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $enviadaEm,
    ) {
        $this->enviadaPorNome = MudancaDeStatusDaQuestaoJuridica::nomeDe($enviadaPor);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocumento(): DocumentoJuridico
    {
        return $this->documento;
    }

    public function getQuestao(): ?string
    {
        return $this->questao;
    }

    public function getNomeOriginal(): string
    {
        return $this->nomeOriginal;
    }

    public function getNomeArquivo(): string
    {
        return $this->nomeArquivo;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getTamanhoBytes(): int
    {
        return $this->tamanhoBytes;
    }

    public function getEnviadaPor(): ?User
    {
        return $this->enviadaPor;
    }

    public function getEnviadaPorNome(): string
    {
        return $this->enviadaPorNome;
    }

    public function getEnviadaEm(): \DateTimeImmutable
    {
        return $this->enviadaEm;
    }
}
