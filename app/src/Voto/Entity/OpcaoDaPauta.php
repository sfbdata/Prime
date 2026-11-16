<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Voto\Repository\OpcaoDaPautaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opção de voto de uma pauta (spec docs/specs/voto-assembleia-condominio.md, seções 2 e 3), com o contador de votos.
 * A abstenção é uma opção com `abstencao = true` e não conta como voto válido.
 *
 * SIGILO (seção 2): o voto só soma no contador (`total_unidades`, `total_peso`) por SQL direto, na mesma transação do
 * INSERT da participação ({@see \App\Voto\Repository\OpcaoDaPautaRepository::somarVoto()}). Por isso esta entidade
 * NÃO é `Auditavel` e não tem método que mude o contador: o audit_log guardaria a hora da mudança e ligaria o voto à
 * participação da mesma hora. Depois de um voto, a instância em memória fica com o contador antigo; quem precisar do
 * número atual relê do banco.
 *
 * O tenant vem da pauta (nunca de fora).
 */
#[ORM\Entity(repositoryClass: OpcaoDaPautaRepository::class)]
#[ORM\Table(name: 'assembleia_opcao', schema: 'voto')]
class OpcaoDaPauta implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalUnidades = 0;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 6, options: ['default' => 0])]
    private string $totalPeso = '0.000000';

    /** Descrição que a urna mostra abaixo do nome (uma linha). */
    #[ORM\Column(length: 280, nullable: true)]
    private ?string $descricao = null;

    /** Propostas da candidatura (texto livre). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $propostas = null;

    /** Composição da chapa, um cargo por linha (ex.: "Presidente: Nome"). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $composicao = null;

    /** Unidade do CANDIDATO (texto livre, opcional). Nunca liga à unidade de quem vota. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $unidadeCandidato = null;

    /**
     * Chave (nome) da foto 3x4 da candidatura no armazenamento do sistema, ou null. Só o nome: o caminho e o escritório
     * saem de `ChavesDoVoto`. Fica FORA da configuração publicada (o código de conferência não a lê).
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $foto = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PautaDaAssembleia::class, inversedBy: 'opcoes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PautaDaAssembleia $pauta,
        #[ORM\Column(type: 'integer')]
        private int $numero,
        #[ORM\Column(length: 120)]
        private string $rotulo,
        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        private bool $abstencao = false,
    ) {
        $this->tenant = $pauta->getTenant();
        $this->rotulo = mb_substr(trim($rotulo), 0, 120);
        $pauta->registrarOpcao($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPauta(): PautaDaAssembleia
    {
        return $this->pauta;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function getRotulo(): string
    {
        return $this->rotulo;
    }

    public function isAbstencao(): bool
    {
        return $this->abstencao;
    }

    /** Grava os dados da candidatura; texto vazio vira nulo e cada campo tem o seu limite. */
    public function detalhar(?string $descricao, ?string $propostas = null, ?string $composicao = null, ?string $unidadeCandidato = null): void
    {
        $this->descricao = self::limpar($descricao, 280);
        $this->propostas = self::limpar($propostas, 2000);
        $this->composicao = self::limpar($composicao, 1000);
        $this->unidadeCandidato = self::limpar($unidadeCandidato, 60);
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function getPropostas(): ?string
    {
        return $this->propostas;
    }

    public function getComposicao(): ?string
    {
        return $this->composicao;
    }

    public function getUnidadeCandidato(): ?string
    {
        return $this->unidadeCandidato;
    }

    public function getFoto(): ?string
    {
        return $this->foto;
    }

    /** Aponta para a foto (ou tira, com null). Quem chama cuida do arquivo: o novo antes, o antigo só depois do COMMIT. */
    public function definirFoto(?string $foto): void
    {
        $this->foto = null === $foto || '' === $foto ? null : $foto;
    }

    private static function limpar(?string $texto, int $limite): ?string
    {
        $texto = trim(str_replace("\r\n", "\n", (string) $texto));

        return '' === $texto ? null : mb_substr($texto, 0, $limite);
    }

    /** Unidades que escolheram esta opção (contador; ver o sigilo no cabeçalho). */
    public function getTotalUnidades(): int
    {
        return $this->totalUnidades;
    }

    /** Soma do peso de quem escolheu esta opção, decimal com 6 casas em string (nunca float). */
    public function getTotalPeso(): string
    {
        return $this->totalPeso;
    }
}
