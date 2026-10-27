<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\QualificacaoContato;
use App\Entity\Auth\User;
use App\Tenant\Enum\PoderSemPrazo;

/**
 * Uma linha da listinha de qualificações do painel da aba Responsáveis (2026-07-27): rótulo, quando e
 * quem. Nada além disso — o dono pediu "algo bem limpo, sem poluir".
 *
 * `podeDesfazer` vem decidido do SERVIDOR, como o `editavel` da anotação: o template não recalcula
 * prazo nem compara autoria, senão tela e servidor poderiam discordar e o botão apareceria para quem
 * a rota vai recusar.
 *
 * Edição sem prazo (spec `edicao-sem-prazo-master-primario.md`, item 7): Master e primário (com a opção) desfazem
 * fora dos 5 minutos e a de outra pessoa; "só a mais recente" continua para todos. `seloSemPrazo` diz quando o botão
 * só existe por causa do poder ('master' | 'primario').
 */
final class QualificacaoContatoOutput
{
    public function __construct(
        public readonly int $eventoId,
        public readonly string $label,
        public readonly string $icone,
        public readonly \DateTimeImmutable $ocorridoEm,
        public readonly ?string $autorNome,
        public readonly bool $podeDesfazer,
        public readonly ?string $seloSemPrazo = null,
    ) {
    }

    /**
     * Devolve `null` quando o payload não guarda uma qualificação reconhecível — a listinha pula a
     * linha em vez de derrubar a página. Ver `QualificacaoContato::tentarDe`.
     *
     * `$ehMaisRecente` é a quarta condição de desfazer (a que o evento sozinho não sabe responder):
     * quem monta a lista passa `true` só para a primeira linha.
     */
    public static function tentarDe(
        EventoHistorico $evento,
        ?User $usuarioAtual,
        ?\DateTimeImmutable $agora,
        bool $ehMaisRecente,
        ?PoderSemPrazo $poder = null,
    ): ?self {
        $qualificacao = QualificacaoContato::tentarDe($evento->getDados()['qualificacao'] ?? null);

        if ($qualificacao === null) {
            return null;
        }

        $autor = $evento->getUsuario();
        $pelaRegra = $ehMaisRecente && $agora !== null && $evento->podeSerDesfeitaPor($usuarioAtual, $agora);
        $peloPoder = !$pelaRegra && $ehMaisRecente && $agora !== null && $poder !== null
            && $evento->podeSerDesfeitaPor($usuarioAtual, $agora, true);

        return new self(
            eventoId: $evento->getId() ?? 0,
            label: $qualificacao->label(),
            icone: $qualificacao->icone(),
            ocorridoEm: $evento->getOcorridoEm(),
            autorNome: $autor?->getFullName() ?? $autor?->getEmail(),
            podeDesfazer: $pelaRegra || $peloPoder,
            seloSemPrazo: $peloPoder ? $poder->value : null,
        );
    }
}
