<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacao;
use App\Cobranca\Service\Importacao\ResultadoLeitura;
use App\Cobranca\Service\NormalizadorNome;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa em lote um relatório já LIDO por um adapter específico (SPEC §21), sempre dentro de UMA
 * Carteira escolhida explicitamente. Reusa os UseCases do cadastro manual (mesmas regras de negócio) e
 * é IDEMPOTENTE: reimportar o mesmo relatório atualiza em vez de duplicar (chave = Carteira+Objeto+NN).
 *
 * Fonte-agnóstico: recebe `ResultadoLeitura` (boletos já normalizados para o domínio); não sabe que a
 * origem era uma planilha. Decisões de mapeamento em `docs/gestao-cobrancas/MAPEAMENTO_FONTE_TOPLIFE.md`.
 *
 * Por boleto, na ordem: resolve/cria Objeto (dedup por identificação na Carteira) → resolve/cria Pessoa
 * cobrada (por nome no Objeto — decisão A) e Caso ativo → resolve/cria/atualiza Obrigação (dedup por NN).
 * Encargos entram SEPARADOS (juros/multa/correção) e a obrigação nasce CONGELADA — o relatório da
 * contabilidade é a verdade e o cron de materialização não a sobrescreve (spec §9). Honorários do
 * relatório passam a ser persistidos, fora do valor exigível (§4.2/INV-E2). NUNCA cruza tenants.
 *
 * Linhas de acordo (spec `cobranca-importar-linhas-acordo.md` §3.2, tarefa #7-B): quando o adapter
 * reconhece "Acordo N - Parc. p/t" na coluna do relatório (`BoletoImportavel::$acordo`), o NN vira uma
 * PARCELA de um Acordo de verdade, não uma obrigação solta — ver `resolverOuCriarAcordo()`.
 */
final class ImportarRelatorioCarteiraUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly CriarObjetoUseCase $criarObjeto,
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly VincularPessoaAObjetoUseCase $vincular,
        private readonly AbrirCasoUseCase $abrirCaso,
        private readonly RegistrarObrigacaoUseCase $registrarObrigacao,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Dry-run: projeta o que ACONTECERIA (SPEC §21 — análise/validação antes da confirmação), sem
     * persistir nada. Read-only: só consulta existência de objeto/caso/obrigação.
     */
    public function prever(int $carteiraId, ResultadoLeitura $leitura, Tenant $tenant): ResultadoImportacao
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        $criadas = [];
        $atualizadas = [];
        $divergentes = [];
        $objetosNovos = [];
        $temCasoPorObjeto = [];   // identificacao => bool (caso ativo real ou simulado nesta prévia)
        $nomesPorObjeto = [];     // identificacao => list<string> nomes normalizados já representados
        $pessoasNovas = 0;
        $casosNovos = 0;

        foreach ($leitura->importaveis as $boleto) {
            $identif = $boleto->objetoIdentificacao;
            $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $identif, $tenant);
            if ($objeto === null && !isset($objetosNovos[$identif])) {
                $objetosNovos[$identif] = true;
            }

            $caso = $objeto !== null ? ($this->casoRepository->casosAtivosDoObjeto($objeto)[0] ?? null) : null;
            if (!isset($nomesPorObjeto[$identif])) {
                $nomesPorObjeto[$identif] = $this->nomesRepresentadosNoObjeto($objeto, $caso);
                $temCasoPorObjeto[$identif] = $caso !== null;
            }

            $nomeBoleto = NormalizadorNome::normalizar($boleto->sacadoNome);
            if ($temCasoPorObjeto[$identif] === false) {
                ++$casosNovos;
                ++$pessoasNovas;
                $temCasoPorObjeto[$identif] = true;
                if ($nomeBoleto !== null) {
                    $nomesPorObjeto[$identif][] = $nomeBoleto;
                }
            } elseif ($nomeBoleto !== null && !in_array($nomeBoleto, $nomesPorObjeto[$identif], true)) {
                ++$pessoasNovas;
                $divergentes[] = $identif;
                $nomesPorObjeto[$identif][] = $nomeBoleto;
            }

            $jaExiste = $caso !== null
                && $this->obrigacaoRepository->findOnePorReferenciaExternaNoCaso($caso, $boleto->nn) !== null;
            if ($jaExiste) {
                $atualizadas[] = $boleto->nn;

                continue;
            }

            $criadas[] = $boleto->nn;
        }

        return new ResultadoImportacao($criadas, $atualizadas, $leitura->rejeitadas, $leitura->linhasIgnoradas, count($objetosNovos), $pessoasNovas, $casosNovos, $divergentes);
    }

    /**
     * Persiste a importação numa transação única (ou tudo, ou nada). Idempotente na reimportação.
     *
     * Data de referência do congelamento (spec §9 pede "a data do relatório"): a fonte lida hoje NÃO
     * carrega essa data — `ResultadoLeitura`/`BoletoImportavel` não a expõem e inventar um campo aqui
     * seria mudar contrato compartilhado. Usa-se então o momento da importação, capturado UMA vez para
     * o lote inteiro, de modo que todos os boletos do mesmo relatório fiquem com a mesma referência
     * (importante para auditoria: um lote = uma data, não N timestamps espalhados pelo loop).
     */
    public function confirmar(int $carteiraId, ResultadoLeitura $leitura, Tenant $tenant, User $user): ResultadoImportacao
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);
        $referencia = new \DateTimeImmutable();

        return $this->em->wrapInTransaction(function () use ($carteira, $leitura, $tenant, $user, $referencia): ResultadoImportacao {
            $criadas = [];
            $atualizadas = [];
            $divergentes = [];
            $objetosCriados = 0;
            $pessoasCriadas = 0;
            $casosCriados = 0;

            foreach ($leitura->importaveis as $boleto) {
                $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $boleto->objetoIdentificacao, $tenant);
                if ($objeto === null) {
                    $objeto = $this->criarObjeto->executar($this->objetoInput($carteira, $boleto), $tenant, $user);
                    ++$objetosCriados;
                }

                $caso = $this->casoRepository->casosAtivosDoObjeto($objeto)[0] ?? null;
                if ($caso === null) {
                    $pessoa = $this->criarPessoa->executar($this->pessoaInput($boleto), $tenant, $user);
                    ++$pessoasCriadas;
                    $this->vincular->executar($this->vinculoInput($pessoa->getId(), $objeto->getId(), $carteira), $tenant, $user);
                    $caso = $this->abrirCaso->executar($this->casoInput($objeto->getId(), $pessoa->getId()), $tenant, $user);
                    ++$casosCriados;
                } elseif (!$this->sacadoJaRepresentadoNoObjeto($objeto, $caso, $boleto->sacadoNome)) {
                    // Decisão A: sacado NOVO no MESMO objeto → nova Pessoa + vínculo. Idempotente: só cria
                    // se ainda não há Pessoa com esse nome vinculada ao objeto (reimport reusa). NÃO troca
                    // a pessoa cobrada automaticamente (decisão jurídica sensível é humana — §28); só reporta.
                    $pessoa = $this->criarPessoa->executar($this->pessoaInput($boleto), $tenant, $user);
                    ++$pessoasCriadas;
                    $this->vincular->executar($this->vinculoInput($pessoa->getId(), $objeto->getId(), $carteira), $tenant, $user);
                    $divergentes[] = $boleto->objetoIdentificacao;
                }

                // Linha de acordo (§3.2): acha/cria o Acordo por (carteira + número) ANTES de resolver a
                // obrigação — a parcela precisa apontar pra ele nos dois ramos (nova ou reimportação).
                $acordo = $boleto->acordo !== null
                    ? $this->resolverOuCriarAcordo($carteira, $caso, $boleto, $tenant, $user)
                    : null;

                $obrigacao = $this->obrigacaoRepository->findOnePorReferenciaExternaNoCaso($caso, $boleto->nn);
                if ($obrigacao === null) {
                    $obrigacao = $this->registrarObrigacao->executar($this->obrigacaoInput($caso->getId(), $boleto), $tenant, $user);
                    if ($acordo !== null) {
                        $obrigacao->setAcordoOrigem($acordo);
                        $this->obrigacaoRepository->salvar($obrigacao, true);
                    }
                    $this->materializarEncargosImportados($obrigacao, $boleto, $referencia);
                    $criadas[] = $boleto->nn;

                    continue;
                }

                // Idempotência (§3.2.4): a parcela já existe (dedup por NN) — garante que ela aponta pro
                // acordo achado/criado acima (cobre a obrigação legada de uma 1ª importação anterior a
                // este ajuste, que nasceu sem acordoOrigem).
                if ($acordo !== null && $obrigacao->getAcordoOrigem() !== $acordo) {
                    $obrigacao->setAcordoOrigem($acordo);
                    $this->obrigacaoRepository->salvar($obrigacao, true);
                }

                // Reimportação idempotente: atualiza SÓ os encargos ao snapshot novo e RE-CONGELA na data
                // nova (spec §9). Preserva o valorOriginal (invariável 20) e não duplica.
                $this->materializarEncargosImportados($obrigacao, $boleto, $referencia);
                $atualizadas[] = $boleto->nn;
            }

            return new ResultadoImportacao($criadas, $atualizadas, $leitura->rejeitadas, $leitura->linhasIgnoradas, $objetosCriados, $pessoasCriadas, $casosCriados, $divergentes);
        });
    }

    /**
     * Grava os encargos do relatório SEPARADOS (juros/multa/correção/honorários) como o valor INICIAL da
     * obrigação (cache). Encargos AO VIVO (D6): NÃO congela mais — a obrigação nasce VIVA e a leitura
     * recalcula (vencimento → hoje × taxa). A fórmula reproduz ao centavo os números da contabilidade
     * (spec §2), então, com a carteira configurada, o vivo bate com o importado; sem isso, os valores
     * importados servem de partida até a primeira hidratação. Honorários são persistidos (§4.2): NÃO
     * afetam o saldo (`valorExigivel()` = valorOriginal + juros + multa + correção, INV-E2).
     */
    private function materializarEncargosImportados(Obrigacao $obrigacao, BoletoImportavel $boleto, \DateTimeImmutable $referencia): void
    {
        $obrigacao->definirEncargos(
            $boleto->jurosCentavos,
            $boleto->multaCentavos,
            $boleto->correcaoCentavos,
            $boleto->honorariosInformadosCentavos,
            $referencia,
        );
        $this->obrigacaoRepository->salvar($obrigacao, true);
    }

    /**
     * Acha o Acordo por (carteira + número externo) ou cria um novo (§3.2.2). NÃO usa
     * `CriarAcordoUseCase`: aquele valida fechamento (INV-B) e obrigações substituídas — regras da
     * criação MANUAL, que não fazem sentido aqui (o import não substitui nada, só materializa a
     * parcela que o relatório já traz pronta). O acordo pertence ao CASO do objeto.
     *
     * Idempotência (§3.2.4): reimportar reusa o mesmo Acordo (achado pela dedup); se ele nasceu antes
     * de se saber o total de parcelas (ou a 1ª leitura não trouxe `parcelaTotal`), completa quando um
     * total aparece.
     */
    private function resolverOuCriarAcordo(Carteira $carteira, CasoCobranca $caso, BoletoImportavel $boleto, Tenant $tenant, User $user): Acordo
    {
        $doRelatorio = $boleto->acordo;
        if ($doRelatorio === null) {
            // Defesa: só é chamado quando o chamador já checou `$boleto->acordo !== null`.
            throw new \LogicException('resolverOuCriarAcordo chamado sem acordo reconhecido no boleto.');
        }

        $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($doRelatorio->numero, $carteira, $tenant);
        if ($acordo === null) {
            $acordo = new Acordo();
            $acordo->setTenant($tenant);
            $acordo->setCaso($caso);
            $acordo->setStatus(StatusAcordo::Ativo);
            $acordo->setDataAcordo($this->dataAcordoPadrao($boleto));
            $acordo->setNumeroExterno($doRelatorio->numero);
            $acordo->setNumeroParcelasTotal($doRelatorio->parcelaTotal);
            $acordo->setCriadoPor($user);
            $this->acordoRepository->salvar($acordo, true);

            return $acordo;
        }

        if ($acordo->getNumeroParcelasTotal() === null && $doRelatorio->parcelaTotal > 0) {
            $acordo->setNumeroParcelasTotal($doRelatorio->parcelaTotal);
            $this->acordoRepository->salvar($acordo, true);
        }

        return $acordo;
    }

    /**
     * Default de `dataAcordo` (§3.2.2, nota de implementação #7-B): o relatório de inadimplência NÃO
     * traz a data do acordo. Preferência: 1º dia da COMPETÊNCIA do boleto — mais estável do que o
     * vencimento de UMA parcela quando o acordo tem várias (a competência tende a ser a mesma faixa
     * para as parcelas vizinhas). Fallback ao vencimento da parcela (defensivo: o adapter atual só
     * entrega boletos com competência MM/AAAA válida — `montarBoleto()` rejeita o resto —, então este
     * ramo não deveria disparar na prática, mas nada garante isso de um adapter futuro).
     */
    private function dataAcordoPadrao(BoletoImportavel $boleto): \DateTimeImmutable
    {
        if (preg_match('#^(\d{2})/(\d{4})$#', $boleto->competencia, $m) === 1) {
            return new \DateTimeImmutable(sprintf('%s-%s-01', $m[2], $m[1]));
        }

        return $boleto->vencimento;
    }

    /**
     * O sacado do boleto já é uma Pessoa representada no objeto? Casa se for a pessoa cobrada atual OU
     * qualquer Pessoa já vinculada ao objeto (por nome normalizado). Garante que a reimportação de um
     * sacado divergente NÃO recrie a Pessoa toda vez (idempotência da decisão A). Sempre intra-tenant.
     */
    private function sacadoJaRepresentadoNoObjeto(ObjetoCobranca $objeto, CasoCobranca $caso, string $sacado): bool
    {
        $alvo = NormalizadorNome::normalizar($sacado);

        return in_array($alvo, $this->nomesRepresentadosNoObjeto($objeto, $caso), true);
    }

    /**
     * Nomes normalizados já representados no objeto: a pessoa cobrada do caso + as pessoas vinculadas.
     *
     * @return list<string>
     */
    private function nomesRepresentadosNoObjeto(?ObjetoCobranca $objeto, ?CasoCobranca $caso): array
    {
        $nomes = [];
        if ($caso !== null) {
            $cobrada = NormalizadorNome::normalizar($caso->getPessoaCobradaAtual()?->getNome());
            if ($cobrada !== null) {
                $nomes[] = $cobrada;
            }
        }
        if ($objeto !== null) {
            foreach ($this->vinculoRepository->pessoasVinculadasAoObjeto($objeto) as $pessoa) {
                $nome = NormalizadorNome::normalizar($pessoa->getNome());
                if ($nome !== null && !in_array($nome, $nomes, true)) {
                    $nomes[] = $nome;
                }
            }
        }

        return $nomes;
    }

    private function resolverCarteira(int $carteiraId, Tenant $tenant): Carteira
    {
        $carteira = $this->carteiraRepository->findOneByIdDoTenant($carteiraId, $tenant);
        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException($carteiraId);
        }

        return $carteira;
    }

    private function objetoInput(Carteira $carteira, BoletoImportavel $boleto): CriarObjetoInput
    {
        $input = new CriarObjetoInput();
        $input->carteiraId = $carteira->getId();
        $input->identificacao = $boleto->objetoIdentificacao;
        $input->descricao = $boleto->unidadeMetadata;
        $input->referenciaExterna = $boleto->objetoIdentificacao;

        return $input;
    }

    private function pessoaInput(BoletoImportavel $boleto): CriarPessoaInput
    {
        $input = new CriarPessoaInput();
        $input->nome = $boleto->sacadoNome;

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

    private function obrigacaoInput(?int $casoId, BoletoImportavel $boleto): RegistrarObrigacaoInput
    {
        $descricao = $boleto->descricao();
        $observacao = $boleto->observacao();
        if ($observacao !== null) {
            $descricao .= ' | ' . $observacao;
        }

        $input = new RegistrarObrigacaoInput();
        $input->casoId = $casoId;
        $input->descricao = mb_substr($descricao, 0, 255);
        $input->vencimentoOriginal = $boleto->vencimento;
        $input->referenciaExterna = $boleto->nn;

        if ($boleto->acordo !== null) {
            // Parcela de acordo (§3.2.3): valorOriginal É o principal NEGOCIADO — soma da coluna Valor
            // de TODAS as linhas do NN (o honorário do relatório já está embutido nela, decisão do
            // dono). NUNCA `principalCentavos` (que só soma classes 1.1/1.14/1.6): usar o principal
            // "comum" aqui subestimaria o valor negociado quando o relatório lista juros/multa/honorário
            // como linhas próprias dentro do NN. Honorários = 0 (decisão #8: acordo não cobra
            // honorário sobre honorário) via override de taxa — ao vivo, sem congelar. Juros/multa/
            // correção continuam herdando a cascata Carteira→Caso (crescem ao vivo a partir do
            // vencimento da parcela).
            $input->valorOriginal = $boleto->somaColunaValorCentavos;
            $input->modoHonorarios = 'percent';
            $input->honorariosBp = 0;
        } else {
            $input->valorOriginal = $boleto->principalCentavos;
        }

        return $input;
    }
}
