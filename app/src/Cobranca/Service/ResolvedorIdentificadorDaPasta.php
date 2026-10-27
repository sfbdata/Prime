<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Pasta\Entity\Pasta;

/**
 * O IDENTIFICADOR exibido de uma Pasta (spec `pasta-prefixo-do-credor-derivado.md`).
 *
 * Duas naturezas, uma resposta:
 *
 * - pasta **judicializada pela cobrança** → identificador DERIVADO na hora,
 *   o cliente da carteira (fantasia, senão razão social ou nome completo). Não é gravado em lugar nenhum: mudar o cadastro
 *   do cliente da carteira muda o nome de todas as pastas afetadas, de uma vez;
 * - **qualquer outra pasta** com cliente vinculado → o cliente PRINCIPAL, pelo nome de exibição
 *   (decisão do dono, 2026-10-27); o `nome_cliente` gravado, texto livre, vira reserva e só aparece
 *   quando a pasta não tem cliente.
 *
 * ⚠️ O derivado VENCE o texto gravado — não se concatena com ele. É isso que impede prefixo em dobro
 * e o que faz as pastas cujo texto ficou quebrado (`APLC TOP LIFE 1 -`) voltarem a exibir o nome
 * certo sem nenhuma correção de dados.
 *
 * **Memória por requisição, primada em lote.** `primeParaPastas()` resolve a página inteira numa
 * consulta; `para()` lê da memória. Se ninguém primar, `para()` ainda devolve o valor CERTO, com uma
 * consulta avulsa — a degradação é de velocidade, nunca de correção. Foi uma escolha deliberada:
 * passar o mapa por parâmetro obrigaria toda tela a lembrar de fazê-lo, e a que esquecesse ficaria
 * sem o identificador em silêncio.
 */
final class ResolvedorIdentificadorDaPasta
{
    /** @var array<int, ?string> id da pasta → derivado (null = não é pasta de cobrança) */
    private array $memoria = [];

    public function __construct(
        private readonly CasoCobrancaRepository $casos,
        private readonly ComporNomeDaPastaJudicial $compositor,
    ) {
    }

    /** @param iterable<Pasta> $pastas */
    public function primeParaPastas(iterable $pastas): void
    {
        $porTenant = [];
        foreach ($pastas as $pasta) {
            $id = $pasta->getId();
            $tenant = $pasta->getTenant();

            if ($id === null || $tenant === null || \array_key_exists($id, $this->memoria)) {
                continue;
            }

            // Agrupado por escritório porque a consulta é escopada por tenant. Na prática uma
            // listagem é de um escritório só, mas depender disso seria supor em vez de garantir.
            $porTenant[(int) $tenant->getId()] ??= ['tenant' => $tenant, 'ids' => []];
            $porTenant[(int) $tenant->getId()]['ids'][] = (int) $id;
        }

        foreach ($porTenant as $grupo) {
            $dados = $this->casos->clienteDaCarteiraPorPasta($grupo['ids'], $grupo['tenant']);

            foreach ($grupo['ids'] as $id) {
                // Pasta sem caso de cobrança fica com `null` na memória — e `null` MEMORIZADO é
                // diferente de ausente: evita reconsultar a mesma pasta a cada linha da tabela.
                $this->memoria[$id] = isset($dados[$id])
                    ? $this->compositor->compor($dados[$id]['fantasia'], $dados[$id]['razaoSocial'])
                    : null;
            }
        }
    }

    public function para(Pasta $pasta): ?string
    {
        $id = $pasta->getId();

        if ($id === null) {
            return $pasta->getNomeCliente();
        }

        if (!\array_key_exists($id, $this->memoria)) {
            $this->primeParaPastas([$pasta]);
        }

        return $this->memoria[$id]
            ?? $pasta->getClientePrincipal()?->getNomeExibicao()
            ?? $pasta->getNomeCliente();
    }

    /**
     * Só o derivado da cobrança (`null` para pasta que não veio de judicialização). É o que diz se o
     * nome da pasta é montado pela carteira e portanto não se edita à mão: o cliente principal de uma
     * pasta comum NÃO conta, porque o `nome_cliente` dela continua sendo texto livre.
     */
    public function daCobranca(Pasta $pasta): ?string
    {
        $id = $pasta->getId();

        if ($id === null) {
            return null;
        }

        if (!\array_key_exists($id, $this->memoria)) {
            $this->primeParaPastas([$pasta]);
        }

        return $this->memoria[$id] ?? null;
    }

    /**
     * Descarta a memória. Serve aos testes e a comandos de longa duração, onde o mesmo serviço
     * atravessa mudanças no banco e a memória deixaria de refletir o dado.
     */
    public function esquecer(): void
    {
        $this->memoria = [];
    }
}
