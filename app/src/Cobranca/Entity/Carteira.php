<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cliente\Entity\Cliente;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Repository\CarteiraRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Carteira de Cobrança (SPEC §4): operação/contexto de cobrança de um Cliente credor.
 * Concentra configurações e padrões de operação — modo A/B (SPEC §6), regra de honorários
 * padrão (SPEC §18), tolerância de atraso, tipo de vínculo preferido e rótulo do objeto na UI.
 * Toda carteira pertence a exatamente um cliente/credor (invariável 4).
 */
#[ORM\Entity(repositoryClass: CarteiraRepository::class)]
#[ORM\Table(name: 'cobranca_carteira')]
#[ORM\HasLifecycleCallbacks]
class Carteira implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Cliente credor dono da carteira (invariável 4). */
    #[ORM\ManyToOne(targetEntity: Cliente::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Cliente $cliente = null;

    #[ORM\Column(length: 255)]
    private string $nome = '';

    /** Modo A (uma cobrança ativa por objeto) ou B (várias). SPEC §6. */
    #[ORM\Column(enumType: ModoCarteira::class)]
    private ModoCarteira $modo = ModoCarteira::Unico;

    /** Regra de honorários PADRÃO da carteira; o caso guarda o snapshot aplicado (SPEC §18.2). */
    #[ORM\Column(enumType: FormaHonorarios::class)]
    private FormaHonorarios $formaHonorarios = FormaHonorarios::SemPercentual;

    /** Percentual padrão de honorários (ex.: "10.00"); nulo quando não se aplica. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $percentualHonorarios = null;

    /** Dias de tolerância para alertas de atraso — não rompe acordos automaticamente (SPEC §12). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $toleranciaAtrasoDias = 0;

    // ------------------------------------------------------------------------------------------
    // Configuração de encargos — NÍVEL 1 da cascata Carteira → Objeto/Caso → Obrigação
    // (spec "encargos configuráveis em cascata" §4.1). Aqui os campos são NOT NULL, porque é o
    // fundo do poço da resolução: o Caso e a Obrigação usam null para dizer "herda de cima".
    //
    // Os defaults são NEUTROS (taxa 0), decisão D4: o módulo está EM PRODUÇÃO e não pode começar a
    // gerar juros e multa sozinho por causa de um deploy. Quem quiser encargos, configura a
    // carteira (o preset da operação real é `ConfigEncargos::padraoTopLife()`).
    // ------------------------------------------------------------------------------------------

    /** Juros de mora ao mês em basis points (100 bp = 1% a.m.); pró-rata diária, mês = 30 dias. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $taxaJurosMensalBp = 0;

    #[ORM\Column(length: 20, enumType: RegimeJuros::class, options: ['default' => 'simples'])]
    private RegimeJuros $regimeJuros = RegimeJuros::Simples;

    /** Multa por atraso em basis points (200 bp = 2%); aplicação única, não cresce. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $taxaMultaBp = 0;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, options: ['default' => 'principal'])]
    private BaseEncargo $baseMulta = BaseEncargo::Principal;

    /** Correção monetária em basis points; default 0 (não usada na operação real observada). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $taxaCorrecaoBp = 0;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, options: ['default' => 'principal'])]
    private BaseEncargo $baseCorrecao = BaseEncargo::Principal;

    /**
     * Base dos honorários. Não há coluna de TAXA de honorários (decisão D2): a alíquota continua
     * vindo de `percentualHonorarios`/`formaHonorarios`, que já existem — nada de campo paralelo.
     */
    #[ORM\Column(length: 20, enumType: BaseEncargo::class, options: ['default' => 'composta'])]
    private BaseEncargo $baseHonorarios = BaseEncargo::Composta;

    /**
     * Dias de atraso sem honorários (corte estrito: 30 ⇒ honorário a partir do 31º dia). NULL cai
     * para `toleranciaAtrasoDias` (decisão D3): os dados reais mostram que a tolerância de ~30 dias
     * já configurada nas carteiras é, na prática, carência de HONORÁRIOS — juros e multa valem
     * desde o 1º dia.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $carenciaHonorariosDias = null;

    /** Dias de atraso sem juros/multa; default 0 — os dados provam que valem desde o 1º dia. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $toleranciaJurosMultaDias = 0;

    /** Tipo de vínculo normalmente cobrado — apenas sugestão, nunca decide (SPEC §8.5/8.6). */
    #[ORM\Column(enumType: TipoVinculo::class, nullable: true)]
    private ?TipoVinculo $tipoVinculoPreferido = null;

    /** Rótulo do objeto na interface (ex.: "Unidade", "Veículo"); domínio permanece genérico (SPEC §4). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $rotuloObjeto = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    /**
     * Emissão do último relatório importado, POR TIPO: `{"inadimplencia":"2026-08-04 09:13", …}`.
     *
     * 🔑 Duas decisões estão aqui, e as duas vieram de medição:
     *
     * 1. **É a emissão do relatório, não a hora da importação.** Importar hoje um arquivo emitido há
     *    três dias deixa os dados de três dias atrás; anunciar "atualizado hoje" mentiria na única
     *    pergunta que a tela existe para responder.
     * 2. **É por TIPO, e a tela mostra o MAIS ANTIGO.** Medido nos arquivos reais da AMLI: cadastro de
     *    06/08 e inadimplência de 04/08. Guardar só a data mais recente faria a tela dizer "em dia até
     *    06/08" com a dívida parada em 04/08 — otimismo exatamente onde o erro custa caro. O elo mais
     *    fraco é que manda.
     *
     * @var array<string, string> tipo => data ISO
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $emissaoPorTipoDeRelatorio = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getCliente(): ?Cliente
    {
        return $this->cliente;
    }

    public function setCliente(Cliente $cliente): self
    {
        $this->cliente = $cliente;

        return $this;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): self
    {
        $this->nome = $nome;

        return $this;
    }

    public function getModo(): ModoCarteira
    {
        return $this->modo;
    }

    public function setModo(ModoCarteira $modo): self
    {
        $this->modo = $modo;

        return $this;
    }

    public function getFormaHonorarios(): FormaHonorarios
    {
        return $this->formaHonorarios;
    }

    public function setFormaHonorarios(FormaHonorarios $formaHonorarios): self
    {
        $this->formaHonorarios = $formaHonorarios;

        return $this;
    }

    public function getPercentualHonorarios(): ?string
    {
        return $this->percentualHonorarios;
    }

    public function setPercentualHonorarios(?string $percentualHonorarios): self
    {
        $this->percentualHonorarios = $percentualHonorarios;

        return $this;
    }

    public function getToleranciaAtrasoDias(): int
    {
        return $this->toleranciaAtrasoDias;
    }

    public function setToleranciaAtrasoDias(int $toleranciaAtrasoDias): self
    {
        $this->toleranciaAtrasoDias = $toleranciaAtrasoDias;

        return $this;
    }

    public function getTaxaJurosMensalBp(): int
    {
        return $this->taxaJurosMensalBp;
    }

    public function setTaxaJurosMensalBp(int $taxaJurosMensalBp): self
    {
        $this->taxaJurosMensalBp = $taxaJurosMensalBp;

        return $this;
    }

    public function getRegimeJuros(): RegimeJuros
    {
        return $this->regimeJuros;
    }

    public function setRegimeJuros(RegimeJuros $regimeJuros): self
    {
        $this->regimeJuros = $regimeJuros;

        return $this;
    }

    public function getTaxaMultaBp(): int
    {
        return $this->taxaMultaBp;
    }

    public function setTaxaMultaBp(int $taxaMultaBp): self
    {
        $this->taxaMultaBp = $taxaMultaBp;

        return $this;
    }

    public function getBaseMulta(): BaseEncargo
    {
        return $this->baseMulta;
    }

    public function setBaseMulta(BaseEncargo $baseMulta): self
    {
        $this->baseMulta = $baseMulta;

        return $this;
    }

    public function getTaxaCorrecaoBp(): int
    {
        return $this->taxaCorrecaoBp;
    }

    public function setTaxaCorrecaoBp(int $taxaCorrecaoBp): self
    {
        $this->taxaCorrecaoBp = $taxaCorrecaoBp;

        return $this;
    }

    public function getBaseCorrecao(): BaseEncargo
    {
        return $this->baseCorrecao;
    }

    public function setBaseCorrecao(BaseEncargo $baseCorrecao): self
    {
        $this->baseCorrecao = $baseCorrecao;

        return $this;
    }

    public function getBaseHonorarios(): BaseEncargo
    {
        return $this->baseHonorarios;
    }

    public function setBaseHonorarios(BaseEncargo $baseHonorarios): self
    {
        $this->baseHonorarios = $baseHonorarios;

        return $this;
    }

    public function getCarenciaHonorariosDias(): ?int
    {
        return $this->carenciaHonorariosDias;
    }

    public function setCarenciaHonorariosDias(?int $carenciaHonorariosDias): self
    {
        $this->carenciaHonorariosDias = $carenciaHonorariosDias;

        return $this;
    }

    public function getToleranciaJurosMultaDias(): int
    {
        return $this->toleranciaJurosMultaDias;
    }

    public function setToleranciaJurosMultaDias(int $toleranciaJurosMultaDias): self
    {
        $this->toleranciaJurosMultaDias = $toleranciaJurosMultaDias;

        return $this;
    }

    public function getTipoVinculoPreferido(): ?TipoVinculo
    {
        return $this->tipoVinculoPreferido;
    }

    public function setTipoVinculoPreferido(?TipoVinculo $tipoVinculoPreferido): self
    {
        $this->tipoVinculoPreferido = $tipoVinculoPreferido;

        return $this;
    }

    public function getRotuloObjeto(): ?string
    {
        return $this->rotuloObjeto;
    }

    public function setRotuloObjeto(?string $rotuloObjeto): self
    {
        $this->rotuloObjeto = $rotuloObjeto;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    /**
     * Registra a emissão do relatório recém-importado. Dentro de cada tipo só AVANÇA: reimportar um
     * arquivo antigo (completar histórico, reprocessar) não pode fazer a tela dizer que os dados
     * envelheceram.
     */
    public function registrarEmissaoImportada(string $tipoRelatorio, ?\DateTimeImmutable $emissao): self
    {
        if ($emissao === null) {
            return $this;
        }

        $mapa = $this->emissaoPorTipoDeRelatorio ?? [];
        $atual = $mapa[$tipoRelatorio] ?? null;

        if ($atual === null || $emissao > new \DateTimeImmutable($atual)) {
            $mapa[$tipoRelatorio] = $emissao->format('Y-m-d H:i:s');
            $this->emissaoPorTipoDeRelatorio = $mapa;
        }

        return $this;
    }

    /**
     * Até quando os dados da carteira estão em dia: a emissão MAIS ANTIGA entre os tipos importados.
     *
     * 🔑 O elo mais fraco é que manda. A carteira é a soma dos relatórios; se a dívida veio de um
     * arquivo de três dias atrás, não importa que o cadastro seja de hoje — há três dias de movimento
     * que o sistema não viu. Null quando nada foi importado ainda.
     */
    public function getDadosAtualizadosAte(): ?\DateTimeImmutable
    {
        $mapa = $this->emissaoPorTipoDeRelatorio ?? [];
        if ($mapa === []) {
            return null;
        }

        $datas = array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d), array_values($mapa));

        return min($datas);
    }

    /** @return array<string, \DateTimeImmutable> tipo => emissão, para o detalhamento na tela */
    public function getEmissaoPorTipoDeRelatorio(): array
    {
        $mapa = $this->emissaoPorTipoDeRelatorio ?? [];

        return array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d), $mapa);
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;

        return $this;
    }
}
