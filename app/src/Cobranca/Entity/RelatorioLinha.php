<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do arquivo da contabilidade, copiada como está (SPEC espelho §4.1 e §4.2).
 *
 * INV-L1: **cópia, não interpretação.** O adapter de importação agrupa por (unidade + NN), soma
 * colunas em baldes e decide o que é principal — e descarta G (Atraso), M (Total) e O (Recebimento).
 * Esta tabela guarda as 15 colunas como vieram, sem agrupar, sem somar, sem julgar. É por isso que
 * ela consegue conferir o adapter: se herdasse a leitura dele, herdaria os defeitos dele.
 *
 * INV-L2: **guarda TODAS as linhas de dado**, inclusive as que o adapter rejeitaria. "Importável" é
 * julgamento do adapter, e é exatamente o que se quer conferir. Hoje a `LinhaRejeitada` do adapter
 * preserva só `[nn, unidade, sacado, competencia]` — todo valor financeiro de um boleto rejeitado se
 * perde. Aqui não se perde.
 *
 * INV-L3: **valores em CENTAVOS e também o texto original** (`bruto`). O int é para consultar; o
 * texto é a prova de que a conversão acertou, quando alguém duvidar do número. Colunas monetárias
 * são **nullable de propósito**: célula vazia e célula com zero são coisas diferentes, e achatar as
 * duas em `0` seria interpretar.
 *
 * INV-L4: **sem FK para `Obrigacao`.** A ligação entre a linha da planilha e a dívida do sistema é
 * feita na conferência, por (caso, referência, competência) — e é justamente essa ligação que pode
 * falhar. Transformá-la em estrutura esconderia o defeito que se quer medir.
 *
 * Não implementa `Auditavel` — ver INV-E2 em {@see RelatorioImportado}.
 */
#[ORM\Entity(repositoryClass: RelatorioLinhaRepository::class)]
#[ORM\Table(name: 'cobranca_relatorio_linha')]
#[ORM\Index(name: 'idx_cobranca_relatorio_linha_relatorio', columns: ['relatorio_id'])]
#[ORM\Index(name: 'idx_cobranca_relatorio_linha_bloco', columns: ['relatorio_id', 'bloco'])]
#[ORM\Index(name: 'idx_cobranca_relatorio_linha_nn', columns: ['relatorio_id', 'nn', 'competencia'])]
#[ORM\Index(name: 'idx_cobranca_relatorio_linha_unidade', columns: ['relatorio_id', 'unidade'])]
class RelatorioLinha implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: RelatorioImportado::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?RelatorioImportado $relatorio = null;

    /** Posição física no .xlsx (1-based), para achar a linha no arquivo original. */
    #[ORM\Column(type: 'integer')]
    private int $numeroLinha = 0;

    /** Em que parte do arquivo a linha estava. A soma dos seis baldes == `linhasTotal`. */
    #[ORM\Column(length: 20, enumType: BlocoRelatorio::class)]
    private BlocoRelatorio $bloco = BlocoRelatorio::Dados;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $unidade = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sacado = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $classe = null;

    /** Competência da dívida, `MM/AAAA`, como veio — não normalizada. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $competencia = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $vencimento = null;

    /** Coluna G — dias de atraso, que o adapter nunca lê. Serve para conferir a nossa contagem. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $atraso = null;

    /** Coluna H (Valor), em centavos. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valor = null;

    /** Coluna I (Juros), em centavos. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $juros = null;

    /** Coluna J (Multa), em centavos. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $multa = null;

    /** Coluna K (Correção), em centavos. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $correcao = null;

    /** Coluna L (Honorários), em centavos. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $honorarios = null;

    /** Coluna M (Total), que o adapter nunca lê. **Copiado, nunca recalculado.** */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $total = null;

    /** Coluna N — o texto cru do acordo, sem parse. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $acordoTexto = null;

    /** Coluna O (Recebimento), que o adapter nunca lê. Vazia nesta fonte, guardada mesmo assim. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recebimento = null;

    /**
     * As células como TEXTO, na ordem do arquivo. É a prova de que a conversão para centavos
     * acertou — ver INV-L3.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bruto = null;

    /**
     * Os campos dos três layouts que entraram na SPEC quatro-relatórios (§4.3). Ficam `null` na
     * inadimplência, que não os tem.
     *
     * 🔴 **Cada um ganhou coluna própria — nenhum foi encaixado numa que já existia.** Guardar
     * `Valor recebido` dentro de `total` porque "cabe" produz número errado com cara de certo, que é o
     * defeito que este módulo já levou duas vezes. Campo sem coluna fica em `bruto` e não vira número.
     */

    /** Nome da aba. Só os acordos têm uma por acordo; nos outros três é `null`. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aba = null;

    /**
     * Qual das duas tabelas da aba de acordo — {@see \App\Cobranca\Enum\TabelaDoAcordo}.
     *
     * Guardado como texto e não deduzido de `parcela` estar preenchida: inferência funciona hoje e
     * passa a mentir no dia em que uma parcela vier sem o rótulo.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $tabela = null;

    /** `5/40`, como a planilha de acordos escreve. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $parcela = null;

    /** `Liquidação` (acordos) ou `Recebimento` (receitas), já como data. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $liquidacao = null;

    /**
     * `Valor recebido` (receitas) ou `Valor liquidado` (acordos), em centavos.
     *
     * ⚠️ Não é o total de nada: é quanto entrou de um valor que podia ter entrado inteiro ou em parte.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valorRecebido = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAba(): ?string
    {
        return $this->aba;
    }

    public function setAba(?string $aba): self
    {
        $this->aba = $aba;

        return $this;
    }

    public function getTabela(): ?string
    {
        return $this->tabela;
    }

    public function setTabela(?string $tabela): self
    {
        $this->tabela = $tabela;

        return $this;
    }

    public function getParcela(): ?string
    {
        return $this->parcela;
    }

    public function setParcela(?string $parcela): self
    {
        $this->parcela = $parcela;

        return $this;
    }

    public function getLiquidacao(): ?\DateTimeImmutable
    {
        return $this->liquidacao;
    }

    public function setLiquidacao(?\DateTimeImmutable $liquidacao): self
    {
        $this->liquidacao = $liquidacao;

        return $this;
    }

    public function getValorRecebido(): ?int
    {
        return $this->valorRecebido;
    }

    public function setValorRecebido(?int $valorRecebido): self
    {
        $this->valorRecebido = $valorRecebido;

        return $this;
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

    public function getRelatorio(): ?RelatorioImportado
    {
        return $this->relatorio;
    }

    public function setRelatorio(RelatorioImportado $relatorio): self
    {
        $this->relatorio = $relatorio;

        return $this;
    }

    public function getNumeroLinha(): int
    {
        return $this->numeroLinha;
    }

    public function setNumeroLinha(int $numeroLinha): self
    {
        $this->numeroLinha = $numeroLinha;

        return $this;
    }

    public function getBloco(): BlocoRelatorio
    {
        return $this->bloco;
    }

    public function setBloco(BlocoRelatorio $bloco): self
    {
        $this->bloco = $bloco;

        return $this;
    }

    public function getUnidade(): ?string
    {
        return $this->unidade;
    }

    public function setUnidade(?string $unidade): self
    {
        $this->unidade = $unidade;

        return $this;
    }

    public function getSacado(): ?string
    {
        return $this->sacado;
    }

    public function setSacado(?string $sacado): self
    {
        $this->sacado = $sacado;

        return $this;
    }

    public function getNn(): ?string
    {
        return $this->nn;
    }

    public function setNn(?string $nn): self
    {
        $this->nn = $nn;

        return $this;
    }

    public function getClasse(): ?string
    {
        return $this->classe;
    }

    public function setClasse(?string $classe): self
    {
        $this->classe = $classe;

        return $this;
    }

    public function getCompetencia(): ?string
    {
        return $this->competencia;
    }

    public function setCompetencia(?string $competencia): self
    {
        $this->competencia = $competencia;

        return $this;
    }

    public function getVencimento(): ?\DateTimeImmutable
    {
        return $this->vencimento;
    }

    public function setVencimento(?\DateTimeImmutable $vencimento): self
    {
        $this->vencimento = $vencimento;

        return $this;
    }

    public function getAtraso(): ?int
    {
        return $this->atraso;
    }

    public function setAtraso(?int $atraso): self
    {
        $this->atraso = $atraso;

        return $this;
    }

    public function getValor(): ?int
    {
        return $this->valor;
    }

    public function setValor(?int $valor): self
    {
        $this->valor = $valor;

        return $this;
    }

    public function getJuros(): ?int
    {
        return $this->juros;
    }

    public function setJuros(?int $juros): self
    {
        $this->juros = $juros;

        return $this;
    }

    public function getMulta(): ?int
    {
        return $this->multa;
    }

    public function setMulta(?int $multa): self
    {
        $this->multa = $multa;

        return $this;
    }

    public function getCorrecao(): ?int
    {
        return $this->correcao;
    }

    public function setCorrecao(?int $correcao): self
    {
        $this->correcao = $correcao;

        return $this;
    }

    public function getHonorarios(): ?int
    {
        return $this->honorarios;
    }

    public function setHonorarios(?int $honorarios): self
    {
        $this->honorarios = $honorarios;

        return $this;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function setTotal(?int $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getAcordoTexto(): ?string
    {
        return $this->acordoTexto;
    }

    public function setAcordoTexto(?string $acordoTexto): self
    {
        $this->acordoTexto = $acordoTexto;

        return $this;
    }

    public function getRecebimento(): ?string
    {
        return $this->recebimento;
    }

    public function setRecebimento(?string $recebimento): self
    {
        $this->recebimento = $recebimento;

        return $this;
    }

    /** @return list<string>|null */
    public function getBruto(): ?array
    {
        return $this->bruto;
    }

    /** @param list<string>|null $bruto */
    public function setBruto(?array $bruto): self
    {
        $this->bruto = $bruto;

        return $this;
    }
}
