<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Service\PermissionChecker;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * `quem_sou_eu` (`docs/specs/mcp-remoto-oauth.md`, 6.4): quem está conectado, em qual escritório, com quais escopos,
 * até quando a conexão vale e quais ferramentas registradas a pessoa pode usar. Tudo vem da {@see ChamadaDoConector}
 * aberta pelo portão: só o escritório do token aparece, nunca outro da mesma pessoa (12.1).
 *
 * "Pode usar" é pelo {@see PermissionChecker}, com o modo do conector ligado pelo portão (sem os passes do Master e do
 * perfil de sistema, 6.3): a ferramenta aparece se a pessoa tem o módulo de {@see MODULO_DA_FERRAMENTA}; a que não
 * depende de módulo aparece sempre. Cada ferramenta continua conferindo a própria permissão ao ser chamada; esta lista
 * só informa. `liberada_nesta_conexao` diz se o escopo do token basta (gravar exige `mcp:escrita`, 5.1).
 *
 * Nomes saem neutralizados ({@see ProtecaoDoTextoDoChat::rotulo()}, 6.2, regra 6).
 */
final class QuemSouEuFerramenta implements FerramentaDoConector
{
    public const NOME = 'quem_sou_eu';

    /**
     * O módulo (código `modules.<x>.view`) que abre cada ferramenta, pela linha "Permissão" de cada uma na 6.4 e na 6.6;
     * null quando nenhum módulo é exigido. Ferramenta fora deste mapa não aparece (o teste exige o catálogo inteiro aqui).
     */
    public const MODULO_DA_FERRAMENTA = [
        'quem_sou_eu' => null,
        'buscar_pastas' => 'expediente',
        'ler_pasta' => 'pastas',
        'ler_documentos_e_modelos' => 'pastas',
        'ler_processos' => 'processos',
        'ler_clientes' => 'clientes',
        'ler_agenda' => 'agenda',
        'atualizar_pasta' => 'pastas',
        'mudar_andamento' => 'pastas',
        'registrar_na_pasta' => 'pastas',
        'prazos_e_metas_da_pasta' => 'pastas',
        'documentos_e_pecas' => 'pastas',
        'financeiro_da_pasta' => 'pastas',
        'atualizar_cliente' => 'clientes',
        'processos_da_pasta' => 'pastas',
        'gravar_na_agenda' => 'agenda',
        'confirmar_acao' => null,
    ];

    /** @param iterable<FerramentaDoConector> $ferramentas */
    public function __construct(
        #[AutowireIterator(FerramentaDoConector::TAG)]
        private readonly iterable $ferramentas,
        private readonly PermissionChecker $permissoes,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Quem sou eu no BlueJus';
    }

    public function descricao(): string
    {
        return 'Mostra quem está conectado ao BlueJus, o escritório desta conexão, as permissões dela (leitura ou também gravação), até quando ela vale e quais ferramentas a pessoa pode usar. Use antes de começar, para saber o que dá para fazer.';
    }

    public function esquemaDeEntrada(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pessoa = $chamada->contexto->pessoa;
        $escritorio = $chamada->contexto->escritorio;

        return new RespostaDaFerramenta(
            [
                'pessoa' => ['id' => $pessoa->getId(), 'nome' => $this->protecao->rotulo($escritorio, (string) $pessoa->getFullName(), 'Sem nome')],
                'escritorio' => ['id' => $escritorio->getId(), 'nome' => $this->protecao->rotulo($escritorio, (string) $escritorio->getName(), 'Sem nome')],
                'escopos' => $chamada->escopos,
                'conexao_vale_ate' => $chamada->conexaoValeAte->format(\DateTimeInterface::ATOM),
                'ferramentas' => $this->ferramentasDa($chamada),
            ],
            ids: ['escritorio' => [(int) $escritorio->getId()]],
        );
    }

    /** @return list<array{nome: string, titulo: string, liberada_nesta_conexao: bool}> */
    private function ferramentasDa(ChamadaDoConector $chamada): array
    {
        $lista = [];
        foreach ($this->ferramentas as $ferramenta) {
            $nome = $ferramenta->nome();
            if (!CatalogoFechadoDoConector::existe($nome) || !$this->temOModulo($chamada, $nome)) {
                continue;
            }
            $lista[] = [
                'nome' => $nome,
                'titulo' => CatalogoFechadoDoConector::TITULOS[$nome],
                'liberada_nesta_conexao' => $chamada->temEscopo(CatalogoFechadoDoConector::tipo($nome)->escopo()),
            ];
        }

        return $lista;
    }

    private function temOModulo(ChamadaDoConector $chamada, string $nome): bool
    {
        if (!\array_key_exists($nome, self::MODULO_DA_FERRAMENTA)) {
            return false;
        }
        $modulo = self::MODULO_DA_FERRAMENTA[$nome];

        return $modulo === null
            || $this->permissoes->canAccessModule($chamada->contexto->pessoa, $chamada->contexto->escritorio, $modulo);
    }
}
