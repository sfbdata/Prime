<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Entity;

use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma versão guardada do PDF de um documento (contrato da etapa 5, seção 2, itens 1 e 2): o original que já chegou com
 * assinaturas digitais, o PDF para assinar congelado na primeira geração (E5-1) ou o PDF que um signatário gov.br ou
 * ICP devolveu assinado. O arquivo é guardado byte a byte e nunca muda: a versão só se cria, e a seguinte tem de
 * começar com a anterior. A única que sai é o `original` de documento em rascunho ({@see exigirRemovivel()}): o rascunho
 * troca o PDF, e o arquivo dela sai depois do COMMIT.
 *
 * Cada versão tem arquivo próprio, gravado por
 * {@see \App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica::novaVersao()}: nunca reaproveita a chave
 * do PDF do documento (`arquivo_enviado_chave`), para a troca do PDF no rascunho, a exclusão e o espelho tratarem cada
 * arquivo uma vez só.
 *
 * `ordem` conta de 1 para cima dentro do documento (único por documento): a última versão é a de maior ordem, e quem
 * cria a seguinte decide com a linha do documento travada. No máximo um `original` e um `para_assinar` por documento
 * (índices únicos parciais). `analise` é o resultado do motor de conferência no JSON
 * versionado da {@see \App\AssinaturaEletronica\Service\SerializacaoDaConferencia} (`{"v":1,...}`).
 *
 * Etapa 6 (E6-7): `cadeia` guarda o JSON do {@see \App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia}, com a
 * situação (confirmada ou recusada) e a hora da conferência, gravada uma vez. Validador indisponível não grava nada
 * (E6-4): o envio do PDF assinado é recusado e o signatário tenta de novo.
 *
 * Sem `tenant_id`: o escritório é o do documento (CASCADE). O signatário que devolveu vira nulo se a linha dele sair
 * (`ON DELETE SET NULL`), e a versão continua como prova.
 */
#[ORM\Entity(repositoryClass: VersaoDoPdfRepository::class)]
#[ORM\Table(name: 'assinatura_eletronica_versao')]
#[ORM\UniqueConstraint(name: 'uniq_assinatura_eletronica_versao_ordem', columns: ['documento_id', 'ordem'])]
#[ORM\UniqueConstraint(name: 'uniq_assinatura_eletronica_versao_original', columns: ['documento_id'], options: ['where' => "((origem)::text = 'original'::text)"])]
#[ORM\UniqueConstraint(name: 'uniq_assinatura_eletronica_versao_para_assinar', columns: ['documento_id'], options: ['where' => "((origem)::text = 'para_assinar'::text)"])]
#[SemIsolamentoDeTenant('Filho do documento (TenantAware, CASCADE): só é lido a partir do documento já filtrado pelo escritório ou, na tela pública, pelo token do signatário')]
class VersaoDoPdf
{
    public const ORDEM_MAXIMA = 32767;
    public const NOME_MAXIMO = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentoParaAssinar::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentoParaAssinar $documento;

    #[ORM\ManyToOne(targetEntity: Signatario::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Signatario $signatario;

    #[ORM\Column(type: 'smallint')]
    private int $ordem;

    #[ORM\Column(length: 20, enumType: OrigemDaVersao::class)]
    private OrigemDaVersao $origem;

    #[ORM\Column(name: 'arquivo_chave', length: 255)]
    private string $arquivoChave;

    #[ORM\Column(name: 'nome_original', length: 255)]
    private string $nomeOriginal;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sha256;

    #[ORM\Column(type: 'integer')]
    private int $tamanho;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $analise;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $cadeia = null;

    #[ORM\Column(name: 'cadeia_situacao', length: 20, nullable: true, enumType: SituacaoDaCadeia::class)]
    private ?SituacaoDaCadeia $cadeiaSituacao = null;

    #[ORM\Column(name: 'cadeia_conferida_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cadeiaConferidaEm = null;

    /** @param array<string, mixed> $analise */
    private function __construct(
        DocumentoParaAssinar $documento,
        ?Signatario $signatario,
        int $ordem,
        OrigemDaVersao $origem,
        string $arquivoChave,
        string $nomeOriginal,
        string $sha256,
        int $tamanho,
        array $analise,
        \DateTimeImmutable $agora,
    ) {
        self::exigirArquivo($ordem, $arquivoChave, $nomeOriginal, $sha256, $tamanho);
        self::exigirAnalise($analise);
        $this->documento = $documento;
        $this->signatario = $signatario;
        $this->ordem = $ordem;
        $this->origem = $origem;
        $this->arquivoChave = $arquivoChave;
        $this->nomeOriginal = $nomeOriginal;
        $this->sha256 = $sha256;
        $this->tamanho = $tamanho;
        $this->analise = $analise;
        $this->criadoEm = self::emSegundos($agora);
    }

    /**
     * O PDF que já chegou com assinaturas digitais, guardado ao salvar o rascunho.
     *
     * @param array<string, mixed> $analise
     */
    public static function original(DocumentoParaAssinar $documento, int $ordem, string $arquivoChave, string $nomeOriginal, string $sha256, int $tamanho, array $analise, \DateTimeImmutable $agora): self
    {
        if (!$documento->ehRascunho()) {
            throw new \LogicException('O original só é guardado no rascunho.');
        }

        return new self($documento, null, $ordem, OrigemDaVersao::Original, $arquivoChave, $nomeOriginal, $sha256, $tamanho, $analise, $agora);
    }

    /**
     * O PDF para assinar congelado na primeira geração (E5-1), de documento aguardando assinatura.
     *
     * @param array<string, mixed> $analise
     */
    public static function paraAssinar(DocumentoParaAssinar $documento, int $ordem, string $arquivoChave, string $nomeOriginal, string $sha256, int $tamanho, array $analise, \DateTimeImmutable $agora): self
    {
        self::exigirEnviado($documento);
        if ($documento->getSituacao() !== SituacaoDoDocumento::Aguardando) {
            throw new \LogicException('O PDF para assinar só é gerado com o documento aguardando assinatura.');
        }

        return new self($documento, null, $ordem, OrigemDaVersao::ParaAssinar, $arquivoChave, $nomeOriginal, $sha256, $tamanho, $analise, $agora);
    }

    /**
     * O PDF que o signatário gov.br ou ICP devolveu assinado. O documento é o do signatário.
     *
     * @param array<string, mixed> $analise
     */
    public static function devolvida(Signatario $signatario, int $ordem, string $arquivoChave, string $nomeOriginal, string $sha256, int $tamanho, array $analise, \DateTimeImmutable $agora): self
    {
        if (!$signatario->assinaComCertificado()) {
            throw new \LogicException('Só signatário gov.br ou ICP devolve PDF assinado.');
        }
        $documento = $signatario->getDocumento();
        self::exigirEnviado($documento);

        return new self($documento, $signatario, $ordem, OrigemDaVersao::Devolvida, $arquivoChave, $nomeOriginal, $sha256, $tamanho, $analise, $agora);
    }

    /**
     * O que o validador de cadeia disse (E6-7): confirmada ou recusada, com o JSON da resposta. Uma vez só. Indisponível
     * nunca é gravado (E6-4): quem chama recusa o envio do PDF assinado antes de chegar aqui.
     *
     * @param array<string, mixed> $cadeia o JSON do ResultadoDaCadeia (`{"v":1,...}`)
     */
    public function registrarCadeia(SituacaoDaCadeia $situacao, array $cadeia, \DateTimeImmutable $agora): void
    {
        if ($this->cadeiaSituacao !== null) {
            throw new \LogicException('A cadeia desta versão já foi conferida.');
        }
        if ($situacao === SituacaoDaCadeia::Indisponivel) {
            throw new \LogicException('Cadeia indisponível não é gravada (E6-4).');
        }
        self::exigirAnalise($cadeia);

        $this->cadeia = $cadeia;
        $this->cadeiaSituacao = $situacao;
        $this->cadeiaConferidaEm = self::emSegundos($agora);
    }

    /**
     * Só o `original` de documento em rascunho sai (o rascunho troca o PDF; o 5A apaga o original antigo antes de guardar o
     * novo). As demais versões são prova do envio e só saem com o documento (CASCADE).
     */
    public function exigirRemovivel(): void
    {
        if ($this->origem !== OrigemDaVersao::Original || !$this->documento->ehRascunho()) {
            throw new \LogicException('Só o original de documento em rascunho sai.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocumento(): DocumentoParaAssinar
    {
        return $this->documento;
    }

    public function getSignatario(): ?Signatario
    {
        return $this->signatario;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function getOrigem(): OrigemDaVersao
    {
        return $this->origem;
    }

    public function getArquivoChave(): string
    {
        return $this->arquivoChave;
    }

    public function getNomeOriginal(): string
    {
        return $this->nomeOriginal;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getTamanho(): int
    {
        return $this->tamanho;
    }

    /** @return array<string, mixed> */
    public function getAnalise(): array
    {
        return $this->analise;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    /** @return array<string, mixed>|null */
    public function getCadeia(): ?array
    {
        return $this->cadeia;
    }

    public function getCadeiaSituacao(): ?SituacaoDaCadeia
    {
        return $this->cadeiaSituacao;
    }

    public function getCadeiaConferidaEm(): ?\DateTimeImmutable
    {
        return $this->cadeiaConferidaEm;
    }

    public function cadeiaConfirmada(): bool
    {
        return $this->cadeiaSituacao === SituacaoDaCadeia::Confirmada;
    }

    private static function exigirArquivo(int $ordem, string $arquivoChave, string $nomeOriginal, string $sha256, int $tamanho): void
    {
        if ($ordem < 1 || $ordem > self::ORDEM_MAXIMA) {
            throw new \LogicException('Ordem da versão fora de 1 a 32767.');
        }
        if ($arquivoChave === '' || \strlen($arquivoChave) > 255) {
            throw new \LogicException('Versão sem chave de arquivo ou com chave longa demais.');
        }
        if (trim($nomeOriginal) === '' || mb_strlen($nomeOriginal) > self::NOME_MAXIMO) {
            throw new \LogicException('Versão sem nome do arquivo ou com nome longo demais.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new \LogicException('sha256 da versão fora do formato (64 hexadecimais minúsculos).');
        }
        if ($tamanho < 1) {
            throw new \LogicException('Versão com arquivo vazio.');
        }
    }

    /** @param array<string, mixed> $json */
    private static function exigirAnalise(array $json): void
    {
        if (!\is_int($json['v'] ?? null)) {
            throw new \LogicException('JSON da versão sem o número da versão do formato ("v").');
        }
    }

    private static function exigirEnviado(DocumentoParaAssinar $documento): void
    {
        if ($documento->getHashEnviado() === null) {
            throw new \LogicException('Só documento enviado tem PDF para assinar ou devolvido.');
        }
    }

    /** As colunas guardam segundos: o objeto em memória fica igual ao que volta do banco. */
    private static function emSegundos(\DateTimeImmutable $data): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $data->getTimestamp()))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
