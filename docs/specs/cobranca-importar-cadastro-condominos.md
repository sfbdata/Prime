# Cobrança — Importar Dados Cadastrais dos Condôminos

> Risco **BAIXO**: mexe em dado de contato/qualificação, nenhum centavo. Fonte: relatório "DADOS CADASTRAIS
> DOS CONDÔMINOS" da contábil L.G (mesma origem do TOPLIFE). Decisões do dono registradas em §5.

## 1. Objetivo

O relatório de inadimplência traz o nome do sacado, mas nada de CPF, telefone, e-mail ou endereço. Hoje
esse dado entra à mão, unidade por unidade. Esta entrega lê o relatório de cadastro e preenche
`Pessoa` + `VinculoPessoaObjeto` + as listas de qualificação, **sem apagar nada** do que já existe.

Não é um importador universal (§24 da SPEC de cobranças): é um adapter da fonte L.G, irmão do
`TopLifeInadimplenciaAdapter`.

## 2. Layout medido da fonte (2026-07-29)

Uma aba (`Relatório`). L1–L2 institucional; L4 título; L6 "Total de unidades filtradas: 229";
**L8 cabeçalho**; **L9+ dados**; rodapé com endereço da contábil e "Emissão:".

| Col | Cabeçalho | Observação medida |
|---|---|---|
| A | Unidade | chave de vínculo; **casou 100%** com a inadimplência (119/119) |
| B | Nome/Nome fantasia | |
| C | Sacado | papel; valor observado: `Proprietário` |
| D | CPF/CNPJ | formatado (`250.730.858-33`); pode vir vazio |
| E | Fração ideal | `-` em 100% do dado real → **ignorada** |
| F | Endereço | linha única, formato `Área Rural - S/N - <unidade> - <bairro>` |
| G | E-mail | pode vir vazio |
| H | Telefone | **pode conter vários**, separados por quebra de linha; contém lixo literal `null () null` |

Volumes medidos: **245 linhas de dado** = 229 `Proprietário` + 13 `Pessoa relacionada` + 3 de rodapé.
Das 229 unidades, **119 têm dívida** e **110 são adimplentes**.

**Endereço (coluna F)** vem numa string só, separada por ` - `, e tem **7 segmentos** (229 linhas) ou
**6** (as 13 de `Pessoa relacionada`, sem a referência da unidade):

```
Área Rural - S/N - CHACARA 01/02 - LAGO CORUMBA IV - Santo Antônio do Descoberto - GO - CEP: 72908-899
logradouro  nº    complemento     bairro             cidade                        uf   cep
```

Parsear **de trás para frente** (CEP, UF, cidade, bairro fixos no fim; logradouro e número no começo; o
que sobrar no meio é complemento) — contar segmentos da esquerda quebra nas 13 linhas curtas.

**Telefones (coluna H)** por célula: 19 vazias · 119 com um · 106 com dois · 1 com três.
**Documentos:** 234 CPF · 8 CNPJ · 3 vazios (que são o rodapé).

## 3. Chave de vínculo

`Unidade` → `ObjetoCobranca.identificacao`, aplicando a **mesma** regra do adapter de inadimplência
(`separarUnidade`: `NOME (associada)` → `NOME`). Isso garante que as duas fontes convirjam para o mesmo
objeto — foi medido, não presumido.

Objeto inexistente é **criado** (§5.1).

## 4. Mapeamento

Nenhuma regra de negócio nova. Reaproveita os UseCases existentes:

| Planilha | Destino | UseCase |
|---|---|---|
| Unidade | `ObjetoCobranca` | dedup por identificação; cria se ausente |
| Nome + CPF/CNPJ | `Pessoa` | `CriarPessoaVinculadaAoObjetoUseCase` |
| Sacado (papel) | `VinculoPessoaObjeto` | `TipoVinculo::Proprietario` / `Coproprietario` |
| Endereço | `PessoaEndereco` | `AdicionarEnderecoPessoaUseCase` |
| E-mail | `PessoaEmail` | `AdicionarEmailPessoaUseCase` |
| Telefone | `PessoaTelefone` | `AdicionarTelefonePessoaUseCase` |

## 5. Decisões

### 5.1. Todas as 229 unidades (decisão do dono, 2026-07-29, ratificada em 2026-07-30)
Objetos são criados também para as **110 unidades adimplentes**. Dois motivos, ambos do dono:

1. **Elas podem ficar inadimplentes depois** — o cadastro (CPF, telefone, e-mail) já estará pronto no
   dia em que a cobrança começar, em vez de virar corrida atrás de dado.
2. **A carteira passa a saber quantas unidades ela tem, no total.** Hoje o sistema só enxerga quem
   deve, então não há como responder "quantas unidades existem" nem calcular taxa de inadimplência
   (119 de 229 = 52%). Objeto sem dívida não é ruído: é o denominador.

Custo aceito: a carteira lista 229 objetos, 110 com saldo zero.

**Consequência a verificar na revisão:** telas e totais da carteira precisam continuar corretos com
objetos de saldo zero. Um objeto sem obrigação não pode quebrar `MontarVisaoCarteiraUseCase` nem inflar
contadores de "objetos em cobrança".

### 5.2. Nunca sobrescrever, sempre acrescentar
O modelo de qualificação já é aditivo ("nada é apagado ao atualizar, só adicionado" — docblock de
`Pessoa`). Telefone/e-mail/endereço novo entra na lista e vira o **atual**; o anterior permanece no
histórico. É o que torna a reimportação mensal segura.

### 5.3. Lixo é rejeitado, não gravado
`null () null` e variações não viram telefone. A linha não é perdida: entra em `LinhaRejeitada` com
motivo e aparece no resumo.

### 5.4. Telefone múltiplo
Célula com quebra de linha vira **N** `PessoaTelefone`; o primeiro é marcado atual.

### 5.5. O papel vem da planilha — e "Pessoa relacionada" NÃO é co-proprietário

**Correção da versão de 29/07**, que mandava tratar a segunda pessoa da unidade como `Coproprietario`.
A coluna C **declara o papel**, e a medição do dado real mostra só dois valores:

| Coluna "Sacado" | Ocorrências | `TipoVinculo` |
|---|---|---|
| `Proprietário` | 229 | `Proprietario` |
| `Pessoa relacionada` | 13 | `Outro` |

"Pessoa relacionada" **não afirma co-propriedade** — afirma que existe algum vínculo. Mapear para
`Coproprietario` gravaria no banco uma informação que a fonte não dá, indistinguível de fato apurado.
`Outro` é o que o documento sustenta. (Mesmo princípio da `Version20260728160000`, que recusou inferir o
tipo do telefone por contagem de dígitos.)

Papel desconhecido → linha **rejeitada** com motivo, nunca adivinhada.

Ambas com vínculo **aberto** (`dataFim` nula). **Nenhuma vira "pessoa cobrada" automaticamente** — isso
permanece decisão humana na tela (`AlterarPessoaCobradaUseCase`).

### 5.7. Rodapé
As 3 últimas linhas (`Filtros:`, endereço da contábil, `Emissão:`) ocupam a coluna A e não têm papel.
São **ignoradas** (contam em `linhasIgnoradas`, não em rejeitadas — não são dados malformados, não são
dados). Critério: linha sem papel reconhecido.

### 5.6. Dedup de pessoa — dois níveis

1. **Por documento**, no tenant: `(tenant + CPF)` ou `(tenant + CNPJ)`, via
   `PessoaRepository::buscarPossiveisDuplicadas`, que compara por dígitos e já tinha sido escrito
   prevendo este importador.
2. **Sem documento: por NOME dentro do OBJETO**, entre quem já tem vínculo aberto naquela unidade — a
   mesma "decisão A" do importador de inadimplência. Só casa com pessoa que **também** não tem
   documento: se a cadastrada tem CPF e a do relatório não, são pessoas diferentes até prova em
   contrário.

**Correção da versão de 29/07**, que dizia "sem documento, não casa por nome: cria nova". Estava errado
e o teste `testReimportacaoEhIdempotente` provou: a pessoa sem CPF (3 no dado real) nasceria **de novo a
cada rodada mensal**, empilhando duplicatas — o oposto do que a regra queria evitar.

O que continua proibido é casar por nome **no tenant inteiro**: aí sim fundiria homônimos, falha já
corrigida neste domínio em `PessoaRepository::opcoesDoTenant`. O escopo do objeto é o que torna o
casamento seguro — dois "José da Silva" em unidades diferentes seguem sendo duas pessoas.

## 6. Entrega

Comando CLI `app:cobranca:importar-cadastro`, espelhando o contrato de
`ImportarRelatorioCobrancaCommand`:

```
--tenant-id  --carteira-id  --usuario-id  --arquivo   (dry-run)
--confirmar                                            (persiste)
```

Dry-run é o padrão e imprime: unidades lidas, objetos a criar, pessoas a criar, pessoas a atualizar,
vínculos a abrir, telefones/e-mails/endereços a acrescentar, e as linhas rejeitadas com motivo.

Sem tela nova nesta entrega.

## 7. Idempotência

Reimportar o mesmo arquivo não pode duplicar pessoa, vínculo, telefone, e-mail nem endereço. Contato
idêntico ao atual é **no-op** (não vira item novo na lista).

## 8. Fora de escopo

- Fração ideal (`-` em 100% do dado).
- Tela de upload — CLI só.
- Encerrar vínculo de quem **saiu** da planilha. Ausência não é prova de saída; encerramento continua
  manual, via `EncerrarVinculoUseCase` (invariável 11: histórico nunca some).

## 9. Testes

- **Unit do adapter:** telefone múltiplo; `null () null` rejeitado; CPF ausente; CNPJ; e-mail vazio;
  co-proprietário; rodapé ignorado.
- **Unit/functional do UseCase:** cria objeto ausente; reimportação não duplica (idempotência);
  contato novo entra e vira atual sem apagar o anterior; sem documento cria pessoa nova.
- **Multi-tenant:** nunca cruza tenant nem carteira; unidade homônima em duas carteiras não funde.
- Suíte de Cobrança verde + global verde (`-d memory_limit=512M`).
