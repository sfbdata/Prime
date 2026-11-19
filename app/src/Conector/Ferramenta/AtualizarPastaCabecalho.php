<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\EnvioAutomaticoDaPasta;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\CarteiraDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\UseCase\AlterarPrioridadeUseCase;
use App\Pasta\UseCase\AlterarResponsavelDaPastaUseCase;
use App\Pasta\UseCase\DefinirSituacaoDaPastaUseCase;
use App\Pasta\UseCase\MoverPastaDeCarteiraUseCase;
use App\Repository\UserRepository;

/**
 * O cabeçalho da pasta pela `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6): `situacao`, `responsavel`,
 * `prioridade` e `carteira`. Todas escolhem numa lista fechada e gravam direto (abertura da 6.6), e todas pedem o recurso
 * `pasta` de editar, como as rotas da tela (`pasta_alternar_situacao`, `pasta_atualizar_responsavel`,
 * `pasta_alterar_prioridade`, e o UseCase de `pasta_mover_carteira`). Pedir o que a pasta já tem recusa sem gravar.
 *
 * - `situacao` para Ativo recusa quando o cliente principal tem o envio automático ligado ({@see EnvioAutomaticoDaPasta}).
 *   Arquivar leva o andamento a Finalizada e pode marcar o contrato do Financeiro como assinado, mesmo para quem não o
 *   vê; a resposta fala do contrato só para quem vê o Financeiro da pasta (6.2, regra 5), como a `mudar_andamento`.
 * - `responsavel` só aceita colega com vínculo ATIVO no escritório (`UserRepository::findColaboradorAtivoPorIdETenant`);
 *   `responsavel_id` nulo tira o responsável.
 *
 * Nenhuma destas ações propõe: {@see reconferir()} e {@see executarConfirmada()} recusam.
 */
final class AtualizarPastaCabecalho implements AtualizarPastaGrupo
{
    public const RECUSA = 'O BlueJus recusou a mudança da pasta. Confira o valor pedido e tente de novo.';
    public const RESPONSAVEL_INVALIDO = 'Responsável precisa ser um colega ativo do escritório.';
    public const CONTRATO_ASSINADO = 'O contrato do Financeiro foi marcado como assinado.';
    public const ANDAMENTO_FINALIZADO = 'O andamento da pasta foi para Finalizada, com o motivo "pasta arquivada", como na tela.';

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly AtualizarPastaTexto $texto,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
        private readonly EnvioAutomaticoDaPasta $envio,
        private readonly UserRepository $usuarios,
        private readonly DefinirSituacaoDaPastaUseCase $definirSituacao,
        private readonly AlterarResponsavelDaPastaUseCase $alterarResponsavel,
        private readonly AlterarPrioridadeUseCase $alterarPrioridade,
        private readonly MoverPastaDeCarteiraUseCase $moverDeCarteira,
    ) {
    }

    public function acoes(): array
    {
        return ['situacao', 'responsavel', 'prioridade', 'carteira'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pasta = $this->acesso->viva($chamada, $entrada->acao, $entrada->pastaId(), true);
        $dados = match ($entrada->acao) {
            'situacao' => $this->situacao($pasta, $entrada->textoObrigatorio('situacao'), $chamada),
            'responsavel' => $this->responsavel($pasta, $entrada->inteiro('responsavel_id'), $chamada),
            'prioridade' => $this->prioridade($pasta, PrioridadePasta::from($entrada->textoObrigatorio('prioridade'))),
            default => $this->carteira($pasta, CarteiraDaPasta::from($entrada->textoObrigatorio('carteira')), $chamada),
        };
        $id = (int) $pasta->getId();

        return new RespostaDaFerramenta(['estado' => 'concluida', 'acao' => $entrada->acao, 'pasta_id' => $id] + $dados, ids: ['pasta' => [$id]]);
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    /**
     * @return array<string, string>
     *
     * @throws ErroDaFerramenta
     */
    private function situacao(Pasta $pasta, string $situacao, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        if ($situacao === $pasta->getSituacao()) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        if ($situacao === Pasta::SITUACAO_ATIVA && $this->envio->ligadoParaOPrincipal($pasta, $contexto->escritorio)) {
            throw ErroDaFerramenta::envioAutomatico();
        }
        $anterior = $pasta->getSituacaoRotulo();
        $contratoAntes = $pasta->getSituacaoContrato();
        AtualizarPastaRecusa::gravando(fn () => $this->definirSituacao->executar($pasta, $contexto->pessoa, $contexto->escritorio, $situacao), self::RECUSA);
        $dados = ['situacao_anterior' => $anterior, 'situacao' => $pasta->getSituacaoRotulo()];
        if ($situacao === Pasta::SITUACAO_ARQUIVADA) {
            $dados['andamento'] = self::ANDAMENTO_FINALIZADO;
        }
        if ($contratoAntes !== $pasta->getSituacaoContrato() && $this->financeiro->podeVer($contexto->pessoa, $contexto->escritorio, $pasta)) {
            $dados['contrato'] = self::CONTRATO_ASSINADO;
        }

        return $dados;
    }

    /**
     * @return array{responsavel: array{id: int, nome: string}|null}
     *
     * @throws ErroDaFerramenta
     */
    private function responsavel(Pasta $pasta, ?int $responsavelId, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $novo = $responsavelId === null
            ? null
            : ($this->usuarios->findColaboradorAtivoPorIdETenant($responsavelId, $contexto->escritorio) ?? throw ErroDaFerramenta::recusada(self::RESPONSAVEL_INVALIDO));
        if ($pasta->getResponsavel()?->getId() === $novo?->getId()) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        AtualizarPastaRecusa::gravando(fn () => $this->alterarResponsavel->executar($pasta, $novo, $contexto->pessoa, $contexto->escritorio), self::RECUSA);

        return ['responsavel' => $novo === null ? null : [
            'id' => (int) $novo->getId(),
            'nome' => $this->texto->protegido($contexto->escritorio, $novo->getFullName(), 'Colega sem nome'),
        ]];
    }

    /**
     * @return array{prioridade: string}
     *
     * @throws ErroDaFerramenta
     */
    private function prioridade(Pasta $pasta, PrioridadePasta $prioridade): array
    {
        if ($pasta->getPrioridade() === $prioridade) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        // O UseCase não confere nada: o escritório, o ver e o editar já passaram pelo acesso, como na rota da tela.
        $this->alterarPrioridade->executar($pasta, $prioridade);

        return ['prioridade' => $prioridade->label()];
    }

    /**
     * @return array{carteira_anterior: string, carteira: string}
     *
     * @throws ErroDaFerramenta
     */
    private function carteira(Pasta $pasta, CarteiraDaPasta $carteira, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        if ($pasta->getCarteira() === $carteira) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        $anterior = CarteiraDaPasta::rotuloDe($pasta->getCarteira());
        $mudou = AtualizarPastaRecusa::gravando(fn (): bool => $this->moverDeCarteira->executar($pasta, $contexto->pessoa, $contexto->escritorio, $carteira), self::RECUSA);
        if (!$mudou) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }

        return ['carteira_anterior' => $anterior, 'carteira' => $carteira->rotulo()];
    }
}
