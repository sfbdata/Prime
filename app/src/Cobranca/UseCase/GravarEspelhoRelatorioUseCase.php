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
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\Espelho\ReconciliacaoInternaFalhouException;
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
        private readonly LeitorEspelhoRelatorio $leitor,
        private readonly RelatorioImportadoRepository $relatorios,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(GravarEspelhoRelatorioInput $input): GravarEspelhoRelatorioOutput
    {
        $espelhado = $this->leitor->ler($input->caminhoArquivo);

        $this->exigirReconciliacaoInterna($espelhado);

        $jaLido = $this->relatorios->findOnePorHash(
            $input->carteira,
            $espelhado->arquivoHash,
            LeitorEspelhoRelatorio::VERSAO,
        );

        if ($jaLido !== null) {
            return $this->descrever($jaLido, $espelhado, jaExistia: true);
        }

        $relatorio = $this->em->wrapInTransaction(
            fn (): RelatorioImportado => $this->gravar($input, $espelhado)
        );

        return $this->descrever($relatorio, $espelhado, jaExistia: false);
    }

    /**
     * INV-G1 — o portão. Compara a soma das linhas de dado com o "Total de inadimplência" que a
     * própria planilha declara. Medido no TL1 de 12/08: as duas dão
     * `44.197.594 · 15.147.395 · 883.952 · 0 · 11.714.735 · 71.943.676`, ao centavo.
     */
    private function exigirReconciliacaoInterna(ArquivoEspelhado $espelhado): void
    {
        $rodape = $espelhado->totalGeral();

        if ($rodape === null) {
            throw new ReconciliacaoInternaFalhouException(sprintf(
                'A planilha "%s" não traz linha de total no rodapé — não há contra o que conferir. Nada foi gravado.',
                $espelhado->arquivoNome,
            ));
        }

        $soma = $espelhado->somarDados();
        $declarado = [
            'valor' => $rodape->valor ?? 0,
            'juros' => $rodape->juros ?? 0,
            'multa' => $rodape->multa ?? 0,
            'correcao' => $rodape->correcao ?? 0,
            'honorarios' => $rodape->honorarios ?? 0,
            'total' => $rodape->total ?? 0,
        ];

        if ($soma !== $declarado) {
            throw ReconciliacaoInternaFalhouException::comDivergencia(
                $espelhado->arquivoNome,
                $soma,
                $declarado,
            );
        }
    }

    private function gravar(GravarEspelhoRelatorioInput $input, ArquivoEspelhado $espelhado): RelatorioImportado
    {
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
            ->setLinhasTotalizador($contagem[BlocoRelatorio::Totalizador->value] ?? 0)
            ->setVersaoLeitor(LeitorEspelhoRelatorio::VERSAO)
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
            );
        }

        $this->em->flush();

        return $relatorio;
    }

    private function descrever(
        RelatorioImportado $relatorio,
        ArquivoEspelhado $espelhado,
        bool $jaExistia,
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
        );
    }
}
