# Plano de implementação — Simular acordo (calculadora de atualização monetária)

> **Spec:** [`docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md`](../specs/cobranca-simular-acordo-atualizacao-monetaria.md).
> A spec manda. Se este plano divergir dela, a spec vence e o plano é corrigido.
>
> **Como usar este documento.** Ele é dividido em **8 partes sequenciais**, cada uma pensada para
> ser executada por um **chat novo**, sem carregar o contexto das anteriores. Um chat lê: a spec, a
> seção "Contratos congelados" abaixo, e **só a sua parte**. Nada mais.

**Objetivo:** dar função ao botão `Simular acordo` do `cobranca_objeto_show`, abrindo uma réplica da
calculadora pública de atualização monetária do TJDFT, pré-preenchida com a dívida do objeto,
salvável, imprimível, e que **não altera saldo nenhum**.

**Arquitetura:** domínio novo `app/src/AtualizacaoMonetaria/`, isolado do motor de encargos que já
roda em produção. Tabela global de índices alimentada da API do Banco Central. Motor de cálculo puro
(sem banco, sem HTTP), travado por casos de referência colhidos da calculadora oficial.

**Stack:** PHP 8.2, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, Dompdf, PHPUnit + Foundry.

---

## Restrições globais (valem para TODAS as partes)

- Código, comentários e commits em **português brasileiro**. `camelCase` métodos/variáveis ·
  `PascalCase` classes · `snake_case` rotas/templates/colunas.
- Tudo roda **dentro do container**: `docker exec jusprime_php_dev bash -c 'cd app && ...'`.
  Nunca `php`/`composer`/`bin/console` fora dele.
- **Dinheiro em centavos (`int`)** na fronteira (DTO, banco, template — o filtro Twig `centavos` já
  existe). Cálculo intermediário em **BCMath com escala 10**; arredondamento só no final de cada
  verba. Nunca `float` para dinheiro.
- Filtro de **tenant obrigatório** em toda query, com uma única exceção justificada na spec §7.1: a
  tabela `indice_monetario` é global.
- PHPUnit roda com `failOnDeprecation/Notice/Warning` — um deprecation derruba a suíte.
- **Push, merge, rebase e reset são do humano.** Commits locais são permitidos.
- Nenhuma parte abre o navegador **exceto a Parte 1**, e mesmo essa **só no site do TJDFT**.

## Contratos congelados

Definidos aqui uma vez para que as partes não precisem ler umas às outras.

**Enums** — `app/src/AtualizacaoMonetaria/Enum/`

| Enum | Casos |
|---|---|
| `SerieIndice` | `INPC` · `IPCA` · `TAXA_LEGAL` |
| `IndiceAtualizacao` | `OFICIAIS_TJDFT` · `INPC_TODO_PERIODO` |
| `TipoJuros` | `LEGAIS` · `PERCENTUAL_FIXO` · `SEM_JUROS` |
| `TermoInicialJuros` | `VALORES` · `CITACAO_OU_OUTRA_DATA` · `DATA_FIXA` |
| `TipoConsectario523` | `MULTA` · `HONORARIO` · `AMBAS` |

**Assinatura do motor** (Parte 3 entrega; Partes 5–7 consomem):

```php
namespace App\AtualizacaoMonetaria\Service;

final class CalculadoraAtualizacaoMonetaria
{
    public function calcular(
        EntradaCalculoInput $entrada,
        TabelaIndices $tabela,
    ): DemonstrativoOutput;
}
```

`TabelaIndices` é um objeto **puro em memória** (mapa `serie+competência → variação`), montado pelo
repositório e injetado. É o que mantém o motor sem I/O e testável offline.

**Formato do arquivo de casos de referência** (Parte 1 produz, Parte 3 consome):
`app/tests/AtualizacaoMonetaria/Fixtures/casos-referencia-tjdft.json`

```json
[
  {
    "id": "01-valor-unico-so-correcao",
    "descricao": "R$ 1.000,00 de 10/01/2020, índices oficiais, sem juros",
    "capturadoEm": "2026-07-29",
    "entrada": {
      "dataFinalCalculo": "2026-07-29",
      "indice": "OFICIAIS_TJDFT",
      "valores": [ { "valorCentavos": 100000, "data": "2020-01-10", "descricao": "Parcela 1" } ],
      "juros": { "tipo": "SEM_JUROS", "termoInicial": "VALORES", "dataInicial": null, "percentual": null, "dataFinal": null },
      "multas": [],
      "honorarios": [],
      "consectario523": null,
      "custas": []
    },
    "esperado": {
      "principalCentavos": 100000,
      "correcaoCentavos": 0,
      "jurosCentavos": 0,
      "multasCentavos": 0,
      "honorariosCentavos": 0,
      "consectario523Centavos": 0,
      "custasCentavos": 0,
      "totalCentavos": 0
    }
  }
]
```

Os campos de `esperado` são preenchidos com o que a **calculadora oficial** devolveu. O `0` acima é
só ilustração de formato.

---

## Índice das partes

| Parte | Entrega | Depende de |
|---|---|---|
| 1 | Casos de referência colhidos do TJDFT (arquivo JSON) | — |
| 2 | Tabela de índices + importador do Banco Central | — |
| 3 | Motor de cálculo, travado pelos casos de referência | 1, 2 |
| 4 | Persistência da simulação (entidade + UseCases) | 3 |
| 5 | Tela — aba "Dados do Cálculo" | 3, 4 |
| 6 | Tela — aba "Demonstrativos" + impressão PDF | 5 |
| 7 | Ligação com o objeto: botão, pré-preenchimento, aviso de divergência | 4, 5, 6 |
| 8 | Revisão contra a spec, suíte completa, entrega | 1–7 |

As Partes 1 e 2 são **independentes entre si** — podem ser feitas em qualquer ordem, ou em paralelo
por dois chats, porque não tocam nos mesmos arquivos.

---

## Parte 1 — Casos de referência do TJDFT

**Objetivo:** produzir a verdade contra a qual o motor será medido. Sem isso, "exatamente igual"
é expectativa, não garantia.

**Entrega:** `app/tests/AtualizacaoMonetaria/Fixtures/casos-referencia-tjdft.json` com **22 casos**
preenchidos, no formato dos Contratos congelados.

**Esta é a única parte que usa o navegador**, e só em `https://juriscalc.tjdft.jus.br/publico/calculos`.
Autorização pontual dada pelo dono em 29/07/2026. **Não abra o JusPrime no navegador.**

**Passos**

- [ ] **1.** Abrir a calculadora oficial e mapear a tela: nomes exatos dos campos, onde ficam, como
      se adicionam linhas de valor/multa/honorário/custas, e onde aparece cada total no demonstrativo.
- [ ] **2.** Criar o arquivo com os 22 casos **só com a `entrada` preenchida** e `esperado` zerado.
      A lista de casos está na spec §9 — os 22, na ordem. Commitar esse esqueleto.
- [ ] **3.** Para cada caso: preencher na calculadora oficial, gerar, e transcrever os totais do
      demonstrativo para `esperado`. **Transcrever, não calcular** — se um número parecer errado, ele
      ainda assim é a verdade; anote a estranheza no campo `descricao`.
- [ ] **4.** Nos casos 4, 5 e 6 (as viradas de índice e de taxa de juros), registrar também o
      **subtotal por período** se o demonstrativo mostrar, num campo extra `observacoes`. É o que vai
      permitir descobrir a fórmula quando o total não bater.
- [ ] **5.** Conferir que o caso 20 (custas) realmente veio **sem juros e sem multa** — é a regra do
      manual p. 21 e o teste que a prova.
- [ ] **6.** Commitar o arquivo completo.

**Critério de conclusão:** os 22 casos com `esperado` preenchido e `capturadoEm` datado. Nenhum
código PHP escrito nesta parte.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` e a Parte 1 de
> `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 1.

---

## Parte 2 — Tabela de índices e importador do Banco Central

**Objetivo:** ter os índices oficiais no banco, atualizados, sem depender de ninguém em tempo de
cálculo.

**Arquivos**
- Criar: `app/src/AtualizacaoMonetaria/Enum/SerieIndice.php`
- Criar: `app/src/AtualizacaoMonetaria/Entity/IndiceMonetario.php`
- Criar: `app/src/AtualizacaoMonetaria/Repository/IndiceMonetarioRepository.php`
- Criar: `app/src/AtualizacaoMonetaria/Service/TabelaIndices.php`
- Criar: `app/src/AtualizacaoMonetaria/Service/ClienteSgsBcb.php`
- Criar: `app/src/AtualizacaoMonetaria/Command/ImportarIndicesMonetariosCommand.php`
- Criar: `app/tests/AtualizacaoMonetaria/Unit/ClienteSgsBcbTest.php`
- Criar: `app/tests/AtualizacaoMonetaria/Functional/ImportarIndicesMonetariosCommandTest.php`
- Migration gerada por `make:migration`

**Contratos produzidos**

```php
final class ClienteSgsBcb
{
    /** @return array<string, string> competência 'Y-m-01' => variação percentual (string BCMath) */
    public function baixarSerie(SerieIndice $serie): array;
}

final class IndiceMonetarioRepository
{
    public function ultimaCompetenciaPublicada(SerieIndice $serie): ?\DateTimeImmutable;
    public function carregarTabela(): TabelaIndices;   // usado pela Parte 3
}
```

**Modelo** — tabela `indice_monetario`, **sem `tenant_id`** (exceção justificada na spec §7.1):
`id` uuid · `serie` varchar(20) · `competencia` date (dia 1) · `variacao_pct` numeric(12,6) ·
`fonte` varchar(40) · `importado_em` timestamptz · `UNIQUE (serie, competencia)`.

**Séries do BCB** — endpoint `https://api.bcb.gov.br/dados/serie/bcdata.sgs.{serie}/dados?formato=json`

| Série | Código |
|---|---|
| INPC | `188` |
| IPCA | `433` |
| Taxa legal (Lei 14.905/2024) | `29543` |

**Passos**

- [ ] **1.** Escrever o teste do cliente: dado o JSON bruto do BCB
      (`[{"data":"01/01/1994","valor":"41.32"}, ...]`), `baixarSerie` devolve
      `['1994-01-01' => '41.32', ...]`. Usar resposta gravada, **sem rede no teste**.
- [ ] **2.** Rodar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ClienteSgsBcbTest'`
      → deve FALHAR (classe não existe).
- [ ] **3.** Implementar `ClienteSgsBcb`. **Baixar a série inteira, sem `dataInicial`/`dataFinal`** —
      medido em 29/07/2026: a janela `01/01/1994`–`31/03/1994` na série 188 devolve **lista vazia**
      embora o dado exista. Filtrar em PHP, descartando competências anteriores a `1994-01-01`.
- [ ] **4.** Rodar o teste → PASSA. Commitar.
- [ ] **5.** Criar enum, entidade e repositório. Gerar a migration seguindo o ritual do `CLAUDE.md`:
      **antes** de gerar, rodar `doctrine:schema:update --dump-sql` e fotografar a divergência que já
      existia; tudo que já aparecia sai do arquivo gerado. Cuidado com `DROP INDEX` de índice funcional.
- [ ] **6.** Escrever o teste do command: importa as três séries, é **idempotente** (rodar duas vezes
      não duplica nem altera), e **resposta vazia da API não apaga nem zera a tabela**.
- [ ] **7.** Rodar → FALHA. Implementar `ImportarIndicesMonetariosCommand`
      (`app:importar-indices-monetarios`). Rodar → PASSA.
- [ ] **8.** Implementar `ultimaCompetenciaPublicada` e `carregarTabela` com teste próprio.
- [ ] **9.** Rodar a carga real no dev e conferir: INPC deve ter registro de `1994-01` até o último
      mês publicado (em 29/07/2026 era `2026-06`).
- [ ] **10.** Commitar.

**Critério de conclusão:** `php bin/console app:importar-indices-monetarios` popula as três séries no
dev, rodar de novo não muda nada, e os testes da pasta passam.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` (§7.1 e §8) e a Parte 2 de
> `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 2.

---

## Parte 3 — Motor de cálculo

**Objetivo:** a peça que produz o número. É onde mora todo o risco de dinheiro.

**Depende de:** Parte 1 (arquivo de casos) e Parte 2 (`TabelaIndices`).

**Arquivos**
- Criar: `app/src/AtualizacaoMonetaria/Enum/{IndiceAtualizacao,TipoJuros,TermoInicialJuros,TipoConsectario523}.php`
- Criar: `app/src/AtualizacaoMonetaria/DTO/EntradaCalculoInput.php` (+ DTOs de valor, multa, honorário, custa)
- Criar: `app/src/AtualizacaoMonetaria/DTO/DemonstrativoOutput.php`
- Criar: `app/src/AtualizacaoMonetaria/Service/CalculadoraAtualizacaoMonetaria.php`
- Criar: `app/src/AtualizacaoMonetaria/Exception/CompetenciaNaoPublicadaException.php`
- Criar: `app/tests/AtualizacaoMonetaria/Unit/CasosReferenciaTjdftTest.php`

**Passos**

- [ ] **1.** Escrever o teste **guiado por dados**: um `#[DataProvider]` que lê
      `casos-referencia-tjdft.json` e roda um caso por vez, comparando **cada verba e o total, ao
      centavo**. O provider falha explicitamente se o arquivo não existir ou tiver caso com
      `esperado` zerado — assim ninguém "passa" por falta de dado.
- [ ] **2.** Rodar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter CasosReferenciaTjdftTest'`
      → 22 falhas. **Esse é o alvo.**
- [ ] **3.** Implementar a correção monetária: fator acumulado das variações mensais entre a data do
      valor e a data final. Rodar os casos 1 e 3 → devem passar. **Se não passarem por diferença de
      centavos, o TJDFT não compõe a partir das variações mensais** — nesse caso, trocar para
      coeficiente acumulado e registrar a descoberta em comentário no código.
- [ ] **4.** Implementar os juros legais por período (0,5% até 10/01/2003 · 1% de 11/01/2003 · taxa
      legal de 30/08/2024). Rodar os casos 2, 6 e 7. **Simples ou composto sai do caso 6**, que
      atravessa a virada — não decida por suposição.
- [ ] **5.** Implementar a regra condicional: com `INPC_TODO_PERIODO`, a taxa legal **não** entra
      (spec §5.1). Rodar o caso 5.
- [ ] **6.** Implementar os três termos iniciais de juros. O caso 8 prova a regra da citação: valores
      **anteriores** à data contam dela; **posteriores**, do próprio vencimento.
- [ ] **7.** Implementar percentual fixo e sem juros → casos 10 e 11.
- [ ] **8.** Implementar multas (% e R$, várias) → casos 12, 13, 14.
- [ ] **9.** Implementar honorários: % sobre o **total do débito atualizado**, e R$ com termos
      iniciais próprios de correção e juros → casos 15 e 16.
- [ ] **10.** Implementar o art. 523 nas três formas, **um por cálculo** → casos 17, 18, 19.
- [ ] **11.** Implementar custas — e o teste do caso 20 tem de provar que elas **não recebem juros
      nem multa**.
- [ ] **12.** Implementar o gerador de parcelas mensais → caso 21; e data final em branco = hoje →
      caso 22.
- [ ] **13.** Implementar a guarda: data final além da última competência publicada lança
      `CompetenciaNaoPublicadaException` com o último mês disponível na mensagem. Teste próprio.
- [ ] **14.** Rodar os 22 → todos passam. Commitar.

**Critério de conclusão:** os 22 casos de referência passam ao centavo. Qualquer regra que tenha sido
**descoberta** (e não deduzida) fica registrada em comentário no ponto do código onde vale.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` (§5 e §9), os Contratos
> congelados e a Parte 3 de `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 3.

---

## Parte 4 — Persistência da simulação

**Objetivo:** guardar a simulação pendurada no objeto de cobrança, com o resultado **congelado**.

**Arquivos**
- Criar: `app/src/AtualizacaoMonetaria/Entity/SimulacaoAcordo.php`
- Criar: `app/src/AtualizacaoMonetaria/Repository/SimulacaoAcordoRepository.php`
- Criar: `app/src/AtualizacaoMonetaria/UseCase/{SalvarSimulacaoUseCase,ListarSimulacoesDoObjetoUseCase,ExcluirSimulacaoUseCase}.php`
- Criar: testes unitários dos UseCases + funcional de isolamento
- Migration

**Modelo** — spec §7.2. `tenant_id` **obrigatório**, `objeto_id` FK, `entrada_json` e
`resultado_json` em `jsonb`.

**Passos**

- [ ] **1.** Antes de escrever: responder por escrito as perguntas de storytelling da skill
      `criar-usecase` (quem salva, o quê, por quê, o que acontece se o objeto sumir, se o tenant não
      bater, se o JSON vier corrompido).
- [ ] **2.** Escrever o teste: salvar simulação **não altera** saldo, obrigação nem histórico do
      objeto. Asserção explícita sobre os três — é a garantia da decisão #3 da spec.
- [ ] **3.** Rodar → FALHA. Implementar entidade, repositório e `SalvarSimulacaoUseCase`. Rodar → PASSA.
- [ ] **4.** Teste funcional cross-tenant: salvar/listar/excluir simulação de objeto de **outro
      tenant** → 404, nunca 403 (não revela existência).
- [ ] **5.** Teste: reabrir simulação salva devolve o `resultado_json` **congelado**, sem recalcular.
- [ ] **6.** Gerar migration com o ritual do `CLAUDE.md`. Commitar.

**Critério de conclusão:** UseCases com teste unitário, isolamento provado por teste funcional, e a
asserção de "não mexe no saldo" verde.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` (§7.2), a skill `criar-usecase`,
> e a Parte 4 de `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 4.

---

## Parte 5 — Tela, aba "Dados do Cálculo"

**Objetivo:** o formulário, réplica da spec §5.1.

**Arquivos**
- Criar: `app/src/AtualizacaoMonetaria/Controller/SimulacaoAcordoController.php`
- Criar: `app/src/AtualizacaoMonetaria/Form/` (type principal + collection types de valor, multa,
  honorário, custa)
- Criar: `app/templates/atualizacao_monetaria/simular.html.twig` + partials `_`
- Criar: `app/assets/` — JS das listas dinâmicas (adicionar/editar/excluir linha, gerador de parcelas)
- Criar: testes funcionais do controller

**Passos**

- [ ] **1.** Rota `cobranca_objeto_simular_acordo` (`snake_case`), GET abre e POST calcula. Conferir
      com `debug:router` depois de criar — o console é autoritativo, não o grep.
- [ ] **2.** Teste funcional primeiro: GET com usuário sem `podeGerenciar` → recusado; GET de objeto
      de outro tenant → 404; POST sem token CSRF → recusado **assertando a mensagem**, não só o
      status (o projeto já teve teste de recusa que passava pelo motivo errado).
- [ ] **3.** Implementar controller + form até os testes passarem.
- [ ] **4.** Montar o template com **todos** os campos da spec §5.1, com os rótulos literais do
      original — inclusive os textos longos das duas opções de índice.
- [ ] **5.** Implementar as listas dinâmicas e o gerador de parcelas mensais iguais.
- [ ] **6.** Implementar as validações da spec §5.3, cada uma com teste: valor > 0, data inicial
      obrigatória, percentual obrigatório quando o tipo é percentual, descrição ≤ 50 caracteres,
      **sem data futura**, sem data posterior à data final.
- [ ] **7.** Recusar mais de um consectário do 523 por cálculo.
- [ ] **8.** `lint:twig templates` limpo. Commitar.

**Critério de conclusão:** o formulário aceita todos os casos de referência da Parte 1 como entrada
e produz o mesmo resultado do motor.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` (§4, §5.1, §5.3), as skills
> `criar-controller` e `criar-form`, `app/templates/CLAUDE.md`, e a Parte 5 de
> `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 5.

---

## Parte 6 — Aba "Demonstrativos" e impressão

**Objetivo:** a saída — o que vira proposta na mão do devedor.

**Arquivos**
- Criar: `app/templates/atualizacao_monetaria/_demonstrativo.html.twig`
- Criar: `app/templates/atualizacao_monetaria/demonstrativo_pdf.html.twig`
- Modificar: `SimulacaoAcordoController` (ação de impressão)
- Criar: teste funcional da impressão

**Passos**

- [ ] **1.** Demonstrativo agrupado **por verba** (principal, correção, juros, multas, honorários,
      consectário 523, custas, total), conforme spec §5.2.
- [ ] **2.** Botões `Editar cálculo` (volta à aba 1 preenchida), `Novo Cálculo` (limpa),
      `Salvar simulação`, `Imprimir`.
- [ ] **3.** PDF via Dompdf, seguindo o padrão já usado em
      [`ExportarPecaTextoUseCase.php`](../../app/src/Pasta/UseCase/ExportarPecaTextoUseCase.php) —
      atenção ao `chroot` do Dompdf, que já mordeu neste projeto.
- [ ] **4.** O impresso traz `Processo`, `Credor`, `Devedor` no cabeçalho e, no rodapé, a frase de
      divergência da spec §6.
- [ ] **5.** Teste funcional: impressão de simulação de outro tenant → 404; PDF gerado tem conteúdo.
- [ ] **6.** Commitar.

**Critério de conclusão:** demonstrativo na tela e PDF, ambos com a frase de divergência.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` (§5.2, §6) e a Parte 6 de
> `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 6.

---

## Parte 7 — Ligação com o objeto de cobrança

**Objetivo:** ligar o botão e trazer a dívida para dentro da calculadora — a parte com risco de
dinheiro.

**Arquivos**
- Modificar: `app/templates/cobranca/objeto/show.html.twig` (~linha 184 e o comentário do bloco §1.4)
- Criar: `app/src/AtualizacaoMonetaria/UseCase/PrePreencherSimulacaoUseCase.php`
- Criar: testes unitário e funcional

**Passos**

- [ ] **1.** Escrever **primeiro** o teste do pré-preenchimento, com um objeto que tenha obrigação
      **parcialmente paga** e encargos já acumulados. O teste exige que a linha gerada traga
      **apenas o principal em aberto**, com data = vencimento. Se trouxer valor com encargo embutido,
      o teste falha.

      > É a armadilha central da spec §6: encargo pré-preenchido faria a calculadora aplicar encargo
      > sobre encargo, contando o mesmo dinheiro duas vezes.

- [ ] **2.** Rodar → FALHA. Implementar `PrePreencherSimulacaoUseCase`. Rodar → PASSA.
- [ ] **3.** Pré-preencher `Processo` (pasta judicial vinculada, se houver), `Credor` (cliente) e
      `Devedor` (pessoa cobrada), todos editáveis.
- [ ] **4.** Habilitar o botão: tirar `disabled`, `is-disabled` e o `<span>` do tooltip
      "Ainda não tem função". **Atualizar o comentário do bloco §1.4**, que hoje afirma que
      `Simular acordo` não existe no sistema.
- [ ] **5.** Colocar a frase de divergência no topo da simulação (spec §6).
- [ ] **6.** Teste funcional: objeto sem obrigação em aberto → abre a calculadora vazia, sem erro.
- [ ] **7.** Commitar.

**Critério de conclusão:** o botão abre a calculadora pré-preenchida, e o teste da obrigação
parcialmente paga prova que não há dupla contagem.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` (§4 e §6) e a Parte 7 de
> `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 7.

---

## Parte 8 — Revisão e entrega

**Objetivo:** fechar com prova, não com impressão.

**Passos**

- [ ] **1.** Suíte completa: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'`.
      Anotar o número (`N/N`).
- [ ] **2.** `doctrine:schema:validate` · `lint:twig templates` · `lint:yaml config` · `lint:container`.
- [ ] **3.** Rodar `/review` (feature-review-agent, read-only) **contra a spec inteira**, não parte
      por parte. A lição registrada neste módulo é que a revisão da frente inteira pegou bloqueantes
      de dinheiro que sete revisões por-etapa não viram.
- [ ] **4.** Corrigir o que a revisão apontar. Rodar a suíte de novo.
- [ ] **5.** Conferir se sobrou alguma migration pendente e montar o comando de deploy **para o
      humano executar** (`# Execute manualmente no terminal externo`).
- [ ] **6.** Listar para o dono **o que precisa ser olhado na tela** — o smoke é dele. No mínimo: uma
      simulação completa com valores reais, a impressão do PDF, e a conferência de um total contra a
      calculadora oficial.

**Critério de conclusão:** suíte verde, revisão sem bloqueante, lista de smoke entregue ao dono.

**Como abrir o chat:**
> Leia `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md` inteira e a Parte 8 de
> `docs/gestao-cobrancas/PLANO_SIMULAR_ACORDO.md`. Execute a Parte 8.

---

## Registro de execução

Cada chat atualiza esta tabela ao terminar sua parte. É o que permite ao próximo saber onde pegar.

| Parte | Status | Commit | Suíte | Notas |
|---|---|---|---|---|
| 1 | ⬜ não iniciada | — | — | |
| 2 | ⬜ não iniciada | — | — | |
| 3 | ⬜ não iniciada | — | — | |
| 4 | ⬜ não iniciada | — | — | |
| 5 | ⬜ não iniciada | — | — | |
| 6 | ⬜ não iniciada | — | — | |
| 7 | ⬜ não iniciada | — | — | |
| 8 | ⬜ não iniciada | — | — | |
