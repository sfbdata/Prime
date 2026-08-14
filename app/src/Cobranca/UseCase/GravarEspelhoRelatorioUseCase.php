<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\DTO\GravarEspelhoRelatorioOutput;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioLinha;
use App\Cobranca\Entity\RelatorioTotalizador;
use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\Espelho\ArquivoEspelhado;
use App\Cobranca\Service\Espelho\LeitorEspelhoAcordos;
use App\Cobranca\Service\Espelho\LeitoresDoEspelho;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guarda um relatório da contabilidade no espelho, como ele veio
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §4).
 *
 * **Quem dispara:** um comando de console — não é ação de tela.
 * **O que se quer:** ter no banco a cópia fiel do que a contabilidade cobrava naquela emissão, para
 * responder "o sistema está alinhado?" sem abrir o Excel na mão, que é como isso era feito até aqui.
 *
 * INV-G1: **a reconciliação interna é PORTÃO, não relatório.** Antes de gravar qualquer coisa, a
 * soma das linhas de dado tem que bater com o totalizador da própria planilha. Se não bate, o leitor
 * está errado e nada é gravado — um espelho torto é pior que espelho nenhum, porque tem cara de
 * verdade.
 *
 * INV-G2: **idempotente por (carteira, hash do arquivo, versão do leitor).** Ler o mesmo arquivo
 * duas vezes não duplica; ler com o leitor corrigido gera lote novo de propósito. É o que torna a
 * carga do histórico reexecutável.
 *
 * INV-G3: **não escreve uma linha em dado de dívida.** Só as três tabelas do espelho. Isto é
 * normativo na spec (§3), não conveniência — e há teste que tira foto do banco antes e depois para
 * provar.
 *
 * INV-G4: **o tenant vem da CARTEIRA, não do usuário logado.** O caminho é console, muitas vezes sem
 * usuário; a carteira é a raiz de posse do dado (`carteira → objeto → caso → obrigação`).
 */
final class GravarEspelhoRelatorioUseCase
{
    /**
     * Quantas entidades acumular antes de descarregar para o banco. O relatório maior medido tem
     * 4.207 linhas; sem descarregar em lotes o Doctrine segura todas na memória de uma vez.
     */
    private const TAMANHO_DO_LOTE = 500;

    public function __construct(
        private readonly LeitoresDoEspelho $leitores,
        private readonly RelatorioImportadoRepository $relatorios,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(GravarEspelhoRelatorioInput $input): GravarEspelhoRelatorioOutput
    {
        // O leitor sai do TIPO declarado na entrada, não de um leitor fixo. Antes da SPEC
        // quatro-relatórios havia um só, e era o de inadimplência — que é como os outros três
        // relatórios ficaram semanas fora do espelho enquanto os comandos imprimiam números com cara
        // de totais.
        $leitor = $this->leitores->paraTipo($input->tipo);

        $espelhado = $leitor->ler($input->caminhoArquivo);

        // INV-G1: o portão é do LAYOUT, não do gravador — cada leitor sabe contra o que o próprio
        // arquivo fecha (dinheiro na inadimplência e nas receitas, por aba nos acordos, estrutural no
        // cadastro). Ver {@see \App\Cobranca\Service\Espelho\LeitorDeEspelho::exigirReconciliacaoInterna()}.
        $leitor->exigirReconciliacaoInterna($espelhado);

        // A tolerância de rateio dos acordos só é segura se for VISÍVEL (SPEC §3.2, mesma regra do
        // INV-CB3). O portão já a aplicou; aqui ela sai do leitor para poder chegar à tela.
        $tolerancia = $leitor instanceof LeitorEspelhoAcordos
            ? $leitor->toleranciaConsumida($espelhado)
            : null;

        $jaLido = $this->relatorios->findOnePorHash(
            $input->carteira,
            $espelhado->arquivoHash,
            $leitor->versao(),
            $input->tipo,
        );

        if ($jaLido !== null) {
            return $this->descrever($jaLido, $espelhado, jaExistia: true, tolerancia: $tolerancia);
        }

        $relatorio = $this->em->wrapInTransaction(
            fn (): RelatorioImportado => $this->gravar($input, $espelhado, $leitor->versao())
        );

        return $this->descrever($relatorio, $espelhado, jaExistia: false, tolerancia: $tolerancia);
    }

    private function gravar(
        GravarEspelhoRelatorioInput $input,
        ArquivoEspelhado $espelhado,
        int $versaoDoLeitor,
    ): RelatorioImportado {
        $tenant = $input->carteira->getTenant();

        if ($tenant === null) {
            throw new \LogicException('Carteira sem tenant — o espelho não teria a quem pertencer.');
        }

        $contagem = $espelhado->contarPorBloco();

        $relatorio = (new RelatorioImportado())
            ->setTenant($tenant)
            ->setCarteira($input->carteira)
            ->setTipo($input->tipo)
            ->setArquivoNome($espelhado->arquivoNome)
            ->setArquivoHash($espelhado->arquivoHash)
            ->setEmitidoEm($espelhado->emitidoEm)
            ->setDadosAte($espelhado->dadosAte)
            ->setConfigDeclarada($espelhado->configDeclarada)
            ->setUnidadesDeclaradas($espelhado->unidadesDeclaradas)
            ->setLinhasTotal($espelhado->linhasTotal)
            ->setLinhasDados($contagem[BlocoRelatorio::Dados->value] ?? 0)
            // Conta os totalizadores EXTRAÍDOS, não o balde de linhas. Nos acordos o "total" de uma
            // aba é um par `Rótulo: valor` dentro do cabeçalho — nenhuma linha cai no balde
            // `Totalizador`, e contar o balde reportava 0 num lote com 392 totalizadores gravados.
            ->setLinhasTotalizador(count($espelhado->totalizadores))
            ->setVersaoLeitor($versaoDoLeitor)
            ->setLidoPor($input->lidoPor);

        $this->em->persist($relatorio);

        $pendentes = 0;

        foreach ($espelhado->linhas as $linha) {
            $this->em->persist(
                (new RelatorioLinha())
                    ->setTenant($tenant)
                    ->setRelatorio($relatorio)
                    ->setNumeroLinha($linha->numeroLinha)
                    ->setBloco($linha->bloco)
                    ->setUnidade($linha->unidade)
                    ->setSacado($linha->sacado)
                    ->setNn($linha->nn)
                    ->setClasse($linha->classe)
                    ->setCompetencia($linha->competencia)
                    ->setVencimento($linha->vencimento)
                    ->setAtraso($linha->atraso)
                    ->setValor($linha->valor)
                    ->setJuros($linha->juros)
                    ->setMulta($linha->multa)
                    ->setCorrecao($linha->correcao)
                    ->setHonorarios($linha->honorarios)
                    ->setTotal($linha->total)
                    ->setAcordoTexto($linha->acordoTexto)
                    ->setRecebimento($linha->recebimento)
                    ->setBruto($linha->bruto)
                    // Campos dos três layouts novos — `null` na inadimplência (SPEC §4.3).
                    ->setAba($linha->aba)
                    ->setTabela($linha->tabela)
                    ->setParcela($linha->parcela)
                    ->setLiquidacao($linha->liquidacao)
                    ->setValorRecebido($linha->valorRecebido)
            );

            if (++$pendentes % self::TAMANHO_DO_LOTE === 0) {
                $this->em->flush();
            }
        }

        foreach ($espelhado->totalizadores as $totalizador) {
            $this->em->persist(
                (new RelatorioTotalizador())
                    ->setTenant($tenant)
                    ->setRelatorio($relatorio)
                    ->setNumeroLinha($totalizador->numeroLinha)
                    ->setForma($totalizador->forma)
                    ->setRotulo($totalizador->rotulo)
                    ->setValor($totalizador->valor)
                    ->setJuros($totalizador->juros)
                    ->setMulta($totalizador->multa)
                    ->setCorrecao($totalizador->correcao)
                    ->setHonorarios($totalizador->honorarios)
                    ->setTotal($totalizador->total)
                    ->setValorRecebido($totalizador->valorRecebido)
            );
        }

        $this->em->flush();

        return $relatorio;
    }

    /**
     * @param array{abas: int, centavos: int}|null $tolerancia
     */
    private function descrever(
        RelatorioImportado $relatorio,
        ArquivoEspelhado $espelhado,
        bool $jaExistia,
        ?array $tolerancia = null,
    ): GravarEspelhoRelatorioOutput {
        return new GravarEspelhoRelatorioOutput(
            relatorioId: $relatorio->getId() ?? 0,
            jaExistia: $jaExistia,
            arquivoNome: $relatorio->getArquivoNome(),
            arquivoHash: $relatorio->getArquivoHash(),
            emitidoEm: $relatorio->getEmitidoEm(),
            dadosAte: $relatorio->getDadosAte(),
            configDeclarada: $relatorio->getConfigDeclarada(),
            linhasTotal: $relatorio->getLinhasTotal(),
            linhasDados: $relatorio->getLinhasDados(),
            linhasTotalizador: $relatorio->getLinhasTotalizador(),
            linhasPorBloco: $espelhado->contarPorBloco(),
            toleranciaDeRateio: $tolerancia,
        );
    }
}
