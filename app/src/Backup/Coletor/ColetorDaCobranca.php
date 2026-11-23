<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Cobrança no backup: `dados/cobranca.json` (carteiras, objetos, casos, obrigações, pagamentos, acordos, liquidações,
 * pessoas cobradas e vínculos, em tabelas planas ligadas por `id`) e `cobranca/<carteira>/...` com os documentos da
 * carteira, dos casos (por seção) e dos acordos. Valores em centavos.
 *
 * Só roda para quem tem o módulo `cobrancas`. A tela de Cobrança só confere esse módulo para LER (`tenantComModulo`
 * em `AutorizacaoCobranca.php:63`, usado por `CarteiraController::index/show`, `ObjetoController::show`,
 * `AcordoController::show` e `DocumentoCobrancaController::baixar`): não há ACL por item (`resource_access` não está
 * ligado à cobrança) nem cadeado. Quem tem o módulo vê o escritório inteiro, então o backup leva o escritório
 * inteiro (`tenant_id` escrito na mão), incluindo os dados dos devedores (terceiros), como decidido na spec (seção 7).
 * Fora: o espelho dos relatórios importados e o NUP da pasta judicial (a pasta tem a própria regra de visibilidade).
 */
#[AsTaggedItem(priority: 5)]
final class ColetorDaCobranca implements ColetorDoBackup
{
    use PercorreEmPaginasTrait;

    public const MODULO = 'cobrancas';

    public const REGISTROS_POR_PAGINA = 100;

    /** @var array<string, string> chave => diretório já reservado no ZIP (o nome sai uma vez só) */
    private array $diretorios = [];

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly EntityManagerInterface $em,
        private readonly int $porPagina = self::REGISTROS_POR_PAGINA,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $tenantId         = (int) $tenant->getId();
        $this->diretorios = [];
        $dados            = [
            'carteiras'   => $this->tabela(Carteira::class, $tenantId, $this->carteira(...), $zip, 'Carteiras de cobrança'),
            'objetos'     => $this->tabela(ObjetoCobranca::class, $tenantId, $this->objeto(...), $zip, 'Objetos de cobrança', static fn (QueryBuilder $qb) => $qb->addSelect('ca')->join('e.carteira', 'ca')),
            'casos'       => $this->tabela(CasoCobranca::class, $tenantId, $this->caso(...), $zip, 'Casos de cobrança'),
            'obrigacoes'  => $this->tabela(Obrigacao::class, $tenantId, $this->obrigacao(...)),
            'pagamentos'  => $this->tabela(Pagamento::class, $tenantId, $this->pagamento(...)),
            'acordos'     => $this->tabela(Acordo::class, $tenantId, $this->acordo(...), $zip, 'Acordos de cobrança'),
            'liquidacoes' => $this->tabela(Liquidacao::class, $tenantId, $this->liquidacao(...)),
            'pessoas'     => $this->tabela(Pessoa::class, $tenantId, $this->pessoa(...), $zip, 'Pessoas cobradas'),
            'vinculos'    => $this->tabela(VinculoPessoaObjeto::class, $tenantId, $this->vinculo(...)),
        ];
        $this->documentos($tenantId, $zip);

        $dir = $zip->reservarDiretorio('', 'dados');
        $zip->adicionarJson($dir . '/cobranca.json', $dados);
    }

    /**
     * @param class-string                        $classe
     * @param callable(object): array<string, mixed> $paraLinha
     * @param (callable(QueryBuilder): mixed)|null $ajuste
     *
     * @return list<array<string, mixed>>
     */
    private function tabela(string $classe, int $tenantId, callable $paraLinha, ?ZipDoBackup $zip = null, ?string $rotulo = null, ?callable $ajuste = null): array
    {
        $linhas = [];
        foreach ($this->paginas($classe, $tenantId, $this->porPagina, $ajuste) as $pagina) {
            foreach ($pagina as $registro) {
                $linhas[] = $paraLinha($registro);
                if ($zip !== null && $rotulo !== null) {
                    $zip->contar($rotulo);
                }
            }
        }

        return $linhas;
    }

    /** @return array<string, mixed> */
    private function carteira(Carteira $c): array
    {
        return [
            'id' => $c->getId(), 'nome' => $c->getNome(), 'modo' => $c->getModo()->value, 'cliente' => $c->getCliente()?->getNomeExibicao(),
            'forma_honorarios' => $c->getFormaHonorarios()->value, 'percentual_honorarios' => $c->getPercentualHonorarios(),
            'tolerancia_atraso_dias' => $c->getToleranciaAtrasoDias(), 'taxa_juros_mensal_bp' => $c->getTaxaJurosMensalBp(),
            'regime_juros' => $c->getRegimeJuros()->value, 'taxa_multa_bp' => $c->getTaxaMultaBp(), 'taxa_correcao_bp' => $c->getTaxaCorrecaoBp(),
            'rotulo_objeto' => $c->getRotuloObjeto(), 'criado_em' => ValoresDoBackup::data($c->getCriadoEm()),
        ];
    }

    /** @return array<string, mixed> */
    private function objeto(ObjetoCobranca $o): array
    {
        return [
            'id' => $o->getId(), 'carteira_id' => $o->getCarteira()?->getId(), 'identificacao' => $o->getIdentificacao(), 'descricao' => $o->getDescricao(),
            'referencia_externa' => $o->getReferenciaExterna(), 'criado_em' => ValoresDoBackup::data($o->getCriadoEm()),
        ];
    }

    /** @return array<string, mixed> */
    private function caso(CasoCobranca $c): array
    {
        return [
            'id' => $c->getId(), 'objeto_id' => $c->getObjeto()?->getId(), 'pessoa_cobrada_id' => $c->getPessoaCobradaAtual()?->getId(),
            'status' => $c->getStatus()->value, 'forma_honorarios' => $c->getFormaHonorarios()->value, 'percentual_honorarios' => $c->getPercentualHonorarios(),
            'judicializado' => $c->estaJudicializado(), 'criado_em' => ValoresDoBackup::data($c->getCriadoEm()),
        ];
    }

    /** @return array<string, mixed> */
    private function obrigacao(Obrigacao $o): array
    {
        return [
            'id' => $o->getId(), 'caso_id' => $o->getCaso()?->getId(), 'descricao' => $o->getDescricao(), 'competencia' => $o->getCompetencia(),
            'valor_original_centavos' => $o->getValorOriginal(), 'vencimento_original' => ValoresDoBackup::dia($o->getVencimentoOriginal()),
            'juros_centavos' => $o->getJuros(), 'multa_centavos' => $o->getMulta(), 'correcao_centavos' => $o->getCorrecao(), 'honorarios_centavos' => $o->getHonorarios(),
            'liquidada_em' => ValoresDoBackup::data($o->getLiquidadaEm()), 'acordo_origem_id' => $o->getAcordoOrigem()?->getId(),
            'acordo_substituto_id' => $o->getAcordoSubstituto()?->getId(), 'referencia_externa' => $o->getReferenciaExterna(),
            'criado_em' => ValoresDoBackup::data($o->getCriadoEm()),
        ];
    }

    /** @return array<string, mixed> */
    private function pagamento(Pagamento $p): array
    {
        return [
            'id' => $p->getId(), 'caso_id' => $p->getCaso()?->getId(), 'data' => ValoresDoBackup::dia($p->getData()),
            'valor_divida_centavos' => $p->getValorDivida(), 'valor_encargos_centavos' => $p->getValorEncargos(), 'valor_honorarios_centavos' => $p->getValorHonorarios(),
            'motivo_correcao' => $p->getMotivoCorrecao(), 'criado_em' => ValoresDoBackup::data($p->getCriadoEm()),
        ];
    }

    /** @return array<string, mixed> */
    private function acordo(Acordo $a): array
    {
        return [
            'id' => $a->getId(), 'caso_id' => $a->getCaso()?->getId(), 'status' => $a->getStatus()->value, 'data_acordo' => ValoresDoBackup::data($a->getDataAcordo()),
            'valor_total_negociado_centavos' => $a->getValorTotalNegociado(), 'valor_entrada_centavos' => $a->getValorEntrada(),
            'numero_parcelas' => $a->getNumeroParcelasTotal(), 'motivo_rompimento' => $a->getMotivoRompimento(), 'motivo_cancelamento' => $a->getMotivoCancelamento(),
            'criado_em' => ValoresDoBackup::data($a->getCriadoEm()),
        ];
    }

    /** @return array<string, mixed> */
    private function liquidacao(Liquidacao $l): array
    {
        return [
            'id' => $l->getId(), 'caso_id' => $l->getCaso()?->getId(), 'tipo' => $l->getTipo()->value, 'descricao_bem' => $l->getDescricaoBem(),
            'valor_atribuido_bem_centavos' => $l->getValorAtribuidoBem(), 'valor_reconhecido_centavos' => $l->getValorReconhecido(), 'data' => ValoresDoBackup::dia($l->getData()),
        ];
    }

    /** @return array<string, mixed> */
    private function pessoa(Pessoa $p): array
    {
        return [
            'id' => $p->getId(), 'nome' => $p->getNome(), 'cpf' => $p->getCpf(), 'cnpj' => $p->getCnpj(), 'rg' => $p->getRg(), 'profissao' => $p->getProfissao(),
            'data_nascimento' => ValoresDoBackup::dia($p->getDataNascimento()), 'observacao' => $p->getObservacao(),
            'emails'    => array_map(static fn (PessoaEmail $e): array => ['email' => $e->getEmail(), 'atual' => $e->isAtual()], $this->filhos(PessoaEmail::class, $p)),
            'telefones' => array_map(static fn (PessoaTelefone $t): array => ['numero' => $t->getNumero(), 'atual' => $t->isAtual()], $this->filhos(PessoaTelefone::class, $p)),
            'enderecos' => array_map(static fn (PessoaEndereco $e): array => [
                'logradouro' => $e->getLogradouro(), 'numero' => $e->getNumero(), 'complemento' => $e->getComplemento(), 'bairro' => $e->getBairro(),
                'cidade' => $e->getCidade(), 'uf' => $e->getUf(), 'cep' => $e->getCep(), 'atual' => $e->isAtual(),
            ], $this->filhos(PessoaEndereco::class, $p)),
        ];
    }

    /**
     * @param class-string $classe
     *
     * @return list<object>
     */
    private function filhos(string $classe, Pessoa $pessoa): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('f')->from($classe, 'f')
            ->andWhere('f.pessoa = :pessoa')->andWhere('IDENTITY(f.tenant) = :tenantId')
            ->setParameter('pessoa', $pessoa->getId())->setParameter('tenantId', $pessoa->getTenant()?->getId())
            ->orderBy('f.id')->getQuery()->getResult());
    }

    /** @return array<string, mixed> */
    private function vinculo(VinculoPessoaObjeto $v): array
    {
        return [
            'pessoa_id' => $v->getPessoa()?->getId(), 'objeto_id' => $v->getObjeto()?->getId(), 'tipo' => $v->getTipoVinculo()->value,
            'inicio' => ValoresDoBackup::dia($v->getDataInicio()), 'fim' => ValoresDoBackup::dia($v->getDataFim()), 'motivo_encerramento' => $v->getMotivoEncerramento(),
        ];
    }

    private function documentos(int $tenantId, ZipDoBackup $zip): void
    {
        foreach ($this->paginas(CarteiraDocumento::class, $tenantId, $this->porPagina, static fn (QueryBuilder $qb) => $qb->addSelect('ca')->join('e.carteira', 'ca')) as $pagina) {
            foreach ($pagina as $d) {
                \assert($d instanceof CarteiraDocumento);
                $dir = $this->dir('car:' . $d->getCarteira()?->getId() . ':docs', $this->dirDaCarteira($d->getCarteira(), $zip), 'documentos da carteira', $zip);
                $this->colocar($zip, $dir, $d->getNomeOriginal(), static fn () => ChavesDeCobranca::documentoDeCarteira($d), $d->getTamanhoBytes());
            }
        }
        foreach ($this->paginas(CobrancaDocumento::class, $tenantId, $this->porPagina, static fn (QueryBuilder $qb) => $qb->addSelect('s', 'c', 'o', 'ca')->join('e.caso', 'c')->join('c.objeto', 'o')->join('o.carteira', 'ca')->leftJoin('e.secao', 's')) as $pagina) {
            foreach ($pagina as $d) {
                \assert($d instanceof CobrancaDocumento);
                $dir = $this->dir('obj:' . $d->getCaso()?->getObjeto()?->getId() . ':docs', $this->dirDoObjeto($d->getCaso()?->getObjeto(), $zip), 'documentos', $zip);
                if ($d->getSecao() !== null) {
                    $dir = $this->dir('sec:' . $d->getSecao()->getId(), $dir, $d->getSecao()->getNome(), $zip);
                }
                $this->colocar($zip, $dir, $d->getNomeOriginal(), static fn () => ChavesDeCobranca::documentoDeCaso($d), $d->getTamanhoBytes());
            }
        }
        foreach ($this->paginas(AcordoDocumento::class, $tenantId, $this->porPagina, static fn (QueryBuilder $qb) => $qb->addSelect('a', 'c', 'o', 'ca')->join('e.acordo', 'a')->join('a.caso', 'c')->join('c.objeto', 'o')->join('o.carteira', 'ca')) as $pagina) {
            foreach ($pagina as $d) {
                \assert($d instanceof AcordoDocumento);
                $acordo = $d->getAcordo();
                $dir    = $this->dir('acd:' . $acordo?->getId(), $this->dirDoObjeto($acordo?->getCaso()?->getObjeto(), $zip), 'acordo ' . $acordo?->getId(), $zip);
                $this->colocar($zip, $dir, $d->getNomeOriginal(), static fn () => ChavesDeCobranca::documentoDeAcordo($d), $d->getTamanhoBytes());
            }
        }
    }

    private function dirDaCarteira(?Carteira $carteira, ZipDoBackup $zip): string
    {
        $raiz = $this->dir('raiz', '', 'cobranca', $zip);

        return $this->dir('car:' . $carteira?->getId(), $raiz, $carteira?->getNome() ?? 'carteira', $zip);
    }

    private function dirDoObjeto(?ObjetoCobranca $objeto, ZipDoBackup $zip): string
    {
        return $this->dir('obj:' . $objeto?->getId(), $this->dirDaCarteira($objeto?->getCarteira(), $zip), $objeto?->getIdentificacao() ?? 'objeto', $zip);
    }

    private function dir(string $chave, string $pai, string $nome, ZipDoBackup $zip): string
    {
        return $this->diretorios[$chave] ??= $zip->reservarDiretorio($pai, $nome);
    }

    private function colocar(ZipDoBackup $zip, string $dir, string $nome, \Closure $chave, int $tamanho): void
    {
        if ($zip->adicionarArquivo($dir, $nome, $chave, $tamanho) !== null) {
            $zip->contar('Documentos de cobrança');
        }
    }
}
