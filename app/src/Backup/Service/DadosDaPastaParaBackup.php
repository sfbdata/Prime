<?php

declare(strict_types=1);

namespace App\Backup\Service;

use App\Cliente\Entity\Cliente;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O `dados.json` de uma pasta no backup: dados da pasta, partes, processos vinculados, andamentos, prazos,
 * mensagens e observações (spec backup, seção 4).
 *
 * Mensagens do sistema seguem `eventoVisivel`; andamentos (marcos do trilho) não têm aba e saem sempre.
 * Toda consulta leva `tenant_id` escrito na mão (o TenantFilter não liga no worker) e cada bloco respeita a aba
 * oculta de quem pediu, com o mesmo mapa da barreira da tela: Financeiro (valor da causa, contrato, pagamentos,
 * observações financeiras), Dados (partes), Processo (processos vinculados), Tarefas (prazos) e Detalhes
 * (observações). Só campos de negócio: nenhum id de outra tabela, nenhum dado de login.
 */
final class DadosDaPastaParaBackup
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<AbaDaPasta> $abasOcultas as abas que a pessoa não vê nesta pasta
     *
     * @return array<string, mixed>
     */
    public function montar(Pasta $pasta, Tenant $tenant, array $abasOcultas): array
    {
        $ocultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $abasOcultas);
        $oculta  = static fn (AbaDaPasta $aba): bool => in_array($aba->value, $ocultas, true);

        $dados = $this->cabecalho($pasta);
        if (!$oculta(AbaDaPasta::Dados)) {
            $dados['clientes_vinculados'] = $this->clientesVinculados($pasta, $tenant);
        }
        if (!$oculta(AbaDaPasta::Financeiro)) {
            $dados['financeiro'] = $this->financeiro($pasta, $tenant);
        }
        if (!$oculta(AbaDaPasta::Dados)) {
            $dados['partes'] = $this->partes($pasta, $tenant);
        }
        if (!$oculta(AbaDaPasta::Processo)) {
            $dados['processos'] = $this->processos($pasta, $tenant);
        }
        if (!$oculta(AbaDaPasta::Tarefas)) {
            $dados['prazos'] = $this->prazos($pasta, $tenant);
        }
        if (!$oculta(AbaDaPasta::Detalhes)) {
            $dados['observacoes_detalhes'] = $this->observacoes(PastaObservacaoDetalhes::class, $pasta, $tenant);
        }
        $dados['andamentos'] = $this->andamentos($pasta, $tenant);
        $dados['mensagens']  = $this->mensagens($pasta, $tenant, $ocultas);

        return $dados;
    }

    /** @return array<string, mixed> */
    private function cabecalho(Pasta $pasta): array
    {
        return [
            'nup'                => $pasta->getNup(),
            'cliente'            => $pasta->getNomeCliente(),
            'acao'               => $pasta->getNomeAcao(),
            'situacao'           => $pasta->getSituacao(),
            'prioridade'         => $pasta->getPrioridade()->value,
            'carteira'           => $pasta->getCarteira()?->value,
            'administrativa'     => $pasta->isAdministrativa(),
            'data_abertura'      => ValoresDoBackup::data($pasta->getDataAbertura()),
            'finalizada_em'      => ValoresDoBackup::data($pasta->getFinalizadaEm()),
            'prescricao_em'      => ValoresDoBackup::dia($pasta->getPrescricaoEm()),
            'unidade'            => $pasta->getUnidade(),
            'responsavel'        => $pasta->getResponsavel()?->getFullName(),
        ];
    }

    /** @return array<string, mixed> */
    private function financeiro(Pasta $pasta, Tenant $tenant): array
    {
        $pagamentos = $this->doTenant(PastaPagamento::class, $pasta, $tenant, 'e.vencimento, e.id');

        return [
            'valor_causa'       => $pasta->getValorCausa(),
            'contrato'          => $pasta->getContrato(),
            'situacao_contrato' => $pasta->getSituacaoContrato(),
            'pro_bono'          => $pasta->isProBono(),
            'pagamentos'        => array_map(static fn (PastaPagamento $p): array => [
                'descricao'   => $p->getDescricao(),
                'tipo'        => $p->getTipo()->value,
                'valor'       => $p->getValor(),
                'percentual'  => $p->getPercentual(),
                'juros_mensal' => $p->getJurosMensal(),
                'vencimento'  => ValoresDoBackup::dia($p->getVencimento()),
                'pago_em'     => ValoresDoBackup::dia($p->getPagoEm()),
            ], $pagamentos),
            'observacoes'       => $this->observacoes(PastaObservacaoFinanceira::class, $pasta, $tenant),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function partes(Pasta $pasta, Tenant $tenant): array
    {
        return array_map(static fn (PastaReu $r): array => [
            'nome'         => $r->getNome(),
            'documento'    => $r->getDocumento(),
            'qualificacao' => $r->getQualificacao(),
            'email'        => $r->getEmail(),
            'endereco'     => $r->getEndereco(),
        ], $this->doTenant(PastaReu::class, $pasta, $tenant, 'e.ordem, e.id'));
    }

    /** @return list<array<string, mixed>> */
    private function processos(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<PastaProcesso> $vinculos */
        $vinculos = $this->em->createQueryBuilder()
            ->select('pp', 'pr')->from(PastaProcesso::class, 'pp')
            ->join('pp.processo', 'pr')->join('pp.pasta', 'p')
            ->andWhere('p.id = :pasta')->andWhere('p.tenant = :tenant')->andWhere('pr.tenant = :tenant')
            ->setParameter('pasta', $pasta->getId())->setParameter('tenant', $tenant)
            ->orderBy('pp.id')->getQuery()->getResult();

        return array_map(static function (PastaProcesso $v): array {
            $processo = $v->getProcesso();

            return [
                'numero_processo' => $processo->getNumeroProcesso(),
                'tribunal'        => $processo->getSiglaTribunal(),
                'orgao_julgador'  => $processo->getOrgaoJulgador(),
                'classe'          => $processo->getClasseProcessual(),
                'situacao'        => $processo->getSituacaoProcesso(),
                'principal'       => $v->isPrincipal(),
                'tipo_apenso'     => $v->getTipoApenso()?->value,
                'vinculado_em'    => ValoresDoBackup::data($v->getVinculadoEm()),
            ];
        }, $vinculos);
    }

    /** @return list<array<string, mixed>> */
    private function andamentos(Pasta $pasta, Tenant $tenant): array
    {
        return array_map(static fn (PastaAndamento $a): array => [
            'via'           => $a->getVia(),
            'etapa_atual'   => $a->getAtual(),
            'marcos'        => $a->getMarcos(),
            'atualizado_em' => ValoresDoBackup::data($a->getAtualizadoEm()),
        ], $this->doTenant(PastaAndamento::class, $pasta, $tenant, 'e.id'));
    }

    /** @return list<array<string, mixed>> */
    private function prazos(Pasta $pasta, Tenant $tenant): array
    {
        return array_map(static fn (PastaPrazo $p): array => [
            'tipo'         => $p->getTipo(),
            'titulo'       => $p->getTitulo(),
            'data'         => ValoresDoBackup::dia($p->getData()),
            'hora'         => $p->getHora(),
            'responsavel'  => $p->getResponsavel(),
            'cumprido_em'  => ValoresDoBackup::data($p->getCumpridoEm()),
            'origem'       => $p->getOrigem(),
        ], $this->doTenant(PastaPrazo::class, $pasta, $tenant, 'e.data, e.id'));
    }

    /**
     * Mensagens e cartões do sistema da pasta. Como na timeline da tela (`PastaTimelineAssembler::semAbasOcultas`), a
     * mensagem segue a aba Dados e o cartão de pró-bono e contrato segue também a Financeiro
     * ({@see VisibilidadeDasAbasDaPasta::eventoVisivel()}).
     *
     * @param list<string> $abasOcultas
     *
     * @return list<array<string, mixed>>
     */
    private function mensagens(Pasta $pasta, Tenant $tenant, array $abasOcultas): array
    {
        /** @var list<PastaMensagem> $todas */
        $todas   = $this->doTenant(PastaMensagem::class, $pasta, $tenant, 'e.criadaEm, e.id');
        $visiveis = array_filter($todas, static fn (PastaMensagem $m): bool => VisibilidadeDasAbasDaPasta::eventoVisivel(
            in_array($m->getSistema(), RegistroDoSistemaNaPasta::DO_FINANCEIRO, true) ? 'registro_financeiro' : 'registro',
            $abasOcultas,
        ));

        return array_values(array_map(static fn (PastaMensagem $m): array => [
            'autor'      => $m->getAutor()?->getFullName(),
            'conteudo'   => $m->getConteudo(),
            'criada_em'  => ValoresDoBackup::data($m->getCriadaEm()),
            'editada_em' => ValoresDoBackup::data($m->getEditadaEm()),
            'do_sistema' => $m->isDoSistema(),
        ], $visiveis));
    }

    /**
     * Os clientes vinculados à pasta (só o nome), com escritório escrito na mão.
     *
     * @return list<string>
     */
    private function clientesVinculados(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<Cliente> $clientes */
        $clientes = $this->em->createQueryBuilder()
            ->select('c')->from(Cliente::class, 'c')
            ->join(Pasta::class, 'p', 'WITH', 'c MEMBER OF p.clientes')
            ->andWhere('p.id = :pasta')->andWhere('p.tenant = :tenant')->andWhere('c.tenant = :tenant')
            ->setParameter('pasta', $pasta->getId())->setParameter('tenant', $tenant)
            ->orderBy('c.id')->getQuery()->getResult();

        return array_map(static fn (Cliente $c): string => $c->getNomeExibicao(), $clientes);
    }

    /**
     * @param class-string<PastaObservacaoDetalhes|PastaObservacaoFinanceira> $classe
     *
     * @return list<array<string, mixed>>
     */
    private function observacoes(string $classe, Pasta $pasta, Tenant $tenant): array
    {
        return array_map(static fn (PastaObservacaoDetalhes|PastaObservacaoFinanceira $o): array => [
            'autor'      => $o->getAutor()?->getFullName(),
            'conteudo'   => $o->getConteudo(),
            'criada_em'  => ValoresDoBackup::data($o->getCriadaEm()),
            'editada_em' => ValoresDoBackup::data($o->getEditadaEm()),
        ], $this->doTenant($classe, $pasta, $tenant, 'e.criadaEm, e.id'));
    }

    /**
     * As linhas de uma entidade ligada à pasta, com pasta E escritório escritos na mão.
     *
     * @param class-string $classe
     *
     * @return list<object>
     */
    private function doTenant(string $classe, Pasta $pasta, Tenant $tenant, string $ordem): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')->from($classe, 'e')
            ->andWhere('e.pasta = :pasta')->andWhere('e.tenant = :tenant')
            ->setParameter('pasta', $pasta->getId())->setParameter('tenant', $tenant);
        foreach (explode(',', $ordem) as $campo) {
            $qb->addOrderBy(trim($campo));
        }

        return array_values($qb->getQuery()->getResult());
    }
}
