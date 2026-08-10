# MCP de investigação em produção — v1 (somente leitura)

**Data:** 2026-08-10
**Risco:** BAIXO no código do sistema (nada de identidade, ponto ou permissão muda);
MÉDIO na operação (abre um caminho novo de leitura para a base de produção).
**Status:** desenho aprovado pelo dono, aguardando plano de implementação.

## 1. Objetivo

Dar ao dono uma porta estruturada e comprovadamente incapaz de escrever para **investigar
dados de produção** a partir do Claude Code na máquina local.

Hoje a investigação em prod é feita à mão, com `docker exec jusprime_db_prod psql`. Isso
funciona, mas devolve texto de terminal em vez de dado estruturado, e a conexão usada tem
permissão de escrita — nada além da disciplina do operador impede um `UPDATE` acidental
contra a produção.

## 2. Escopo da v1

**Entra:**

- Um comando Symfony `mcp:server` que fala o protocolo MCP por STDIO.
- Duas ferramentas: `consultar_sql` e `descrever_esquema`.
- Uma conexão de banco separada, com usuário PostgreSQL restrito a `SELECT`.
- Log das consultas executadas.

**Não entra (decidido, não esquecido):**

- Qualquer operação de escrita — criar pasta, cadastrar cliente, gravar documento.
- Atalhos de domínio (`buscar_cliente`, `detalhar_pasta`, `situacao_devedor`). Foram
  desenhados e cortados: eles são os únicos que precisariam de `--escritorio`, `--usuario`,
  `TenantContext` e `PermissionChecker`. Sem eles, some metade da complexidade da v1.
- Autenticação por token, OAuth, API HTTP, uso por outros escritórios.
- Leitura de documento de identificação e extração de dados.

Esses itens fazem parte da visão maior (seção 10), não desta spec.

## 3. Arquitetura

O servidor MCP **é um comando do próprio JusPrime**, não um serviço separado.

```
Claude Code (máquina local)
      │  lança como processo de servidor MCP (transporte STDIO)
      ▼
ssh bluejus 'docker exec -i jusprime_php_prod php bin/console mcp:server'
      │
      ▼
Symfony (container de prod)  ──►  conexão DBAL "leitura"  ──►  PostgreSQL prod
```

**O SSH é apenas o cano.** Uma conexão para a sessão inteira, não uma por chamada de
ferramenta. Não há servidor em Node, segundo repositório, porta nova exposta na internet
nem endpoint HTTP.

### Por que dentro do Symfony

- Reusa a configuração de banco, o autoload e o container que já existem.
- O mesmo comando roda contra o dev (`jusprime_php_dev`), o que permite construir e testar
  tudo localmente; só o apontamento final para prod depende do dono.
- Evita manter um segundo projeto em outra linguagem.

### Dependência nova

`mcp/sdk` (Packagist), SDK oficial mantido em conjunto pelo time do Symfony e pela PHP
Foundation. Requer `php: ^8.1` — o projeto está em `>=8.2`, compatível. Versão corrente
0.7.0, **declarada experimental pelos mantenedores até a 1.0**. Ferramentas são declaradas
por atributo `#[McpTool]`; transporte STDIO é nativo.

Como produção roda imagem *baked*, a dependência só existe lá depois de
`./scripts/deploy-prod-tls.sh` na VPS.

## 4. Ferramentas

### 4.1 `consultar_sql(sql: string)`

Executa uma consulta e devolve as linhas em JSON.

**Retorno:** `{ colunas: [...], linhas: [...], total: int, truncado: bool }`

**Comportamento:**

- Roda pela conexão `leitura` (seção 5), nunca pela conexão padrão do app.
- Sessão configurada com `SET default_transaction_read_only = on` e
  `SET statement_timeout = '15s'`.
- Teto de 500 linhas. A leitura é feita **linha a linha, parando na 501ª** — não se carrega
  o resultado inteiro na memória para depois cortar, senão uma consulta de 40 mil linhas
  derruba o processo antes de o teto valer para alguma coisa. Ao atingir o teto, devolve as
  500 primeiras com `truncado: true` — nunca um pedaço silencioso.
- Toda consulta executada é registrada em log (seção 6).

**Explicitamente fora de escopo desta ferramenta:** filtro de tenant, `PermissionChecker`,
UseCase. Ela enxerga o banco inteiro, de todos os escritórios. É a ferramenta de
investigação do dono, e é assim que o `psql` de hoje já se comporta. Isso está registrado
como decisão consciente, não como omissão.

### 4.2 `descrever_esquema(tabela?: string)`

Sem argumento, lista as tabelas. Com o nome de uma tabela, devolve colunas, tipos,
nulidade, chaves e índices.

Não é conveniência: é o que impede o modelo de **inventar nome de coluna** ao escrever SQL
— erro já registrado em frentes anteriores deste projeto, onde uma medição saiu errada por
coluna inexistente. Sem essa ferramenta, `consultar_sql` opera por chute.

## 5. A tranca: usuário PostgreSQL somente leitura

A garantia de que este MCP não escreve **não pode** ser análise do texto do SQL — isso se
burla com comentário, encadeamento de comandos ou CTE com `RETURNING`.

A garantia é um usuário de banco separado, criado uma única vez na VPS:

- Role `jusprime_leitura`, com `LOGIN`, `CONNECT` no banco e `USAGE` no schema.
- `SELECT` nas tabelas existentes, mais `ALTER DEFAULT PRIVILEGES` para que tabelas futuras
  também nasçam legíveis por ele.
- Nenhum `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE` ou DDL.

No Symfony, uma segunda conexão DBAL nomeada `leitura`, com DSN próprio vindo de variável
de ambiente (`DATABASE_URL_LEITURA`), configurada em `.env.prod` na VPS.

Assim, uma tentativa de escrita não é "proibida por convenção": o PostgreSQL recusa.

## 6. Log de consultas

Cada chamada de `consultar_sql` registra: horário, SQL executado, número de linhas
devolvidas e duração. Vai pelo Monolog, em canal próprio `mcp`, escrevendo em
`var/log/mcp-prod.log` dentro do container — arquivo separado para não se perder no log da
aplicação. **O log vai para arquivo, nunca para `stdout`** (ver seção 7).

Serve para duas coisas: reconstruir o que foi consultado numa investigação, e perceber se
alguma consulta está pesando na produção.

## 7. Erros e o transporte

O transporte STDIO é frágil a lixo no stdout: qualquer `echo`, aviso do PHP ou deprecation
que caia lá corrompe o JSON-RPC, e o cliente mostra apenas "servidor falhou", sem pista.

**Regras:**

- Todo log e toda mensagem de diagnóstico vão para **stderr**. `stdout` carrega
  exclusivamente o protocolo.
- Erro de ferramenta (SQL inválido, timeout, tabela inexistente) vira **resposta MCP de
  erro** com mensagem em português — nunca exceção que derruba o processo. Derrubar o
  processo custa a sessão inteira por causa de um erro de digitação.

## 8. Testes

1. **A conexão de leitura recusa escrita.** Provado contra um banco de verdade, não contra
   mock: `INSERT`/`UPDATE`/`DELETE` pela conexão `leitura` devem falhar. Este é o teste que
   sustenta a spec inteira; será provado reintroduzindo o defeito (apontar a ferramenta
   para a conexão padrão e confirmar que o teste fica vermelho).
2. **Truncamento é anunciado.** Consulta que excede 500 linhas devolve `truncado: true` e
   exatamente 500 linhas.
3. **Timeout devolve erro de ferramenta**, e o processo continua vivo e respondendo.
4. **`stdout` limpo.** O comando não emite nenhum byte fora do protocolo — teste que roda o
   comando e verifica que a saída é JSON-RPC válido do primeiro byte ao último.
5. **`descrever_esquema` bate com o banco:** as colunas devolvidas para uma tabela conhecida
   conferem com o mapeamento Doctrine.

## 9. Passos que dependem do dono

1. **Criar a role no PostgreSQL de prod** — comando SQL entregue pronto, executado por ele
   na VPS.
2. **Definir `DATABASE_URL_LEITURA` no `.env.prod`** da VPS.
3. **Criar o alias SSH** `bluejus` em `~/.ssh/config` na máquina local.
4. **Deploy** via `./scripts/deploy-prod-tls.sh` — sem ele, `mcp/sdk` não existe em prod.
5. **Registrar o servidor** na configuração MCP do Claude Code.

## 10. O que fica para depois

A visão original do dono era maior: buscar clientes, processos, pastas e cobranças e,
depois, criar pastas e cadastrar clientes a partir de um documento de identificação. Isso
foi decomposto e adiado por decisão consciente:

| Etapa | Depende de |
|---|---|
| Atalhos de domínio com tenant e permissão | Retomar `--escritorio`/`--usuario` e `TenantContext` fora da sessão HTTP |
| Criar pasta | `CriarPastaUseCase` já existe; falta a camada de confirmação e auditoria |
| Cadastrar cliente + documento | Extrair um `CriarClienteUseCase` do `ClienteController` legado (557 linhas) e resolver os campos obrigatórios que um RG não traz (`email`, `cep`, `endereco`) |
| Uso por outros escritórios | Autenticação remota (token ou OAuth 2.1) e API HTTP |

## 11. Riscos conhecidos

- **`mcp/sdk` é experimental (0.7.0).** Mudança de API entre versões menores é plausível;
  a versão deve ser fixada no `composer.json`, não deixada em `^0.7`.
- **O `TenantFilter` do sistema falha aberto.** Sem o parâmetro `tenant`, ele não restringe
  nada (comportamento intencional, do qual as importações da contábil dependem). A v1 não
  toca nele porque nenhuma ferramenta usa filtro de tenant. **Quando os atalhos de domínio
  entrarem, esse será o primeiro ponto a tratar** — uma rota nova que esqueça de amarrar o
  tenant devolve dados de todos os escritórios sem erro nenhum.
- **Dados pessoais reais entram no contexto do modelo.** As consultas trazem nome, CPF,
  endereço e dívida de clientes reais da produção para dentro da conversa. É legítimo e é
  decisão do dono, registrada aqui para que seja consciente.
- **O teste final contra produção é do dono.** O SSH do agente para a VPS foi bloqueado por
  classificador em sessão anterior; a construção e os testes automatizados rodam contra o
  dev.