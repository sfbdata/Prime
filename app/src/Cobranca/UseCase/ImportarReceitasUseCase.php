<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\Importacao\EstadoDaImportacaoDeReceitas;
use App\Cobranca\Service\Importacao\ReceitaImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacaoReceitas;
use App\Cobranca\Service\Importacao\ResultadoLeituraReceitas;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa o relatório "Receitas detalhadas por unidade/cliente" — o 4º e último da contábil L.G (spec
 * `docs/specs/cobranca-importar-receitas.md`). É o relatório que responde **o que foi pago**; os outros
 * três só dizem o que é devido.
 *
 * 🔑 **Por que ele CRIA obrigação (decisão R1 do dono).** Medido nos arquivos de 03/08: dos 2.078 NNs
 * com recebimento, apenas **80 (3,8%)** existem como obrigação, e dos **106 acordos** citados, **zero**.
 * Não é defeito do dado: o sistema só conhece o que a *Inadimplência* traz, e ela só traz o que **não
 * foi pago** — boleto pago sai daquele relatório e nunca entrou aqui. Os dois conjuntos são quase
 * disjuntos por construção. Casar só com o que já existe pousaria em 4% e reportaria o resto; criar o
 * que falta é o que dá ao escritório o panorama do ano e o que dá base à reativação por importação.
 *
 * A obrigação criada nasce **liquidada na data do recebimento**, com o principal e os encargos que a
 * planilha diz terem sido pagos — então ela entra e sai da conta no mesmo valor e **o saldo não se
 * move** por causa dela.
 *
 * Idempotência: a chave é `(obrigação, data de recebimento)`. Reimportar o mesmo arquivo não cria
 * pagamento nenhum na segunda vez.
 *
 * ⚠️ A PRÉVIA carrega ESTADO INTRA-EXECUÇÃO. Dois recebimentos do mesmo objeto não podem contar "objeto
 * criado" duas vezes, e o segundo recebimento de um NN tem de enxergar o primeiro. Prévia que só
 * consulta o banco mente — nesta frente já mentiu duas vezes.
 */
final class ImportarReceitasUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly CriarObjetoUseCase $criarObjeto,
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly VincularPessoaAObjetoUseCase $vincular,
        private readonly AbrirCasoUseCase $abrirCaso,
        private readonly RegistrarObrigacaoUseCase $registrarObrigacao,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Dry-run: projeta o que ACONTECERIA, sem escrever nada. Mesma estrutura de saída da confirmação —
     * é o que permite conferir os 13 campos um a um, e não por amostra.
     */
    public function prever(int $carteiraId, ResultadoLeituraReceitas $leitura, Tenant $tenant): ResultadoImportacaoReceitas
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);
        $estado = new EstadoDaImportacaoDeReceitas();

        foreach ($leitura->receitas as $receita) {
            $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $receita->objetoIdentificacao, $tenant);
            $caso = $objeto === null ? null : ($this->casoRepository->casosAtivosDoObjeto($objeto)[0] ?? null);

            // ESTADO: o mesmo objeto/caso aparece em vários recebimentos do arquivo. Sem isto, a prévia
            // contaria uma criação por linha e prometeria um número que a confirmação não entrega.
            $estado->projetarObjetoECaso($receita->objetoIdentificacao, $objeto !== null, $caso !== null);

            $obrigacao = $caso === null
                ? null
                : $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $receita->nn, $receita->competencia);

            $jaImportado = $obrigacao !== null
                && $this->alocacaoRepository->existeNaObrigacaoComData((int) $obrigacao->getId(), $receita->recebimento, $tenant);

            $estado->projetarRecebimento($receita, $obrigacao !== null, $jaImportado);
        }

        return $estado->resultado($leitura);
    }

    /** Persiste. Transação única: ou o arquivo inteiro entra, ou nada entra. */
    public function confirmar(int $carteiraId, ResultadoLeituraReceitas $leitura, Tenant $tenant, User $user): ResultadoImportacaoReceitas
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        return $this->em->wrapInTransaction(function () use ($carteira, $leitura, $tenant, $user): ResultadoImportacaoReceitas {
            $estado = new EstadoDaImportacaoDeReceitas();

            foreach ($leitura->receitas as $receita) {
                $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $receita->objetoIdentificacao, $tenant);
                $objetoExistia = $objeto !== null;
                if ($objeto === null) {
                    $objeto = $this->criarObjeto->executar($this->objetoInput($carteira, $receita), $tenant, $user);
                }

                $caso = $this->casoRepository->casosAtivosDoObjeto($objeto)[0] ?? null;
                $casoExistia = $caso !== null;
                if ($caso === null) {
                    $pessoa = $this->criarPessoa->executar($this->pessoaInput($receita), $tenant, $user);
                    $this->vincular->executar($this->vinculoInput($pessoa->getId(), $objeto->getId(), $carteira), $tenant, $user);
                    $caso = $this->abrirCaso->executar($this->casoInput($objeto->getId(), $pessoa->getId()), $tenant, $user);
                }
                $estado->projetarObjetoECaso($receita->objetoIdentificacao, $objetoExistia, $casoExistia);

                $obrigacao = $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $receita->nn, $receita->competencia);
                $obrigacaoExistia = $obrigacao !== null;

                if ($obrigacao !== null
                    && $this->alocacaoRepository->existeNaObrigacaoComData((int) $obrigacao->getId(), $receita->recebimento, $tenant)) {
                    // Reimportação do mesmo arquivo: nada a fazer. É a garantia de que rodar duas vezes
                    // não duplica dinheiro.
                    $estado->projetarRecebimento($receita, true, true);

                    continue;
                }

                if ($obrigacao === null) {
                    $obrigacao = $this->criarObrigacaoJaPaga($caso, $receita, $tenant, $user);
                }

                $this->registrarRecebimento($caso, $obrigacao, $receita, $tenant, $user);
                $estado->projetarRecebimento($receita, $obrigacaoExistia, false);
            }

            $this->em->flush();

            return $estado->resultado($leitura);
        });
    }

    /**
     * Cria a obrigação que o sistema nunca conheceu (R1) e a marca liquidada NA DATA DO RECEBIMENTO,
     * com o principal e os encargos que a planilha diz terem sido pagos.
     *
     * Ela nasce liquidada de propósito: `valorExigivel()` fica igual ao que o pagamento aloca, então a
     * obrigação entra e sai da conta no mesmo valor e **o saldo do caso não se move**. Congelada, ela
     * também não volta a crescer — é história, não dívida viva.
     */
    private function criarObrigacaoJaPaga(CasoCobranca $caso, ReceitaImportavel $receita, Tenant $tenant, User $user): Obrigacao
    {
        $input = new RegistrarObrigacaoInput();
        $input->casoId = $caso->getId();
        $input->descricao = mb_substr($receita->descricao(), 0, 255);
        $input->valorOriginal = $receita->valorDividaCentavos;
        $input->vencimentoOriginal = $receita->vencimento;
        $input->referenciaExterna = $receita->nn;
        $input->competencia = $receita->competencia;
        // Encargos vêm da planilha, não da cascata da carteira: esta obrigação já foi paga e o que
        // vale é o que a contabilidade cobrou, não o que o motor calcularia hoje.
        $input->honorariosBp = 0;

        $obrigacao = $this->registrarObrigacao->executar($input, $tenant, $user);

        $obrigacao->liquidar(
            $receita->valorJurosCentavos,
            $receita->valorMultaCentavos,
            0,
            $receita->valorHonorariosCentavos,
            $receita->recebimento,
        );
        $this->obrigacaoRepository->salvar($obrigacao);

        return $obrigacao;
    }

    /** Cria o `Pagamento` do recebimento e a alocação que o amarra à obrigação. */
    private function registrarRecebimento(
        CasoCobranca $caso,
        Obrigacao $obrigacao,
        ReceitaImportavel $receita,
        Tenant $tenant,
        User $user,
    ): void {
        $pagamento = new Pagamento();
        $pagamento->setTenant($tenant);
        $pagamento->setCaso($caso);
        $pagamento->setData($receita->recebimento);
        $pagamento->setValorDivida($receita->valorDividaCentavos);
        $pagamento->setValorEncargos($receita->valorEncargosCentavos());
        $pagamento->setValorHonorarios($receita->valorHonorariosCentavos);
        $pagamento->setCriadoPor($user);

        $alocacao = new AlocacaoPagamento();
        $alocacao->setTenant($tenant);
        $alocacao->setObrigacao($obrigacao);
        $alocacao->setValor($receita->recuperadoDividaCentavos());
        $pagamento->adicionarAlocacao($alocacao);

        $this->pagamentoRepository->salvar($pagamento);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::PagamentoRegistrado,
            $user,
            sprintf('Recebimento importado: R$ %s de %s (NN %s)',
                number_format($receita->totalRecebidoCentavos() / 100, 2, ',', '.'),
                $receita->recebimento->format('d/m/Y'),
                $receita->nn,
            ),
            [
                'nn' => $receita->nn,
                'competencia' => $receita->competencia,
                'data' => $receita->recebimento->format('Y-m-d'),
                'valorDivida' => $receita->valorDividaCentavos,
                'valorEncargos' => $receita->valorEncargosCentavos(),
                'valorHonorarios' => $receita->valorHonorariosCentavos,
                'origem' => 'importacao_receitas',
            ],
        );
    }

    private function resolverCarteira(int $carteiraId, Tenant $tenant): Carteira
    {
        $carteira = $this->carteiraRepository->findOneByIdDoTenant($carteiraId, $tenant);
        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException($carteiraId);
        }

        return $carteira;
    }

    private function objetoInput(Carteira $carteira, ReceitaImportavel $receita): CriarObjetoInput
    {
        $input = new CriarObjetoInput();
        $input->carteiraId = $carteira->getId();
        $input->identificacao = $receita->objetoIdentificacao;
        $input->descricao = $receita->unidadeMetadata;
        $input->referenciaExterna = $receita->objetoIdentificacao;

        return $input;
    }

    private function pessoaInput(ReceitaImportavel $receita): CriarPessoaInput
    {
        $input = new CriarPessoaInput();
        $input->nome = $receita->sacadoNome;

        return $input;
    }

    private function vinculoInput(?int $pessoaId, ?int $objetoId, Carteira $carteira): VincularPessoaAObjetoInput
    {
        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = $pessoaId;
        $input->objetoId = $objetoId;
        $input->tipoVinculo = $carteira->getTipoVinculoPreferido() ?? TipoVinculo::Outro;

        return $input;
    }

    private function casoInput(?int $objetoId, ?int $pessoaId): AbrirCasoInput
    {
        $input = new AbrirCasoInput();
        $input->objetoId = $objetoId;
        $input->pessoaCobradaId = $pessoaId;

        return $input;
    }
}
