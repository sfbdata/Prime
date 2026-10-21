<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Entity\Auth\User;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use App\Master\DTO\RespostaNaOrigem;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Tenant\Enum\EstadoDoPedidoDeExclusao;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Origem `exclusao_conta`: o pedido de exclusão de conta feito pelo primário (PR 5, docs/specs/conta-pedido-exclusao.md;
 * spec docs/specs/master-central-leitores-exclusao-e-consumo.md). Todos os pedidos, mais novos primeiro, com a
 * situação no texto; a decisão continua no painel (Contas e escritórios). A resposta vai para quem pediu.
 *
 * SQL direto, como `PedidoDeExclusaoDeContaRepository::pendentes()`: o filtro de escritório da sessão do Master
 * esconderia os pedidos das outras contas.
 */
final class LeitorDePedidosDeExclusao implements OrigemDaCentral
{
    public const ORIGEM = 'exclusao_conta';
    // Tipo visual do desenho (cor da etiqueta): o vermelho dos alertas; o rótulo diz o que é.
    public const TIPO   = 'alerta';

    private const SELECT = 'SELECT p.id, p.tenant_id, p.pedido_por_id, p.motivo, p.criado_em, p.estado, t.name AS escritorio, t.is_active,
                                   u.full_name, u.email
                              FROM pedido_exclusao_conta p
                              JOIN tenant t ON t.id = p.tenant_id
                         LEFT JOIN "user" u ON u.id = p.pedido_por_id';

    public function __construct(
        private readonly Connection $conexao,
        private readonly TenantRepository $escritorios,
        private readonly UserRepository $usuarios,
    ) {
    }

    public function origem(): string
    {
        return self::ORIGEM;
    }

    public function todas(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            self::SELECT . ' ORDER BY p.criado_em DESC, p.id DESC LIMIT :limite',
            ['limite' => self::LIMITE],
            ['limite' => ParameterType::INTEGER],
        );

        return array_map($this->emMensagem(...), $linhas);
    }

    public function uma(int $idOrigem): ?MensagemDaCentral
    {
        $linha = $this->linha($idOrigem);

        return $linha === null ? null : $this->emMensagem($linha);
    }

    public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta
    {
        $linha = $this->linha($idOrigem);
        if ($linha === null || $linha['pedido_por_id'] === null) {
            return null;
        }
        // O escritório pelo id da linha: o pedido é TenantAware, e um find() passaria pelo filtro da sessão do Master.
        $autor = $this->usuarios->find((int) $linha['pedido_por_id']);
        $escritorio = $this->escritorios->find((int) $linha['tenant_id']);

        return $autor instanceof User ? new DestinoDaResposta($autor->getEmail(), $autor, $escritorio) : null;
    }

    public function respostaNaOrigem(int $idOrigem): ?RespostaNaOrigem
    {
        return null;
    }

    public function registrarResposta(int $idOrigem, string $texto, User $master): void
    {
        // A resposta fica só na Central: o pedido guarda a decisão, não a conversa.
    }

    /** @return array<string, mixed>|null */
    private function linha(int $idOrigem): ?array
    {
        $linha = $this->conexao->fetchAssociative(self::SELECT . ' WHERE p.id = :id', ['id' => $idOrigem]);

        return $linha === false ? null : $linha;
    }

    /** @param array<string, mixed> $l */
    private function emMensagem(array $l): MensagemDaCentral
    {
        $escritorio = (string) $l['escritorio'];
        $estado     = EstadoDoPedidoDeExclusao::tryFrom((string) $l['estado']);

        return new MensagemDaCentral(
            origem: self::ORIGEM,
            idOrigem: (int) $l['id'],
            tipo: self::TIPO,
            rotuloDoTipo: 'Pedido de exclusão',
            deNome: is_string($l['full_name']) && $l['full_name'] !== '' ? $l['full_name'] : 'Usuário primário',
            deEmail: is_string($l['email']) && $l['email'] !== '' ? $l['email'] : null,
            escritorio: 'Conta Nº ' . str_pad((string) $l['tenant_id'], 6, '0', \STR_PAD_LEFT),
            tenantId: (int) $l['tenant_id'],
            assunto: 'Quer excluir a conta ' . $escritorio,
            texto: sprintf("Motivo: %s\n\nSituação do pedido: %s. A decisão fica em Contas e escritórios.", (string) $l['motivo'], self::situacao($estado, (bool) $l['is_active'])),
            em: new \DateTimeImmutable((string) $l['criado_em']),
            podeResponder: $l['pedido_por_id'] !== null,
        );
    }

    /** "excluida" com o escritório de volta ao ar = recuperada pelo Master (contrato com a b5). */
    private static function situacao(?EstadoDoPedidoDeExclusao $estado, bool $escritorioAtivo): string
    {
        return match ($estado) {
            EstadoDoPedidoDeExclusao::Pendente  => 'aguardando a sua decisão (a conta está suspensa a pedido)',
            EstadoDoPedidoDeExclusao::Desistida => 'o primário desistiu',
            EstadoDoPedidoDeExclusao::Reativada => 'reativada pelo Master',
            EstadoDoPedidoDeExclusao::Excluida  => $escritorioAtivo ? 'excluída e depois recuperada pelo Master' : 'excluída pelo Master (quarentena de 365 dias)',
            null                                => 'desconhecida',
        };
    }
}
