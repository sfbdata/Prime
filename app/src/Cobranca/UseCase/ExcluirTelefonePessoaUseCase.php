<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ExcluirTelefonePessoaInput;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Entity\Tenant\Tenant;

/**
 * Exclui um telefone da lista da Pessoa (spec de qualificação §7: "excluir um item é ação explícita
 * à parte" — a rotina de trocar de número continua sendo adicionar + marcar atual, que não apaga
 * nada; isto aqui é para o registro que NÃO DEVERIA EXISTIR, tipo número de outra pessoa importado
 * por engano).
 *
 * Pessoa e telefone são resolvidos por id + tenant (guarda multi-tenant, invariável 24), e o
 * telefone precisa pertencer à MESMA pessoa informada — item de outra pessoa é tratado como não
 * encontrado (evita vazamento de existência entre pessoas). Mesma guarda do MarcarTelefoneAtual.
 *
 * SUCESSÃO (decisão do dono, 2026-07-28): excluir o telefone ATUAL promove automaticamente o mais
 * recente que sobrou (`criadoEm` maior; empate no mesmo segundo desempata pelo id maior, que é o
 * cadastrado depois). Sem isso a pessoa ficaria com telefones na lista e NENHUM atual — estado que
 * nenhum outro caminho do sistema produz e que a leitura escalar (`Pessoa::getTelefone()`) não sabe
 * representar. Quando não sobra nenhum, a coluna-sombra é zerada: a pessoa fica de fato sem
 * telefone, e é isso que as listagens devem mostrar — não o número recém-apagado.
 *
 * A promoção normaliza a lista inteira (mesmo self-healing do MarcarTelefoneAtualUseCase): qualquer
 * duplicidade pré-existente de `atual` morre junto com a exclusão, em vez de sobreviver a ela.
 *
 * Tudo — DELETE, flag do sucessor e coluna-sombra — vai num flush só.
 */
final class ExcluirTelefonePessoaUseCase
{
    public function __construct(
        private readonly PessoaTelefoneRepository $telefoneRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    /**
     * @return PessoaTelefone|null o telefone PROMOVIDO a atual por esta exclusão; null quando o
     *                            excluído não era o atual, ou quando não sobrou nenhum na lista
     */
    public function executar(ExcluirTelefonePessoaInput $input, Tenant $tenant): ?PessoaTelefone
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Guarda multi-tenant + posse: o telefone precisa ser do tenant E desta mesma pessoa.
        $telefone = $this->telefoneRepository->findOneByIdDoTenant((int) $input->telefoneId, $tenant);

        if ($telefone === null || $telefone->getPessoa()?->getId() !== $pessoa->getId()) {
            throw new PessoaTelefoneNaoEncontradoException((int) $input->telefoneId);
        }

        $eraOAtual = $telefone->isAtual();
        // Sai da coleção ANTES de escolher o sucessor: quem escolhe olha o que sobrou, e a mesma
        // request ainda vai ler esta coleção para remontar a lista na tela.
        $pessoa->removerTelefone($telefone);

        $sucessor = null;
        if ($eraOAtual) {
            $sucessor = $this->maisRecenteDaLista($pessoa->getTelefones()->toArray());

            // Normaliza a lista inteira: só o sucessor fica atual (e, sem sucessor, ninguém fica).
            foreach ($pessoa->getTelefones() as $item) {
                $item->setAtual($item === $sucessor);
            }

            $pessoa->sincronizarTelefoneSombra($sucessor?->getNumero());
        }

        $this->telefoneRepository->remover($telefone, true);

        return $sucessor;
    }

    /**
     * O mais recente da lista: `criadoEm` maior vence; empate no mesmo segundo (importação em lote
     * cria vários itens no mesmo instante) desempata pelo id maior — o cadastrado depois.
     *
     * @param list<PessoaTelefone> $telefones
     */
    private function maisRecenteDaLista(array $telefones): ?PessoaTelefone
    {
        $vencedor = null;

        foreach ($telefones as $item) {
            if ($vencedor === null) {
                $vencedor = $item;

                continue;
            }

            $comparacao = $item->getCriadoEm() <=> $vencedor->getCriadoEm();

            if ($comparacao > 0 || ($comparacao === 0 && ((int) $item->getId()) > ((int) $vencedor->getId()))) {
                $vencedor = $item;
            }
        }

        return $vencedor;
    }
}
