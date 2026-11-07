<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\DTO\ConsultaDePrazosInput;
use App\ChatIa\DTO\MetaDoPrazoOutput;
use App\ChatIa\DTO\PrazosEMetasOutput;
use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\Exception\SemAcessoAsMetasNoChatException;
use App\ChatIa\Prompt\PromptDoChatLivre;
use App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ChamadaDeFerramenta;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Service\NeutralizadorDeConteudo;
use App\Tarefa\Enum\AbaMetas;
use Psr\Log\LoggerInterface;

/**
 * A ferramenta `consultar_prazos_e_metas` na resposta do chat (spec `docs/specs/chat-ia-prazos-e-metas.md`): lê a entrada que o
 * modelo mandou (dado não confiável), roda {@see ConsultarPrazosEMetasUseCase} para a DONA da conversa (a pessoa da sessão) e
 * monta, para cada consulta, um bloco `<prazos_e_metas>` com o cabeçalho escrito aqui e os valores do banco neutralizados e
 * mascarados pelo escritório ({@see ProtecaoDoTextoDoChat::comoDado}).
 *
 * No máximo {@see self::MAXIMO_POR_RESPOSTA} consultas distintas por resposta (a repetida roda uma vez). Entrada inválida ou
 * pessoa sem acesso vira uma linha fixa "consulta não realizada", sem dado, para o modelo poder dizer isso à pessoa.
 *
 * O log leva só ids e números (período, papel, quantas); nunca título, pasta, cliente nem nome.
 */
final class ConsultaDePrazosNoChat implements ConsultasDoChat
{
    public const MAXIMO_POR_RESPOSTA = 3;
    private const ENTRADA_INVALIDA = 'o pedido de consulta veio num formato que o sistema não reconhece';

    public function __construct(
        private readonly ConsultarPrazosEMetasUseCase $consulta,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PromptDoChatLivre $prompt,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ehConsulta(ChamadaDeFerramenta $chamada): bool
    {
        return $chamada->nome === FerramentasDaConsulta::PRAZOS_E_METAS;
    }

    public function pedidoComResultado(RespostaPreparada $preparada, array $chamadas): ?PedidoDeLinguagem
    {
        $blocos = [];
        foreach ($this->consultasDistintas($chamadas) as $consulta) {
            $blocos[] = $this->bloco($preparada, $consulta);
        }

        return $blocos === [] ? null : $this->prompt->comResultadoDaConsulta($preparada->pedido, $blocos);
    }

    /**
     * @param list<ChamadaDeFerramenta> $chamadas
     *
     * @return list<ConsultaDePrazosInput|null> nulo = entrada inválida (conta como uma consulta)
     */
    private function consultasDistintas(array $chamadas): array
    {
        $consultas = [];
        foreach ($chamadas as $chamada) {
            if (!$this->ehConsulta($chamada)) {
                continue;
            }
            $consulta = ConsultaDePrazosInput::daEntrada($chamada->entrada);
            $consultas[$consulta?->chave() ?? 'invalida'] ??= $consulta;
            if (\count($consultas) >= self::MAXIMO_POR_RESPOSTA) {
                break;
            }
        }

        return array_values($consultas);
    }

    private function bloco(RespostaPreparada $preparada, ?ConsultaDePrazosInput $consulta): string
    {
        if ($consulta === null) {
            $this->registrar($preparada, null, null);

            return self::naoRealizada(self::ENTRADA_INVALIDA);
        }
        try {
            $resultado = $this->consulta->executar($preparada->conversa->getUsuario(), $preparada->tenant, $consulta);
        } catch (SemAcessoAsMetasNoChatException $e) {
            $this->registrar($preparada, $consulta, null);

            return self::naoRealizada($e->getMessage());
        }
        $this->registrar($preparada, $consulta, $resultado);

        return $this->formatar($preparada->tenant, $resultado);
    }

    private function formatar(Tenant $tenant, PrazosEMetasOutput $resultado): string
    {
        $linhas = [sprintf('• No período: %d meta(s); mostrando %d, pelo prazo (o mais antigo primeiro).', $resultado->total, \count($resultado->metas))];
        if ($resultado->omitidasPorSegredo > 0) {
            $linhas[] = sprintf('• %d meta(s) do período ficaram de fora por serem de processo em segredo de justiça; a pessoa as vê na tela Minhas Metas.', $resultado->omitidasPorSegredo);
        }
        foreach ($resultado->metas as $meta) {
            $linhas[] = $this->linha($meta, $resultado->papel);
        }
        if ($resultado->metas === []) {
            $linhas[] = '• Nenhuma meta neste período.';
        }

        return sprintf(
            "PRAZOS E METAS DA PESSOA (consulta feita agora pelo sistema, na tela Minhas Metas dela; hoje é %s; período: %s; papel: %s):\n<prazos_e_metas>\n%s\n</prazos_e_metas>",
            $resultado->hoje->format('d/m/Y'),
            $resultado->periodo->rotulo(),
            mb_strtolower($resultado->papel->rotulo()),
            // Uma máscara para o bloco inteiro (uma leitura da preferência do escritório); cada valor já foi neutralizado.
            $this->protecao->comoDado($tenant, implode("\n", $linhas)),
        );
    }

    private function linha(MetaDoPrazoOutput $meta, AbaMetas $papel): string
    {
        $partes = [
            ($meta->numero === null ? 'Meta' : sprintf('Meta nº %04d', $meta->numero)) . ' ' . self::valor($meta->titulo, 'sem título'),
            'pasta ' . self::valor($meta->pasta, 'sem número'),
            'cliente ' . self::valor($meta->cliente, 'não informado'),
            self::quando($meta),
            'prioridade ' . $meta->prioridade, // valor do enum, nasce no código
        ];
        if ($meta->quem !== []) {
            $nomes = implode(', ', array_map(static fn (string $nome): string => self::valor($nome, 'sem nome'), $meta->quem));
            $partes[] = ($papel === AbaMetas::RESPONSAVEL ? 'delegada por ' : 'responsável ') . $nomes;
        }

        return '• ' . implode(' · ', $partes);
    }

    private static function quando(MetaDoPrazoOutput $meta): string
    {
        $data = $meta->prazo->format('d/m/Y');

        return match (true) {
            $meta->dias < 0 => sprintf('atrasada há %d dia(s) (prazo %s)', -$meta->dias, $data),
            $meta->dias === 0 => sprintf('vence hoje (%s)', $data),
            default => sprintf('vence em %d dia(s) (%s)', $meta->dias, $data),
        };
    }

    /** Valor que veio do banco: vira texto inerte de uma linha (a tag delimitadora não fecha o bloco). */
    private static function valor(string $texto, string $padrao): string
    {
        $valor = NeutralizadorDeConteudo::neutralizar($texto);

        return $valor === '' ? $padrao : $valor;
    }

    private static function naoRealizada(string $motivo): string
    {
        return "PRAZOS E METAS DA PESSOA:\n<prazos_e_metas>\n• Consulta não realizada: " . $motivo . ".\n</prazos_e_metas>";
    }

    private function registrar(RespostaPreparada $preparada, ?ConsultaDePrazosInput $consulta, ?PrazosEMetasOutput $resultado): void
    {
        $this->logger->info('Chat I.A: consulta de prazos e metas.', [
            'tenantId' => $preparada->tenant->getId(),
            'conversaId' => $preparada->conversa->getId(),
            'periodo' => $consulta?->periodo->value,
            'papel' => $consulta?->papel->value,
            'recusada' => $resultado === null,
            'total' => $resultado?->total,
            'mostradas' => $resultado === null ? null : \count($resultado->metas),
            'omitidasPorSegredo' => $resultado?->omitidasPorSegredo,
        ]);
    }
}
