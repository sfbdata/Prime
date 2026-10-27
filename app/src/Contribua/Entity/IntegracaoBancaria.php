<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Integração bancária da BlueJus (desenho 08, só o Master): uma linha global. O certificado e a chave
 * privada ficam CIFRADOS (CofreDeCertificado; a chave de cifra mora no ambiente) e nunca voltam à tela: a
 * tela mostra os nomes dos arquivos e se estão presentes. Fora da auditoria (técnica, com segredo).
 */
#[ORM\Entity(repositoryClass: IntegracaoBancariaRepository::class)]
#[ORM\Table(name: 'contribua_integracao_bancaria')]
class IntegracaoBancaria
{
    public const ESTADO_DESLIGADA = 'off';
    public const ESTADO_AGUARDANDO = 'pend';
    public const ESTADO_CONECTADA = 'ok';
    public const AMBIENTES = ['sandbox', 'producao'];
    /** Chaves do desenho (L249): cobrança por conta, conciliação, boleto, relatório. */
    public const RECURSOS_PADRAO = ['cob' => true, 'conc' => true, 'bol' => false, 'rel' => true, 'te' => false, 'te_trava' => false];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $modalidade = 'direta';

    #[ORM\Column(length: 20)]
    private string $banco = 'cora';

    #[ORM\Column(length: 20)]
    private string $ambiente = 'sandbox';

    #[ORM\Column(name: 'client_id', length: 255, nullable: true)]
    private ?string $clientId = null;

    #[ORM\Column(name: 'certificado_cifrado', type: 'text', nullable: true)]
    private ?string $certificadoCifrado = null;

    #[ORM\Column(name: 'chave_cifrada', type: 'text', nullable: true)]
    private ?string $chaveCifrada = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $arquivos = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $recursos = self::RECURSOS_PADRAO;

    #[ORM\Column(length: 10)]
    private string $estado = self::ESTADO_DESLIGADA;

    #[ORM\Column(name: 'testada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $testadaEm = null;

    #[ORM\Column(name: 'resultado_do_teste', length: 255, nullable: true)]
    private ?string $resultadoDoTeste = null;

    #[ORM\Column(name: 'atualizada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'atualizada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $atualizadaPor = null;

    /** @param array<string, bool> $recursos */
    public function configurar(string $ambiente, string $clientId, array $recursos, User $quem): void
    {
        if (!in_array($ambiente, self::AMBIENTES, true)) {
            throw new \DomainException('Ambiente inválido.');
        }
        $this->ambiente = $ambiente;
        $this->clientId = mb_substr(trim($clientId), 0, 255);
        $this->recursos = array_merge(self::RECURSOS_PADRAO, array_intersect_key(array_map('boolval', $recursos), self::RECURSOS_PADRAO));
        $this->estado = $this->temCertificado() ? self::ESTADO_AGUARDANDO : self::ESTADO_DESLIGADA;
        $this->tocar($quem);
    }

    /** @param list<string> $nomes */
    public function guardarCertificado(string $certificadoCifrado, string $chaveCifrada, array $nomes, User $quem): void
    {
        $this->certificadoCifrado = $certificadoCifrado;
        $this->chaveCifrada = $chaveCifrada;
        $this->arquivos = array_map(static fn (string $n): string => mb_substr($n, 0, 120), $nomes);
        $this->estado = self::ESTADO_AGUARDANDO;
        $this->tocar($quem);
    }

    public function registrarTeste(bool $conectou, string $mensagem, \DateTimeImmutable $quando): void
    {
        $this->estado = $conectou ? self::ESTADO_CONECTADA : self::ESTADO_DESLIGADA;
        $this->testadaEm = $quando;
        $this->resultadoDoTeste = mb_substr($mensagem, 0, 255);
    }

    /** Vale para cobrar: conectada (testada com sucesso) e com a cobrança por conta ligada. */
    public function cobraPorConta(): bool
    {
        return self::ESTADO_CONECTADA === $this->estado && ($this->recursos['cob'] ?? false) && $this->temCertificado();
    }

    /** Gateway M1: o QR do Trabalho Externo vira cobrança dinâmica. Vale só com a integração conectada e a chave `te` ligada. */
    public function cobraTrabalhoExterno(): bool
    {
        return $this->cobraPorConta() && ($this->recursos['te'] ?? false);
    }

    public function temCertificado(): bool
    {
        return null !== $this->certificadoCifrado && null !== $this->chaveCifrada;
    }

    private function tocar(User $quem): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
        $this->atualizadaPor = $quem;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModalidade(): string
    {
        return $this->modalidade;
    }

    public function getBanco(): string
    {
        return $this->banco;
    }

    public function getAmbiente(): string
    {
        return $this->ambiente;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getCertificadoCifrado(): ?string
    {
        return $this->certificadoCifrado;
    }

    public function getChaveCifrada(): ?string
    {
        return $this->chaveCifrada;
    }

    /** @return list<string> */
    public function getArquivos(): array
    {
        return $this->arquivos;
    }

    /** @return array<string, bool> */
    public function getRecursos(): array
    {
        return $this->recursos;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function getTestadaEm(): ?\DateTimeImmutable
    {
        return $this->testadaEm;
    }

    public function getResultadoDoTeste(): ?string
    {
        return $this->resultadoDoTeste;
    }
}
