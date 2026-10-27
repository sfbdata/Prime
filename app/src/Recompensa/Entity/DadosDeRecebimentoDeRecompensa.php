<?php

declare(strict_types=1);

namespace App\Recompensa\Entity;

use App\Entity\Auth\User;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\DadosDeRecebimentoDeRecompensaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Service\DigitoVerificador;
use Doctrine\ORM\Mapping as ORM;

/**
 * Para quem o Master paga a recompensa: nome, CPF ou CNPJ e chave PIX do indicador (spec perfis-1.2-vs-25-10, F4). O
 * sistema grava o valor bruto e não calcula imposto; este cadastro é o que o contador usa. Um por indicador; some junto
 * com ele (CASCADE, exclusão LGPD).
 *
 * GLOBAL, sem tenant. O documento fica só com dígitos e passa pelo dígito verificador; a lista mostra só os 2 últimos.
 */
#[ORM\Entity(repositoryClass: DadosDeRecebimentoDeRecompensaRepository::class)]
#[ORM\Table(name: 'dados_de_recebimento_de_recompensa')]
#[ORM\UniqueConstraint(name: 'uniq_dados_de_recebimento_de_recompensa_indicador', columns: ['indicador_id'])]
#[ORM\Index(name: 'idx_dados_de_recebimento_de_recompensa_atualizado_por', columns: ['atualizado_por_id'])]
class DadosDeRecebimentoDeRecompensa implements Auditavel
{
    public const NOME_MAXIMO = 120;
    public const CHAVE_PIX_MAXIMA = 140;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'indicador_id', nullable: false, onDelete: 'CASCADE')]
    private User $indicador;

    #[ORM\Column(length: 120)]
    private string $nome;

    #[ORM\Column(length: 14)]
    private string $documento;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $chavePix;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'atualizado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $atualizadoPor;

    /** @throws RecompensaRecusada nome vazio ou longo, documento que não é CPF nem CNPJ válido, chave PIX longa */
    public function __construct(User $indicador, string $nome, string $documento, ?string $chavePix, ?User $atualizadoPor, \DateTimeImmutable $em)
    {
        $this->indicador = $indicador;
        $this->aplicar($nome, $documento, $chavePix, $atualizadoPor, $em);
    }

    /** @throws RecompensaRecusada */
    public function atualizar(string $nome, string $documento, ?string $chavePix, ?User $atualizadoPor, \DateTimeImmutable $em): void
    {
        $this->aplicar($nome, $documento, $chavePix, $atualizadoPor, $em);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIndicador(): User
    {
        return $this->indicador;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    /** Só dígitos. */
    public function getDocumento(): string
    {
        return $this->documento;
    }

    public function getChavePix(): ?string
    {
        return $this->chavePix;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    // CPF vira "***.***.***-12" e CNPJ "**.***.***" e "/****-34": só os 2 últimos dígitos aparecem.
    public function documentoMascarado(): string
    {
        $fim = substr($this->documento, -2);

        return strlen($this->documento) === 11 ? '***.***.***-' . $fim : '**.***.***/****-' . $fim;
    }

    /**
     * Confere e normaliza os três campos (nome aparado, documento só com dígitos, chave nula se vazia). O UseCase chama
     * antes de abrir a transação: recusa dentro dela fecharia o EntityManager.
     *
     * @return array{string, string, ?string}
     *
     * @throws RecompensaRecusada
     */
    public static function normalizar(string $nome, string $documento, ?string $chavePix): array
    {
        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > self::NOME_MAXIMO) {
            throw new RecompensaRecusada('Informe o nome de quem recebe (até 120 caracteres).');
        }
        $digitos = DigitoVerificador::digitos($documento);
        if (!DigitoVerificador::cpfValido($digitos) && !DigitoVerificador::cnpjValido($digitos)) {
            throw new RecompensaRecusada('Informe um CPF ou CNPJ válido.');
        }
        $chavePix = $chavePix === null ? null : trim($chavePix);
        if ($chavePix !== null && mb_strlen($chavePix) > self::CHAVE_PIX_MAXIMA) {
            throw new RecompensaRecusada('A chave PIX deve ter até 140 caracteres.');
        }

        return [$nome, $digitos, $chavePix === '' ? null : $chavePix];
    }

    private function aplicar(string $nome, string $documento, ?string $chavePix, ?User $atualizadoPor, \DateTimeImmutable $em): void
    {
        [$this->nome, $this->documento, $this->chavePix] = self::normalizar($nome, $documento, $chavePix);
        $this->atualizadoPor = $atualizadoPor;
        $this->atualizadoEm = $em;
    }
}
