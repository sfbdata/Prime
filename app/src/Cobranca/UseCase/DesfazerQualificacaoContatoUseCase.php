<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Exception\EventoNaoEncontradoException;
use App\Cobranca\Exception\QualificacaoNaoDesfazivelException;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Válvula de escape do clique errado na qualificação (spec §3.5, 2026-07-27).
 *
 * O histórico de cobrança é append-only por decisão de projeto, e a qualificação nasce de um clique
 * único — sem formulário, sem confirmação. Sem esta válvula, errar o botão seria permanente. Ela existe
 * para o engano do momento, não para revisar o registro depois; daí a janela de 5 minutos, muito mais
 * curta que as 48h da anotação.
 *
 * Remoção DEFINITIVA (`remover`), como a exclusão de anotação: a linha some do histórico, não vira
 * "desfeita". Consequência aceita — a qualificação conta como trabalho de cobrança na Central, então
 * desfazer reduz o próprio número de quem desfez.
 *
 * As QUATRO condições da spec, e onde cada uma mora:
 *
 * 1. é do tipo `qualificacao_contato`  ─┐
 * 2. foi registrada por quem desfaz     ├─ `EventoHistorico::podeSerDesfeitaPor` (só dependem do evento)
 * 3. tem no máximo 5 minutos           ─┘
 * 4. é a qualificação MAIS RECENTE do caso ─ aqui, via `ultimaQualificacaoDoCaso`
 *
 * A quarta é a que se perde com facilidade: sem ela, qualquer qualificação dos últimos 5 minutos seria
 * desfazível, e desfazer a penúltima deixaria a listinha contando uma história que não aconteceu.
 *
 * **O que a quarta condição NÃO impede** (achado da revisão de 2026-07-27, comportamento conhecido e
 * sob teste): ela ordena as remoções, não as limita. Desfeita a última, a penúltima passa a ser a mais
 * recente e — se ainda estiver dentro dos 5 minutos DELA — também pode ser desfeita. Em cliques
 * sucessivos o autor limpa as qualificações que registrou nos últimos 5 minutos, de cima para baixo.
 * É o que a spec §3.5 literalmente descreve, e é coerente com a finalidade (todas seriam do mesmo
 * engano, na mesma janela, da mesma pessoa). Limitar a UMA remoção por janela é decisão do dono, ainda
 * não tomada; se ele decidir limitar, o teste `cascataDentroDaJanela` é o que quebra.
 *
 * **Caso encerrado NÃO bloqueia** — e isto DIVERGE do vizinho direto: `ExcluirAnotacaoUseCase` bloqueia,
 * com teste. A spec §3.5 é silente, e silêncio não é decisão; o que pesou aqui foi que em caso encerrado
 * nem a qualificação corretiva por cima seria possível (§17 recusa novos lançamentos), então bloquear o
 * desfazer tornaria o engano permanente e sem saída — e a janela é de 5 minutos, não de 48 horas.
 * O mesmo argumento serviria para a anotação, onde o projeto escolheu o contrário; **duas regras opostas
 * para "remover evento por engano" convivem, e uniformizá-las é decisão do dono, ainda não tomada.**
 * Registrado no handoff da frente.
 *
 * O relógio é injetado (`ClockInterface`) — o mesmo serviço que `EncargosVivos::agora()` devolve para o
 * resto da página. Nada de `new \DateTimeImmutable()` no caminho: sem relógio fixável não haveria como
 * testar a janela de 5 minutos.
 */
final class DesfazerQualificacaoContatoUseCase
{
    public function __construct(
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Sem retorno, decidido na Etapa 8 (2026-07-27). A Etapa 2 devolvia o id do CASO "para o redirect",
     * e o retorno nasceu morto: o controller volta para a página do OBJETO, e resolve esse objeto ANTES
     * de remover — depois da remoção o evento já não leva a lugar nenhum. Pior que peso morto, era um
     * contrato falso: quem lesse o `@return` acreditaria que o destino da volta é o caso. Quem precisar
     * de um valor aqui um dia que o declare com o destino verdadeiro, em vez de herdar este.
     */
    public function executar(int $eventoId, Tenant $tenant, User $usuario): void
    {
        // Guarda multi-tenant: evento de outro escritório não existe para quem pede.
        $evento = $this->eventoRepository->findOneByIdDoTenant($eventoId, $tenant);

        if ($evento === null) {
            throw new EventoNaoEncontradoException($eventoId);
        }

        // Condições 1 a 3.
        if (!$evento->podeSerDesfeitaPor($usuario, $this->clock->now())) {
            throw new QualificacaoNaoDesfazivelException();
        }

        $caso = $evento->getCaso();
        $idDoEvento = $evento->getId();

        // Evento sem caso ou sem id não tem como ser comparado com "a última do caso" — e o que não se
        // consegue provar, se recusa. A coluna é `nullable: false` e o evento veio do banco, então na
        // prática nenhum dos dois acontece; a guarda existe para a comparação abaixo nunca ser
        // fail-open (dois ids nulos são "iguais" para o `!==` e liberariam a remoção).
        if ($caso === null || $idDoEvento === null) {
            throw new QualificacaoNaoDesfazivelException();
        }

        // Condição 4: só a do topo. Comparação por id — o objeto vindo da consulta pode ser outra
        // instância do mesmo registro.
        $ultima = $this->eventoRepository->ultimaQualificacaoDoCaso($caso);

        if ($ultima === null || $ultima->getId() !== $idDoEvento) {
            throw new QualificacaoNaoDesfazivelException();
        }

        $this->eventoRepository->remover($evento, true);
    }
}
