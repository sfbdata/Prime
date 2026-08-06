<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;

/**
 * Acha, entre as pessoas JÁ vinculadas a um objeto, a que tem o mesmo nome do sacado que vem do
 * relatório — para o importe reusá-la em vez de criar uma segunda pessoa igual.
 *
 * Existe porque o relatório de **Dados cadastrais** cria a unidade com a pessoa vinculada (nome, CPF,
 * e-mail, telefone) e **sem caso de cobrança**. Os importes de inadimplência e de receita, ao abrir o
 * caso, criavam outra pessoa com o mesmo nome e sem documento, e o caso passava a cobrar essa cópia.
 * Medido na AMLI BR 060 antes da correção: 9 unidades pela inadimplência, 45 pela receita, de 51.
 * Spec: `docs/specs/cobranca-importe-nao-duplica-devedor-do-cadastro.md`.
 *
 * 🔑 É um serviço, e não um método privado em cada UseCase, de propósito: as duas portas de importação
 * precisam da MESMA régua. Trecho parecido copiado em dois lugares foi como nasceu a "porta B mais
 * frouxa que a porta A" que a 1ª revisão da §16 teve de corrigir.
 *
 * Escopo: **dentro do objeto**, nunca global, nunca entre carteiras, nunca entre tenants — o repositório
 * já filtra por `objeto` + `tenant` do próprio objeto. Nome igual em objetos diferentes continua sendo
 * pessoa diferente (decisão A do mapeamento TOPLIFE, provada em `ImportarRelatorioCarteiraTest`).
 */
final class ResolvedorPessoaNoObjeto
{
    public function __construct(
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
    ) {
    }

    /**
     * A pessoa já vinculada ao objeto cujo nome normalizado é igual ao do sacado; null se não houver.
     *
     * Sacado sem nome utilizável devolve null (nunca casa "por vazio", o que uniria pessoas sem relação).
     */
    public function porNome(ObjetoCobranca $objeto, ?string $sacadoNome): ?Pessoa
    {
        $alvo = NormalizadorNome::normalizar($sacadoNome);

        if ($alvo === null) {
            return null;
        }

        foreach ($this->vinculoRepository->pessoasVinculadasAoObjeto($objeto) as $pessoa) {
            if (NormalizadorNome::normalizar($pessoa->getNome()) === $alvo) {
                return $pessoa;
            }
        }

        return null;
    }
}
