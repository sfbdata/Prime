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
 * perfil de sistema, 6.3): a ferramenta aparece se a pessoa tem o módulo de alguma ação dela na
 * {@see TabelaDeAbaEChave}; a que tem ação sem módulo exigido aparece sempre, e a que não está na tabela, nunca. Cada
 * ferramenta continua conferindo a própria permissão ao ser chamada (a {@see \App\Conector\Service\GuardaDaAcaoDoConector},
 * com aba e chave); esta lista só informa. `liberada_nesta_conexao` diz se o escopo do token basta (gravar exige
 * `mcp:escrita`, 5.1).
 *
 * Nomes saem neutralizados ({@see ProtecaoDoTextoDoChat::rotulo()}, 6.2, regra 6).
 */
final class QuemSouEuFerramenta implements FerramentaDoConector
{
    public const NOME = 'quem_sou_eu';

    /** @param iterable<FerramentaDoConector> $ferramentas */
    public function __construct(
        // `excludeSelf: false`: a própria `quem_sou_eu` entra na lista de ferramentas que ela mostra.
        #[AutowireIterator(FerramentaDoConector::TAG, excludeSelf: false)]
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
        foreach (TabelaDeAbaEChave::linhasDa($nome) as $linha) {
            if ($linha->modulos === []) {
                return true;
            }
            foreach ($linha->modulos as $modulo) {
                if ($this->permissoes->canAccessModule($chamada->contexto->pessoa, $chamada->contexto->escritorio, $modulo)) {
                    return true;
                }
            }
        }

        return false;
    }
}
