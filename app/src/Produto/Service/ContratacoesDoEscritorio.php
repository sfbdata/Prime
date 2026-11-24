<?php

declare(strict_types=1);

namespace App\Produto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Enum\SituacaoDaContratacao;
use App\Produto\Repository\ContratacaoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * As regras das linhas de `contratacao` quando alguém liga ou desliga um produto (spec
 * docs/specs/multiproduto-contratacao-e-voto.md, §9 e D5, D16). Serve ao dono no Meu Perfil e ao Master nas abas
 * "Voto" e "I.A.".
 *
 * - Ligar num escritório sem linha nenhuma (ou com `completo` ativa) não grava nada: ele já tem tudo.
 * - Desligar num escritório sem linha (ou com `completo` ativa) materializa: grava uma linha ativa, com a mesma origem,
 *   para cada ferramenta menos a escolhida, e encerra a `completo` (D5).
 * - Trava do Master (D16): a última linha do produto encerrada com origem `master`. Quando o Master desliga um produto
 *   que já não tinha linha ativa, grava um marcador (linha aberta e encerrada no mesmo instante, origem `master`).
 *
 * Quem chama roda tudo dentro de {@see sobTrava()}: dois cliques ao mesmo tempo não quebram o índice
 * `uniq_contratacao_ativa` nem gravam um conjunto errado.
 */
// Não-final: os testes de UseCase o substituem por mock (a trava precisa da conexão, que não se mocka).
class ContratacoesDoEscritorio
{
    /** Classe da trava `pg_advisory_xact_lock(classe, tenant_id)`: as contratações de um escritório. */
    public const CLASSE_TRAVA = 4801;

    public function __construct(
        private readonly ContratacaoRepository $contratacoes,
        private readonly EntityManagerInterface $em,
        private readonly ProdutosDoEscritorio $produtos,
    ) {
    }

    /**
     * Roda `$acao` numa transação da conexão, sob a trava do escritório, e devolve o que ela devolver. A transação é da
     * conexão (não `wrapInTransaction`, que fecha o EntityManager ao recusar por regra de negócio). Depois do commit
     * (e no erro) a memória dos produtos do escritório é limpa, para a mesma requisição ler o estado novo.
     */
    public function sobTrava(Tenant $tenant, callable $acao): mixed
    {
        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();
        try {
            $conexao->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, (int) $tenant->getId()]);
            $resultado = $acao();
            $conexao->commit();
        } catch (\Throwable $e) {
            $conexao->rollBack();

            throw $e;
        } finally {
            $this->produtos->reset();
        }

        return $resultado;
    }

    /** D16: a última linha do produto (por id) está encerrada com origem `master`. Só o Master religa. */
    public function travadoPeloMaster(Tenant $tenant, Produto $produto): bool
    {
        return self::encerradaPeloMaster(self::ultimaDo($this->contratacoes->todasDoTenant($tenant), $produto));
    }

    /**
     * D7: o produto é o único ligado? Conta os produtos ligados de fato (sem linha ou com `completo` ativa, todos),
     * nunca o número de linhas.
     */
    public function eOUnicoLigado(Tenant $tenant, Produto $produto): bool
    {
        $ativos = [];
        foreach ($this->contratacoes->todasDoTenant($tenant) as $linha) {
            if (SituacaoDaContratacao::Ativa === $linha->getSituacao()) {
                $ativos[$linha->getProduto()->value] = $linha->getProduto();
            }
        }

        return [$produto->value] === array_keys($ativos);
    }

    /** Liga o produto. Sem linha nenhuma, com `completo` ativa ou com o produto já ativo, não grava nada. */
    public function incluir(Tenant $tenant, Produto $produto, OrigemDaContratacao $origem, ?User $por, \DateTimeImmutable $agora): void
    {
        $linhas = $this->contratacoes->todasDoTenant($tenant);
        if ([] === $linhas || null !== self::ativaDo($linhas, Produto::Completo) || null !== self::ativaDo($linhas, $produto)) {
            return;
        }

        $this->contratacoes->salvar(Contratacao::ativar($tenant, $produto, $origem, $por, $agora));
        $this->em->flush();
    }

    /**
     * Desliga o produto: materializa se preciso (D5), encerra a linha ativa dele e, quando é o Master que desliga um
     * produto sem linha ativa, grava o marcador da trava (D16).
     */
    public function excluir(Tenant $tenant, Produto $produto, OrigemDaContratacao $origem, ?User $por, \DateTimeImmutable $agora): void
    {
        $linhas = $this->contratacoes->todasDoTenant($tenant);
        if ([] === $linhas || null !== self::ativaDo($linhas, Produto::Completo)) {
            $this->materializar($tenant, $produto, $linhas, $origem, $por, $agora);
        }

        $ativa = self::ativaDo($linhas, $produto);
        if (null !== $ativa) {
            $ativa->encerrar($origem, $por, $agora);
        }
        // A marca da trava (D16) é só de ferramenta: o `completo` não se religa pelo dono, e a marca dele seria lixo.
        if (null === $ativa && OrigemDaContratacao::Master === $origem && \in_array($produto, Produto::ferramentas(), true)
            && !self::encerradaPeloMaster(self::ultimaDo($linhas, $produto))) {
            $this->marcarTravaDoMaster($tenant, $produto, $por, $agora);
        }
        $this->em->flush();
    }

    /**
     * Uma linha ativa para cada ferramenta menos a escolhida (Voto e I.A. inclusive: a liberação continua decidindo à
     * parte) e a `completo` encerrada. Ferramenta que já tem linha ativa fica como está.
     *
     * @param list<Contratacao> $linhas
     */
    private function materializar(Tenant $tenant, Produto $escolhido, array $linhas, OrigemDaContratacao $origem, ?User $por, \DateTimeImmutable $agora): void
    {
        foreach (Produto::ferramentas() as $produto) {
            if ($produto !== $escolhido && null === self::ativaDo($linhas, $produto)) {
                $this->contratacoes->salvar(Contratacao::ativar($tenant, $produto, $origem, $por, $agora));
            }
        }
        self::ativaDo($linhas, Produto::Completo)?->encerrar($origem, $por, $agora);
    }

    /** Linha aberta e encerrada no mesmo instante (o CHECK `fim >= inicio` aceita igual): a marca da trava do Master. */
    private function marcarTravaDoMaster(Tenant $tenant, Produto $produto, ?User $por, \DateTimeImmutable $agora): void
    {
        $marcador = Contratacao::ativar($tenant, $produto, OrigemDaContratacao::Master, $por, $agora);
        $marcador->encerrar(OrigemDaContratacao::Master, $por, $agora);
        $this->contratacoes->salvar($marcador);
    }

    /** @param list<Contratacao> $linhas */
    private static function ativaDo(array $linhas, Produto $produto): ?Contratacao
    {
        foreach ($linhas as $linha) {
            if ($linha->getProduto() === $produto && SituacaoDaContratacao::Ativa === $linha->getSituacao()) {
                return $linha;
            }
        }

        return null;
    }

    /** @param list<Contratacao> $linhas */
    private static function ultimaDo(array $linhas, Produto $produto): ?Contratacao
    {
        $ultima = null;
        foreach ($linhas as $linha) {
            if ($linha->getProduto() === $produto && (null === $ultima || (int) $linha->getId() > (int) $ultima->getId())) {
                $ultima = $linha;
            }
        }

        return $ultima;
    }

    private static function encerradaPeloMaster(?Contratacao $linha): bool
    {
        return null !== $linha
            && SituacaoDaContratacao::Encerrada === $linha->getSituacao()
            && OrigemDaContratacao::Master === $linha->getOrigem();
    }
}
