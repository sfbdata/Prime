<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AtividadePessoaDetalheOutput;
use App\Cobranca\DTO\EventoAtividadeOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\PastilhasDeContato;
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
        private readonly PastilhasDeContato $pastilhas,
    ) {
    }

    public function executar(
        Tenant $tenant,
        ?int $usuarioId,
        string $nome,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira = null,
        bool $incluirCadastro = false,
    ): AtividadePessoaDetalheOutput {
        $contarPayload = fn (string $chave): array => $this->eventoRepository->contarPayloadDeContatoDaPessoa(
            $tenant,
            $chave,
            $usuarioId,
            $inicio,
            $fimExclusivo,
            $carteira,
        );

        // A lista PADRÃO é só trabalho de cobrança (spec §5.1): sem isso, uma importação de 537 linhas
        // afoga os 3 contatos que o gestor abriu o detalhe para ver.
        [$eventos, $truncado] = $this->listar(
            $tenant,
            $usuarioId,
            $inicio,
            $fimExclusivo,
            $carteira,
            TipoEventoHistorico::trabalhoDeCobranca(),
        );

        $tiposCadastro = TipoEventoHistorico::lancamentoDeCadastro();

        // O resto NUNCA some calado: vira a linha "+ N lançamentos de cadastro/importação", expansível.
        $totalCadastro = $this->eventoRepository->contarEventosDoUsuarioNoPeriodo(
            $tenant,
            $usuarioId,
            $inicio,
            $fimExclusivo,
            $carteira,
            $tiposCadastro,
        );

        $eventosCadastro = [];
        $truncadoCadastro = false;
        if ($incluirCadastro && $totalCadastro > 0) {
            [$eventosCadastro, $truncadoCadastro] = $this->listar(
                $tenant,
                $usuarioId,
                $inicio,
                $fimExclusivo,
                $carteira,
                $tiposCadastro,
            );
        }

        return new AtividadePessoaDetalheOutput(
            usuarioId: $usuarioId,
            nome: $nome,
            desfechos: $this->pastilhas->desfechos($contarPayload(PastilhasDeContato::CHAVE_RESULTADO)),
            canais: $this->pastilhas->canais($contarPayload(PastilhasDeContato::CHAVE_CANAL)),
            eventos: $eventos,
            truncado: $truncado,
            limite: self::LIMITE_EVENTOS,
            totalCadastro: $totalCadastro,
            eventosCadastro: $eventosCadastro,
            cadastroExpandido: $incluirCadastro,
            truncadoCadastro: $truncadoCadastro,
        );
    }

    /**
     * Busca uma lista de eventos com a folga de +1 — o truque que dispensa uma segunda consulta só para
     * saber se sobrou coisa de fora — e devolve já cortada no limite, com o aviso de truncagem.
     *
     * @param list<TipoEventoHistorico> $tipos
     *
     * @return array{0: list<EventoAtividadeOutput>, 1: bool}
     */
    private function listar(
        Tenant $tenant,
        ?int $usuarioId,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira,
        array $tipos,
    ): array {
        $eventos = $this->eventoRepository->eventosDoUsuarioNoPeriodo(
            $tenant,
            $usuarioId,
            $inicio,
            $fimExclusivo,
            $carteira,
            $tipos,
            self::LIMITE_EVENTOS + 1,
        );

        return [
            array_map(
                static fn ($evento): EventoAtividadeOutput => EventoAtividadeOutput::fromEntity($evento),
                array_slice($eventos, 0, self::LIMITE_EVENTOS),
            ),
            \count($eventos) > self::LIMITE_EVENTOS,
        ];
    }
}
