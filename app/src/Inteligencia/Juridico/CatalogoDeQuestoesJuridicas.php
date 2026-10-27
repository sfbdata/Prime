<?php

declare(strict_types=1);

namespace App\Inteligencia\Juridico;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O conteúdo das questões jurídicas do Master, lido de `config/inteligencia/questoes_juridicas.json` (uma lista de
 * objetos com `chave`, `titulo`, `problema`, `risco_grau`, `risco`, `opcoes`, `sugestao`, `fontes` e `documentos`, mais `oculta` opcional).
 * Questão com `"oculta": true` fica no arquivo (para o dono recolocá-la depois), mas o catálogo não a entrega: some da
 * listagem, da contagem de "em aberto" e do aviso global. A numeração dos títulos não é refeita.
 * O texto muda por PR, com revisão; o banco guarda só o status e o histórico de cada chave.
 *
 * Lido uma vez por processo. Arquivo ausente, JSON quebrado, campo faltando ou chave repetida lançam
 * `\UnexpectedValueException`: catálogo errado é defeito de entrega, não dado a contornar (o aviso do topo engole a
 * falha para não derrubar a página; a aba mostra o erro).
 */
final class CatalogoDeQuestoesJuridicas
{
    /** @var list<QuestaoJuridica>|null */
    private ?array $questoes = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/inteligencia/questoes_juridicas.json')]
        private readonly string $arquivo,
    ) {
    }

    /** @return list<QuestaoJuridica> */
    public function todas(): array
    {
        return $this->questoes ??= $this->ler();
    }

    public function porChave(string $chave): ?QuestaoJuridica
    {
        foreach ($this->todas() as $questao) {
            if ($questao->chave === $chave) {
                return $questao;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function chaves(): array
    {
        return array_map(static fn (QuestaoJuridica $q): string => $q->chave, $this->todas());
    }

    /** @return list<QuestaoJuridica> */
    private function ler(): array
    {
        $conteudo = is_file($this->arquivo) ? file_get_contents($this->arquivo) : false;
        if ($conteudo === false) {
            throw new \UnexpectedValueException('Catálogo de questões jurídicas não encontrado: ' . $this->arquivo);
        }
        try {
            $dados = json_decode($conteudo, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('Catálogo de questões jurídicas com JSON inválido: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($dados) || !array_is_list($dados)) {
            throw new \UnexpectedValueException('Catálogo de questões jurídicas inválido: a raiz precisa ser uma lista.');
        }

        $todas = self::semChaveRepetida(array_map(
            static fn (mixed $item): QuestaoJuridica => QuestaoJuridica::doCatalogo(is_array($item) ? $item : []),
            $dados,
        ));

        return array_values(array_filter($todas, static fn (QuestaoJuridica $q): bool => !$q->oculta));
    }

    /**
     * @param list<QuestaoJuridica> $questoes
     *
     * @return list<QuestaoJuridica>
     */
    private static function semChaveRepetida(array $questoes): array
    {
        $chaves = array_map(static fn (QuestaoJuridica $q): string => $q->chave, $questoes);
        if (count($chaves) !== count(array_unique($chaves))) {
            throw new \UnexpectedValueException('Catálogo de questões jurídicas inválido: chave repetida.');
        }

        return $questoes;
    }
}
