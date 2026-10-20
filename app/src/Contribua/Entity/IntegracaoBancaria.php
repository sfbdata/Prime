<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Integração bancária da BlueJus (desenho 08, só o Master): uma linha global. O certificado, a chave
 * privada, o Client Secret e a app key ficam CIFRADOS (CofreDeCertificado; a chave de cifra mora no
 * ambiente) e nunca voltam à tela: a tela mostra os nomes dos arquivos e se cada segredo está definido.
 * Fora da auditoria (técnica, com segredo). O banco é escolhido no seletor (D5 da C4b); os campos extras
 * (D4) só valem para os bancos da API Pix do BACEN.
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
    public const RECURSOS_PADRAO = ['cob' => true, 'conc' => true, 'bol' => false, 'rel' => true];
    /** Tamanho máximo de uma chave PIX (a aleatória tem 36; o e-mail vai até 77). */
    public const TAMANHO_CHAVE_PIX = 77;

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

    /** Client Secret do OAuth2 (Inter, BB, Outro), cifrado. */
    #[ORM\Column(name: 'client_secret_cifrado', type: 'text', nullable: true)]
    private ?string $clientSecretCifrado = null;

    /** A chave PIX da conta que recebe (vai em toda cobrança da API Pix do BACEN). Não é segredo. */
    #[ORM\Column(name: 'chave_pix', length: 77, nullable: true)]
    private ?string $chavePix = null;

    /** App key do Portal do Desenvolvedor do BB, cifrada. */
    #[ORM\Column(name: 'app_key_cifrada', type: 'text', nullable: true)]
    private ?string $appKeyCifrada = null;

    /** "Outro": a URL base da API Pix do PSP (sem a barra final). */
    #[ORM\Column(name: 'url_base', length: 255, nullable: true)]
    private ?string $urlBase = null;

    /** "Outro": a URL do token OAuth2 do PSP. */
    #[ORM\Column(name: 'url_token', length: 255, nullable: true)]
    private ?string $urlToken = null;

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

    /**
     * O banco do seletor (D5). Quem chama confere que ele existe em BancosPix; aqui só o formato. Trocar
     * de banco desfaz a conexão: o teste valia para o banco anterior.
     */
    public function escolherBanco(string $codigo): void
    {
        if (1 !== preg_match('/^[a-z]{2,20}$/', $codigo)) {
            throw new \DomainException('Banco inválido.');
        }
        if ($codigo !== $this->banco && self::ESTADO_CONECTADA === $this->estado) {
            $this->estado = self::ESTADO_AGUARDANDO;
        }
        $this->banco = $codigo;
    }

    /** Chave PIX e, no "Outro", as URLs do PSP. Confere tudo antes de gravar (nada muda se uma falhar). */
    public function definirEnderecos(string $chavePix, string $urlBase, string $urlToken): void
    {
        $chavePix = trim($chavePix);
        if (mb_strlen($chavePix) > self::TAMANHO_CHAVE_PIX) {
            throw new \DomainException('A chave PIX tem no máximo 77 caracteres.');
        }
        $urlBase = rtrim(self::url($urlBase, 'URL base da API Pix'), '/');
        $urlToken = self::url($urlToken, 'URL do token');
        $this->chavePix = '' === $chavePix ? null : $chavePix;
        $this->urlBase = '' === $urlBase ? null : $urlBase;
        $this->urlToken = '' === $urlToken ? null : $urlToken;
    }

    /** Segredos já cifrados; `null` mantém o que estava (campo deixado em branco na tela). */
    public function guardarSegredos(?string $clientSecretCifrado, ?string $appKeyCifrada): void
    {
        $this->clientSecretCifrado = $clientSecretCifrado ?? $this->clientSecretCifrado;
        $this->appKeyCifrada = $appKeyCifrada ?? $this->appKeyCifrada;
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

    public function temCertificado(): bool
    {
        return null !== $this->certificadoCifrado && null !== $this->chaveCifrada;
    }

    public function temClientSecret(): bool
    {
        return null !== $this->clientSecretCifrado;
    }

    public function temAppKey(): bool
    {
        return null !== $this->appKeyCifrada;
    }

    /** Vazia ou `https://` com host (a URL vai para o servidor chamar: nada de http, arquivo ou outro esquema). */
    private static function url(string $url, string $rotulo): string
    {
        $url = trim($url);
        if ('' === $url) {
            return '';
        }
        $host = parse_url($url, \PHP_URL_HOST);
        if (mb_strlen($url) > 255 || !str_starts_with(strtolower($url), 'https://') || !is_string($host) || '' === $host || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new \DomainException('A ' . $rotulo . ' precisa ser um endereço https:// completo.');
        }

        return $url;
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

    public function getClientSecretCifrado(): ?string
    {
        return $this->clientSecretCifrado;
    }

    public function getChavePix(): ?string
    {
        return $this->chavePix;
    }

    public function getAppKeyCifrada(): ?string
    {
        return $this->appKeyCifrada;
    }

    public function getUrlBase(): ?string
    {
        return $this->urlBase;
    }

    public function getUrlToken(): ?string
    {
        return $this->urlToken;
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
