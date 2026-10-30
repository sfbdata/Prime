<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\ConfirmacaoDaProposta;
use App\ChatIa\DTO\ConfirmarPropostaInput;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\ChatIa\Enum\TipoDaProposta;
use App\ChatIa\Exception\CadastroJaExisteNaPropostaException;
use App\ChatIa\Exception\PastaSemelhanteNaPropostaException;
use App\ChatIa\Exception\PropostaEncerradaException;
use App\ChatIa\Exception\PropostaInvalidaException;
use App\ChatIa\Exception\PropostaNaoEncontradaException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\ChatIa\Exception\SemPermissaoParaPropostaException;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\ExecutorDaProposta;
use App\ChatIa\Service\GuardaDasPropostas;
use App\ChatIa\Service\InterpretadorDaChamadaDeCadastro;
use App\ChatIa\Service\MontadorDoCartaoDaProposta;
use App\ChatIa\Service\TransacaoDoChat;
use App\ChatIa\Service\ValidadorDaProposta;
use App\Cliente\Entity\Cliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use Psr\Clock\ClockInterface;

/**
 * O clique em Confirmar: a ÚNICA porta pela qual uma proposta da I.A vira Cliente ou Pasta (spec `chat-ia-cadastro.md`).
 * Grava pelos UseCases de Cliente e Pasta, com o usuário e o escritório da SESSÃO.
 *
 * Ordem das guardas (todas recusam antes de gravar):
 * 1. login, vínculo ativo e módulo da I.A; posse da proposta (alheia, de outro escritório ou inexistente = 404);
 * 2. estado, com TRAVA DE LINHA numa transação: só `pendente` e dentro do prazo (senão 409; vencida vira `expirada`). Dois
 *    cliques seguidos gravam uma vez, o segundo já enxerga a proposta decidida;
 * 3. permissão de quem CLICOU, agora (403), não a da hora da proposta;
 * 4. validação dos valores que a pessoa enviou (422 por campo), com as regras do cadastro;
 * 5. CPF/CNPJ que já é de um cadastro (409 `duplicada`; na pasta, `usarExistente` liga o existente);
 * 6. pasta parecida (409 `pasta_semelhante`), a menos que venha `criarMesmoAssim`.
 *
 * Depois de gravar, a proposta vira `confirmada`, guarda só o tipo e o id do criado e perde os dados pessoais. A recusa
 * é devolvida pela transação (não lançada dentro dela) para o que for persistido, como o vencimento, não ser desfeito.
 *
 * @throws SemAcessoAoChatException
 * @throws PropostaNaoEncontradaException
 * @throws PropostaEncerradaException
 * @throws SemPermissaoParaPropostaException
 * @throws PropostaInvalidaException
 * @throws CadastroJaExisteNaPropostaException
 * @throws PastaSemelhanteNaPropostaException
 */
final class ConfirmarPropostaDeCadastroUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly PropostaDeCadastroRepository $propostas,
        private readonly GuardaDasPropostas $guarda,
        private readonly ValidadorDaProposta $validador,
        private readonly ExecutorDaProposta $executor,
        private readonly PastaRepository $pastas,
        private readonly MontadorDoCartaoDaProposta $cartao,
        private readonly TransacaoDoChat $transacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $id, ConfirmarPropostaInput $input): ConfirmacaoDaProposta
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        if ($id <= 0 || $this->propostas->doDono($id, $tenant, $eu) === null) {
            throw new PropostaNaoEncontradaException();
        }
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $desfecho = $this->transacao->executar(fn (): ConfirmacaoDaProposta|\Throwable => $this->decidir($id, $eu, $tenant, $input, $agora));
        if ($desfecho instanceof \Throwable) {
            throw $desfecho;
        }

        return $desfecho;
    }

    private function decidir(int $id, User $eu, Tenant $tenant, ConfirmarPropostaInput $input, \DateTimeImmutable $agora): ConfirmacaoDaProposta|\Throwable
    {
        $proposta = $this->propostas->doDonoComTrava($id, $tenant, $eu);
        if ($proposta === null) {
            return new PropostaNaoEncontradaException();
        }
        if (!$proposta->estaPendente()) {
            return PropostaEncerradaException::jaDecidida($proposta->getEstado());
        }
        if ($proposta->estaVencida($agora) || $proposta->getMensagem()?->foiSubstituida() === true) {
            $proposta->expirar($agora);

            return PropostaEncerradaException::vencida();
        }

        try {
            $this->guarda->exigirNoClique($proposta->getTipo(), $eu, $tenant);
            $dados = $this->dadosConfirmados($proposta, $input);
            $validacao = $this->validador->validar($proposta->getTipo(), $dados, $tenant);
            $usaOExistente = $proposta->getTipo() === TipoDaProposta::Pasta && $input->usarExistente && $validacao->existente !== null;
            // Ligando o cadastro que já existe, os campos do cliente novo não serão gravados: erro neles não conta.
            $erros = $usaOExistente ? array_filter($validacao->erros, static fn (string $campo): bool => !str_starts_with($campo, 'campos.'), ARRAY_FILTER_USE_KEY) : $validacao->erros;
            if ($erros !== []) {
                return new PropostaInvalidaException($erros);
            }
            $existente = $this->existenteAUsar($proposta->getTipo(), $input, $validacao->existente, $validacao->documento);
            $this->exigirSemPastaSemelhante($proposta->getTipo(), $dados, $input, $tenant);
        } catch (SemPermissaoParaPropostaException|PropostaInvalidaException|CadastroJaExisteNaPropostaException|PastaSemelhanteNaPropostaException $recusa) {
            return $recusa;
        }

        $criado = $this->executor->executar($proposta->getTipo(), $dados, $eu, $tenant, $existente);
        $proposta->confirmar($criado->tipo, $criado->id, $agora);

        return new ConfirmacaoDaProposta($this->cartao->montar($proposta, $agora), $criado->pasta);
    }

    /**
     * Os dados que valem: os da proposta, com o que a pessoa editou no cartão por cima. `campos` troca todos os campos do
     * cliente (o que ela enviou é o que vale) e só aceita as chaves do tipo de pessoa da proposta.
     *
     * @return array<string, mixed>
     *
     * @throws PropostaInvalidaException
     */
    private function dadosConfirmados(PropostaDeCadastro $proposta, ConfirmarPropostaInput $input): array
    {
        $dados = $proposta->getDados() ?? throw new PropostaInvalidaException(['' => 'A proposta não tem mais dados.']);
        $ehPasta = $proposta->getTipo() === TipoDaProposta::Pasta;

        if ($input->campos !== null) {
            $pessoa = $ehPasta ? ($dados['cliente']['tipoPessoa'] ?? null) : $dados['tipoPessoa'];
            if ($pessoa === null) {
                throw new PropostaInvalidaException(['' => 'Esta proposta não traz o cadastro de um cliente.']);
            }
            $campos = InterpretadorDaChamadaDeCadastro::campos($input->campos, (string) $pessoa)
                ?? throw new PropostaInvalidaException(['' => 'Há um campo que a proposta não aceita.']);
            if ($ehPasta) {
                $dados['cliente']['campos'] = $campos;
            } else {
                $dados['campos'] = $campos;
            }
        }
        if ($input->pasta !== null) {
            if (!$ehPasta) {
                throw new PropostaInvalidaException(['' => 'Esta proposta não é de pasta.']);
            }
            foreach (['nomeCliente' => 255, 'nomeAcao' => 255, 'nup' => 50] as $chave => $teto) {
                if (!\array_key_exists($chave, $input->pasta)) {
                    continue;
                }
                $dados[$chave] = InterpretadorDaChamadaDeCadastro::texto($input->pasta[$chave], $teto)
                    ?? throw new PropostaInvalidaException(['pasta.' . $chave => 'Texto inválido ou grande demais.']);
            }
        }

        return $dados;
    }

    /**
     * @throws CadastroJaExisteNaPropostaException
     * @throws PropostaInvalidaException
     */
    private function existenteAUsar(TipoDaProposta $tipo, ConfirmarPropostaInput $input, ?Cliente $existente, ?string $documento): ?Cliente
    {
        if ($existente === null) {
            return $input->usarExistente ? throw new PropostaInvalidaException(['' => 'Não há cadastro existente para usar.']) : null;
        }
        if ($tipo === TipoDaProposta::Pasta && $input->usarExistente) {
            return $existente;
        }

        throw new CadastroJaExisteNaPropostaException((int) $existente->getId(), $existente->getNomeExibicao(), (string) $documento, $tipo === TipoDaProposta::Pasta);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws PastaSemelhanteNaPropostaException
     */
    private function exigirSemPastaSemelhante(TipoDaProposta $tipo, array $dados, ConfirmarPropostaInput $input, Tenant $tenant): void
    {
        if ($tipo !== TipoDaProposta::Pasta || $input->criarMesmoAssim) {
            return;
        }
        $nomeCliente = trim((string) $dados['nomeCliente']);
        $nomeAcao = trim((string) $dados['nomeAcao']);
        $semelhantes = $this->pastas->findSemelhantesPorClienteEAcao($tenant, $nomeCliente, $nomeAcao);
        if ($semelhantes === []) {
            return;
        }

        throw new PastaSemelhanteNaPropostaException(
            array_map(static fn (Pasta $p): array => [
                'id' => (int) $p->getId(),
                'nup' => (string) $p->getNup(),
                'nomeCliente' => (string) $p->getNomeCliente(),
                'nomeAcao' => (string) $p->getNomeAcao(),
            ], $semelhantes),
            $this->pastas->contarSemelhantesPorClienteEAcao($tenant, $nomeCliente, $nomeAcao),
        );
    }
}
