<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaComentarioDestaque;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Repository\PastaObservacaoFinanceiraRepository;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use App\Pasta\UseCase\EditarObservacaoDetalhesUseCase;
use App\Pasta\UseCase\EditarObservacaoFinanceiraUseCase;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use App\Pasta\UseCase\EnviarObservacaoDetalhesUseCase;
use App\Pasta\UseCase\EnviarObservacaoFinanceiraUseCase;
use App\Pasta\UseCase\ExcluirMensagemPastaUseCase;
use App\Pasta\UseCase\ExcluirObservacaoDetalhesUseCase;
use App\Pasta\UseCase\ExcluirObservacaoFinanceiraUseCase;

/**
 * Os três lugares da `registrar_na_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6) e os UseCases da tela de cada um:
 *  - `registro`: as mensagens do Registro da aba Dados ({@see PastaMensagem});
 *  - `relatorio_inicial`: as observações do Relatório inicial, aba Detalhes ({@see PastaObservacaoDetalhes});
 *  - `relatorio_financeiro`: as observações do Relatório financeiro, aba Financeiro ({@see PastaObservacaoFinanceira}).
 *
 * Só despacha para o UseCase certo; as regras (autor, janela de 15 minutos, menções, avisos) moram nos UseCases, e as
 * recusas de acesso, na ferramenta. A busca por id é sempre por id, pasta E escritório, como a tela.
 */
final class RegistrarNaPastaLugares
{
    public const REGISTRO = 'registro';
    public const RELATORIO_INICIAL = 'relatorio_inicial';
    public const RELATORIO_FINANCEIRO = 'relatorio_financeiro';

    /** Na ordem da tabela da 6.6 ({@see TabelaDeAbaEChave}, campo `lugar`). */
    public const LUGARES = [self::REGISTRO, self::RELATORIO_INICIAL, self::RELATORIO_FINANCEIRO];

    /** Como o resumo e a resposta chamam cada lugar. */
    public const ROTULOS = [
        self::REGISTRO => 'Registro da pasta (aba Dados)',
        self::RELATORIO_INICIAL => 'Relatório inicial (aba Detalhes)',
        self::RELATORIO_FINANCEIRO => 'Relatório financeiro (aba Financeiro)',
    ];

    /** A aba do {@see \App\Pasta\UseCase\DestacarComentarioUseCase} de cada lugar. */
    public const ABA_DO_DESTAQUE = [
        self::REGISTRO => PastaComentarioDestaque::ABA_DADOS,
        self::RELATORIO_INICIAL => PastaComentarioDestaque::ABA_DETALHES,
        self::RELATORIO_FINANCEIRO => PastaComentarioDestaque::ABA_FINANCEIRO,
    ];

    public function __construct(
        private readonly PastaMensagemRepository $mensagens,
        private readonly PastaObservacaoDetalhesRepository $detalhes,
        private readonly PastaObservacaoFinanceiraRepository $financeiras,
        private readonly EnviarMensagemPastaUseCase $enviarMensagem,
        private readonly EditarMensagemPastaUseCase $editarMensagem,
        private readonly ExcluirMensagemPastaUseCase $excluirMensagem,
        private readonly EnviarObservacaoDetalhesUseCase $enviarDetalhes,
        private readonly EditarObservacaoDetalhesUseCase $editarDetalhes,
        private readonly ExcluirObservacaoDetalhesUseCase $excluirDetalhes,
        private readonly EnviarObservacaoFinanceiraUseCase $enviarFinanceira,
        private readonly EditarObservacaoFinanceiraUseCase $editarFinanceira,
        private readonly ExcluirObservacaoFinanceiraUseCase $excluirFinanceira,
    ) {
    }

    /** O registro do lugar, por id, pasta e escritório; null se não é dali (alheio, de outra pasta ou de outro lugar). */
    public function buscar(string $lugar, int $id, Pasta $pasta, Tenant $escritorio): PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira|null
    {
        $criterio = ['id' => $id, 'pasta' => $pasta, 'tenant' => $escritorio];

        return match ($lugar) {
            self::REGISTRO => $this->mensagens->findOneBy($criterio),
            self::RELATORIO_INICIAL => $this->detalhes->findOneBy($criterio),
            self::RELATORIO_FINANCEIRO => $this->financeiras->findOneBy($criterio),
            default => null,
        };
    }

    /**
     * Grava um registro novo (ou uma resposta) pelo UseCase da tela do lugar. A resposta tem de ser do mesmo lugar: veio
     * do {@see buscar()} com o mesmo `$lugar`.
     *
     * @throws \InvalidArgumentException recusa do UseCase (conteúdo, posse)
     */
    public function escrever(string $lugar, Pasta $pasta, User $autor, string $html, Tenant $escritorio, PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira|null $respostaA): PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira
    {
        if ($lugar === self::REGISTRO && ($respostaA === null || $respostaA instanceof PastaMensagem)) {
            return $this->enviarMensagem->executar($pasta, $autor, $html, $escritorio, $respostaA);
        }
        if ($lugar === self::RELATORIO_INICIAL && ($respostaA === null || $respostaA instanceof PastaObservacaoDetalhes)) {
            return $this->enviarDetalhes->executar($pasta, $autor, $html, $escritorio, $respostaA);
        }
        if ($lugar === self::RELATORIO_FINANCEIRO && ($respostaA === null || $respostaA instanceof PastaObservacaoFinanceira)) {
            return $this->enviarFinanceira->executar($pasta, $autor, $html, $escritorio, $respostaA);
        }

        throw new \LogicException('Lugar e registro respondido não combinam.');
    }

    /** A mesma pergunta que a tela faz para mostrar o lápis (autor, janela de 15 minutos, cartão do sistema). */
    public function podeEditar(PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $registro, User $pessoa, Tenant $escritorio): bool
    {
        if ($registro instanceof PastaMensagem) {
            return $this->editarMensagem->podeEditar($registro, $pessoa, $escritorio);
        }
        if ($registro instanceof PastaObservacaoDetalhes) {
            return $this->editarDetalhes->podeEditar($registro, $pessoa, $escritorio);
        }

        return $this->editarFinanceira->podeEditar($registro, $pessoa, $escritorio);
    }

    /** A mesma pergunta que a tela faz para mostrar a lixeira do cartão. */
    public function podeExcluir(PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $registro, User $pessoa, Tenant $escritorio): bool
    {
        if ($registro instanceof PastaMensagem) {
            return $this->excluirMensagem->podeExcluir($registro, $pessoa, $escritorio);
        }
        if ($registro instanceof PastaObservacaoDetalhes) {
            return $this->excluirDetalhes->podeExcluir($registro, $pessoa, $escritorio);
        }

        return $this->excluirFinanceira->podeExcluir($registro, $pessoa, $escritorio);
    }

    /**
     * @throws \DomainException      quem não pode editar (o UseCase confere de novo)
     * @throws \InvalidArgumentException conteúdo recusado
     */
    public function editar(PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $registro, User $pessoa, Tenant $escritorio, string $html): void
    {
        if ($registro instanceof PastaMensagem) {
            $this->editarMensagem->executar($registro, $pessoa, $escritorio, $html);

            return;
        }
        if ($registro instanceof PastaObservacaoDetalhes) {
            $this->editarDetalhes->executar($registro, $pessoa, $escritorio, $html);

            return;
        }
        $this->editarFinanceira->executar($registro, $pessoa, $escritorio, $html);
    }

    /** @throws \DomainException quem não pode excluir (o UseCase confere de novo) */
    public function excluir(PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $registro, User $pessoa, Tenant $escritorio): void
    {
        if ($registro instanceof PastaMensagem) {
            $this->excluirMensagem->executar($registro, $pessoa, $escritorio);

            return;
        }
        if ($registro instanceof PastaObservacaoDetalhes) {
            $this->excluirDetalhes->executar($registro, $pessoa, $escritorio);

            return;
        }
        $this->excluirFinanceira->executar($registro, $pessoa, $escritorio);
    }
}
