<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AtividadePessoaDetalheOutput;
use App\Cobranca\DTO\DesfechoContatoOutput;
use App\Cobranca\DTO\EventoAtividadeOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\ResultadoContato;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Leitura: detalhe de UMA pessoa na aba Atividade (spec §4). Carregado sob demanda, por rota própria —
 * a tabela não embute o detalhe de todo mundo. Read-only.
 *
 * História: o gestor viu na tabela que a Maria fez 61 contatos e 27 atendidos, e quer saber COMO isso se
 * distribuiu — quantos caíram em caixa postal, quantos eram número errado — e o que exatamente ela
 * registrou. As pastilhas respondem a primeira pergunta; a lista de eventos, a segunda.
 *
 * O desfecho mora no payload JSON do evento (`dados->>'resultado'`), gravado por
 * `RegistrarTentativaCobrancaUseCase` com o VALUE do enum. A tradução para rótulo é aqui: o repositório
 * devolve o valor cru.
 *
 * `$usuarioId` nulo significa a linha "Sem responsável" — os eventos órfãos —, não "qualquer pessoa". O
 * nome chega pronto do controller, que é quem resolve o usuário tenant-safe (anti-IDOR).
 */
final class MontarDetalheAtividadePessoaUseCase
{
    /** Teto da lista de eventos: o suficiente para investigar um período sem despejar o histórico todo. */
    public const LIMITE_EVENTOS = 200;

    public function __construct(
        private readonly EventoHistoricoRepository $eventoRepository,
    ) {
    }

    public function executar(
        Tenant $tenant,
        ?int $usuarioId,
        string $nome,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira = null,
    ): AtividadePessoaDetalheOutput {
        $contagem = $this->eventoRepository->contarDesfechosDeContato($tenant, $usuarioId, $inicio, $fimExclusivo, $carteira);

        // +1 é o truque que dispensa uma segunda consulta só para saber se sobrou coisa de fora.
        $eventos = $this->eventoRepository->eventosDoUsuarioNoPeriodo(
            $tenant,
            $usuarioId,
            $inicio,
            $fimExclusivo,
            $carteira,
            self::LIMITE_EVENTOS + 1,
        );

        $truncado = \count($eventos) > self::LIMITE_EVENTOS;

        return new AtividadePessoaDetalheOutput(
            usuarioId: $usuarioId,
            nome: $nome,
            desfechos: $this->desfechos($contagem),
            eventos: array_map(
                static fn ($evento): EventoAtividadeOutput => EventoAtividadeOutput::fromEntity($evento),
                array_slice($eventos, 0, self::LIMITE_EVENTOS),
            ),
            truncado: $truncado,
            limite: self::LIMITE_EVENTOS,
        );
    }

    /**
     * Ordem das pastilhas: "Atendido" primeiro (é a medida de efetividade que a coluna "Falou com o
     * devedor" resume), depois os demais desfechos oferecidos no formulário — inclusive os zerados, para
     * o gestor ver a régua inteira.
     *
     * Fora dessa régua, só entra o que de fato ocorreu:
     * - `prometeu_pagar` saiu do formulário no ajuste de 2026-07 e sobrevive só em histórico antigo:
     *   pastilha zerada dele seria pedir um desfecho que ninguém consegue mais registrar;
     * - contato sem a chave `resultado` no payload vira "Não informado";
     * - valor desconhecido é exibido CRU — some em silêncio seria a tela mentir sobre o total.
     *
     * @param array<string, int> $contagem valor cru do payload → quantidade
     *
     * @return list<DesfechoContatoOutput>
     */
    private function desfechos(array $contagem): array
    {
        $regua = array_merge(
            [ResultadoContato::Atendido],
            array_values(array_filter(
                ResultadoContato::selecionaveis(),
                static fn (ResultadoContato $r): bool => $r !== ResultadoContato::Atendido,
            )),
        );

        $pastilhas = [];
        foreach ($regua as $resultado) {
            $pastilhas[] = new DesfechoContatoOutput($resultado->label(), $contagem[$resultado->value] ?? 0);
            unset($contagem[$resultado->value]);
        }

        foreach ($contagem as $valorCru => $quantidade) {
            if ($quantidade <= 0) {
                continue;
            }

            $conhecido = ResultadoContato::tryFrom((string) $valorCru);
            $label = $conhecido?->label() ?? ($valorCru === '' ? 'Não informado' : (string) $valorCru);

            $pastilhas[] = new DesfechoContatoOutput($label, $quantidade);
        }

        return $pastilhas;
    }
}
